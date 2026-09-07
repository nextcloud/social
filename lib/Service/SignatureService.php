<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use Exception;
use JsonLdException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\LinkedDataSignatureMissingException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use stdClass;

class SignatureService {
	use TArrayTools;

	public const ORIGIN_HEADER = 1;
	public const ORIGIN_SIGNATURE = 2;
	public const ORIGIN_REQUEST = 3;

	public const DATE_HEADER = 'D, d M Y H:i:s T';
	public const DATE_OBJECT = 'Y-m-d\TH:i:s\Z';

	public const DATE_DELAY = 300;

	private CacheActorService $cacheActorService;
	private ActorsRequest $actorsRequest;
	private CurlService $curlService;
	private ConfigService $configService;
	private LoggerInterface $logger;

	public function __construct(
		ActorsRequest $actorsRequest,
		CacheActorService $cacheActorService,
		CurlService $curlService,
		ConfigService $configService,
		LoggerInterface $logger,
	) {
		$this->actorsRequest = $actorsRequest;
		$this->cacheActorService = $cacheActorService;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->logger = $logger;
	}


	/**
	 * @param Person $actor
	 */
	public function generateKeys(Person &$actor) {
		$res = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);

		openssl_pkey_export($res, $privateKey);
		$publicKey = openssl_pkey_get_details($res)['key'];

		$actor->setPublicKey($publicKey);
		$actor->setPrivateKey($privateKey);
	}


	/**
	 * @param NCRequest $request
	 * @param RequestQueue $queue
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function signRequest(NCRequest $request, RequestQueue $queue): void {
		$date = gmdate(self::DATE_HEADER);
		$path = $queue->getInstance();

		$localActor = $this->actorsRequest->getFromId($queue->getAuthor());

		$headersElements = ['(request-target)', 'content-length', 'date', 'host', 'digest'];
		$allElements = [
			'(request-target)' => 'post ' . $path->getPath(),
			'date' => $date,
			'host' => $path->getAddress(),
			'digest' => $this->generateDigest($request->getDataBody()),
			'content-length' => strlen($request->getDataBody())
		];

		$signing = $this->generateHeaders($headersElements, $allElements, $request);
		openssl_sign($signing, $signed, $localActor->getPrivateKey(), OPENSSL_ALGO_SHA256);

		$signed = base64_encode($signed);
		$signature = $this->generateSignature($headersElements, $localActor->getId(), $signed);

		$request->addHeader('Signature', $signature);
	}


	/**
	 * @param array $elements
	 * @param array $data
	 * @param NCRequest $request
	 *
	 * @return string
	 */
	private function generateHeaders(array $elements, array $data, NCRequest $request): string {
		$signingElements = [];
		foreach ($elements as $element) {
			$signingElements[] = $element . ': ' . $data[$element];
			if ($element !== '(request-target)') {
				$request->addHeader($element, (string)$data[$element]);
			}
		}

		return implode("\n", $signingElements);
	}


	/**
	 * @param array $elements
	 * @param string $actorId
	 * @param string $signed
	 *
	 * @return string
	 */
	private function generateSignature(array $elements, string $actorId, string $signed): string {
		$signatureElements[] = 'keyId="' . $actorId . '#main-key"';
		$signatureElements[] = 'algorithm="rsa-sha256"';
		$signatureElements[] = 'headers="' . implode(' ', $elements) . '"';
		$signatureElements[] = 'signature="' . $signed . '"';

		return implode(',', $signatureElements);
	}


	/**
	 * @param string $data
	 *
	 * @return string
	 */
	private function generateDigest(string $data): string {
		$encoded = hash('sha256', $data, true);

		return 'SHA-256=' . base64_encode($encoded);
	}


	/**
	 * @param IRequest $request
	 *
	 * @param string $data
	 * @param int $time
	 *
	 * @return string
	 * @throws DateTimeException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SignatureException
	 * @throws SignatureIsGoneException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function checkRequest(IRequest $request, string $data, int &$time = 0): string {
		try {
			$dTime = new DateTime($request->getHeader('date'));
			$time = $dTime->getTimestamp();
		} catch (Exception $e) {
			throw new DateTimeException(
				'datetime exception: ' . $e->getMessage() . ' - ' . $request->getHeader('date')
			);
		}

		if ($time < (time() - self::DATE_DELAY)) {
			throw new SignatureException('object is too old');
		}

		if (strlen($data) !== (int)$request->getHeader('content-length')) {
			throw new SignatureException('issue with content-length');
		}

		if ($this->generateDigest($data) !== $request->getHeader('digest')) {
			throw new SignatureException(
				'issue with digest -- sent: ' .
				$request->getHeader('digest') . ', expected: ' . $this->generateDigest($data)
			);
		}

		try {
			return $this->checkRequestSignature($request, $data);
		} catch (RequestContentException $e) {
			if ($e->getCode() === Http::STATUS_GONE) {
				throw new SignatureIsGoneException();
			}

			// The signing key could not be retrieved. Failing here, rather than
			// returning an empty origin for a later check to reject, keeps this method
			// the single place that decides whether a request is authenticated.
			throw new SignatureException(
				'signing key could not be retrieved: ' . get_class($e) . ' ' . $e->getMessage(),
				0,
				$e
			);
		}
	}


	/**
	 * @param ACore $object
	 *
	 * @return bool
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 * @throws DateTimeException
	 * @throws UnauthorizedFediverseException
	 */
	public function checkObject(ACore $object): bool {
		try {
			$actorId = $object->getActorId();

			$signature = new LinkedDataSignature();
			$signature->import(json_decode($object->getSource(), true));
			$signature->setPublicKey($this->retrieveKey($actorId));

			if (!$signature->verify()) {
				$signature->setPublicKey($this->retrieveKey($actorId, true));
				if (!$signature->verify()) {
					return false;
				}
			}

			try {
				$dTime = new DateTime($signature->getCreated());
				$time = $dTime->getTimestamp();
			} catch (Exception $e) {
				throw new DateTimeException(
					'datetime exception: ' . $e->getMessage() . ' - ' . $signature->getCreated()
				);
			}

			$object->setOrigin(
				$this->getKeyOrigin($actorId), SignatureService::ORIGIN_SIGNATURE, $time
			);

			return true;
		} catch (LinkedDataSignatureMissingException $e) {
		}

		return false;
	}


	/**
	 * @param Person $actor
	 * @param ACore $object
	 */
	public function signObject(Person $actor, ACore &$object) {
		$signature = new LinkedDataSignature();
		$signature->setPrivateKey($actor->getPrivateKey());
		$signature->setType('RsaSignature2017');
		$signature->setCreator($actor->getId() . '#main-key');
		$signature->setCreated($date = gmdate(self::DATE_OBJECT));
		$signature->setObject(json_decode(json_encode($object), true));

		try {
			$signature->sign();
			$object->setSignature($signature);
		} catch (Exception $e) {
		}
	}


	/**
	 * @param IRequest $request
	 *
	 * @param string $data
	 *
	 * @return string
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 * @throws SignatureException
	 */
	private function checkRequestSignature(IRequest $request, string $data): string {
		$signatureHeader = $request->getHeader('Signature');

		$sign = $this->parseSignatureHeader($signatureHeader);

		$this->mustContains(['keyId', 'headers', 'signature'], $sign);

		$keyId = $sign['keyId'];
		$origin = $this->getKeyOrigin($keyId);

		$headers = $sign['headers'];

		// The digest is checked against the body earlier, but that binds nothing unless
		// the digest itself is signed; and without host and date in the signed set, a
		// captured request can be replayed against another instance or with a swapped
		// body. Everything sending to the Fediverse signs at least these four.
		$signedHeaders = explode(' ', strtolower($headers));
		foreach (['(request-target)', 'host', 'date', 'digest'] as $mandatory) {
			if (!in_array($mandatory, $signedHeaders, true)) {
				throw new SignatureException('header is not signed: ' . $mandatory);
			}
		}
		$signed = base64_decode($sign['signature']);
		$estimated = $this->generateEstimatedSignature($headers, $request);

		try {
			$publicKey = $this->retrieveKey($keyId);
			$this->checkRequestSignatureUsingPublicKey($publicKey, $sign, $estimated, $signed);
		} catch (SignatureException $e) {
			$publicKey = $this->retrieveKey($keyId, true);
			$this->checkRequestSignatureUsingPublicKey($publicKey, $sign, $estimated, $signed);
		}

		return $origin;
	}


	/**
	 * @param string $publicKey
	 * @param array $sign
	 * @param string $estimated
	 * @param string $signed
	 *
	 * @throws SignatureException
	 */
	private function checkRequestSignatureUsingPublicKey(
		string $publicKey, array $sign, string $estimated, string $signed,
	) {
		$algorithm = $this->getAlgorithmFromSignature($sign);
		if ($publicKey === ''
			|| openssl_verify($estimated, $signed, $publicKey, $algorithm) !== 1) {
			throw new SignatureException(
				'signature cannot be checked - signed: ' . $signed . ' - key: ' . $publicKey
				. ' - algo: ' . $algorithm . ' - estimated: ' . $estimated
			);
		}
	}


	/**
	 * @param string $headers
	 * @param IRequest $request
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 * @throws SignatureException
	 */
	private function generateEstimatedSignature(string $headers, IRequest $request): string {
		$keys = explode(' ', $headers);

		if (!empty(array_diff(['(request-target)', 'date'], $keys))) {
			throw new SignatureException('missing elements in \'headers\'');
		}

		$target = '';
		try {
			$target = strtolower($request->getMethod()) . ' ' . $request->getRequestUri();
		} catch (Exception $e) {
		}

		$estimated = '';

		foreach ($keys as $key) {
			if ($key === '(request-target)') {
				$estimated .= '(request-target): ' . $target . "\n";
				continue;
			}

			$value = $request->getHeader($key);
			if ($key === 'host') {
				$value = $this->configService->getCloudHost();
			}

			$estimated .= $key . ': ' . $value . "\n";
		}

		return trim($estimated, "\n");
	}


	/**
	 * @param $signatureHeader
	 *
	 * @return array
	 */
	private function parseSignatureHeader(string $signatureHeader) {
		$sign = [];

		$entries = explode(',', $signatureHeader);
		foreach ($entries as $entry) {
			if ($entry === '' || !strpos($entry, '=')) {
				continue;
			}

			[$k, $v] = explode('=', $entry, 2);
			preg_match('/"([^"]+)"/', $v, $varr);
			if (isset($varr[0])) {
				$v = trim($varr[0], '"');
			}
			$sign[$k] = $v;
		}

		return $sign;
	}


	/**
	 * @param string $keyId
	 *
	 * @param bool $refresh
	 *
	 * @return string
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	private function retrieveKey(string $keyId, bool $refresh = false): string {
		$actor = $this->cacheActorService->getFromId($keyId, $refresh);

		return $actor->getPublicKey();
	}


	/**
	 * @param $id
	 *
	 * @return string
	 * @throws InvalidOriginException
	 */
	private function getKeyOrigin(string $id) {
		$host = parse_url($id, PHP_URL_HOST);
		if (is_string($host) && ($host !== '')) {
			return $host;
		}

		throw new InvalidOriginException(
			'SignatureService::getKeyOrigin - host: ' . $host . ' - id: ' . $id
		);
	}

	/**
	 * @param array $sign
	 *
	 * @return string
	 */
	private function getAlgorithmFromSignature(array $sign): string {
		switch ($this->get('algorithm', $sign, '')) {
			case 'rsa-sha512':
				return 'sha512';

			case 'rsa-sha256':
				return 'sha256';

			default:
				return 'sha256';
		}
	}


	/** Shipped copies of the only JSON-LD contexts signature normalisation may use. */
	public const LOCAL_CONTEXTS = [
		'https://www.w3.org/ns/activitystreams' => 'www.w3.org.ns.activitystreams.json',
		'https://w3id.org/security/v1' => 'w3id.org.security.v1.json',
		'https://w3id.org/identity/v1' => 'w3id.org.identity.v1.json',
	];

	/**
	 * Serves the JSON-LD contexts used during signature normalisation, exclusively
	 * from the copies shipped with the app.
	 *
	 * A document's `@context` is remote input. Resolving it over the network would
	 * hand every signing instance a URL this server then opens — with
	 * `file_get_contents()`, that means any PHP stream wrapper — and a substituted
	 * context would change the bytes a signature is computed over. An unknown context
	 * therefore fails normalisation, which callers treat as an unverifiable
	 * signature rather than an error.
	 *
	 * @throws JsonLdException
	 */
	public static function documentLoader($url): stdClass {
		$filename = self::LOCAL_CONTEXTS[$url] ?? '';
		if ($filename === '') {
			throw new JsonLdException('remote @context is not resolved: ' . $url, 'jsonld.LoadDocumentError');
		}

		$context = file_get_contents(__DIR__ . '/../../context/' . $filename);
		if (is_bool($context)) {
			throw new JsonLdException('shipped context cannot be read: ' . $filename, 'jsonld.LoadDocumentError');
		}

		return json_decode($context);
	}
}
