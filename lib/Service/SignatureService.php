<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Exceptions\DateTimeException;
use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Traits\TArrayTools;
use DateTime;
use Exception;
use JsonLdException;
use OC;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\LinkedDataSignatureMissingException;
use OCA\Social\Exceptions\RedundancyLimitException;
use daita\MySmallPhpTools\Exceptions\RequestContentException;
use daita\MySmallPhpTools\Exceptions\RequestNetworkException;
use daita\MySmallPhpTools\Exceptions\RequestResultNotJsonException;
use daita\MySmallPhpTools\Exceptions\RequestResultSizeException;
use daita\MySmallPhpTools\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Model\RequestQueue;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IRequest;
use stdClass;

class SignatureService {


	use TArrayTools;


	const ORIGIN_HEADER = 1;
	const ORIGIN_SIGNATURE = 2;
	const ORIGIN_REQUEST = 3;


	const DATE_HEADER = 'D, d M Y H:i:s T';
	const DATE_OBJECT = 'Y-m-d\TH:i:s\Z';

	const DATE_DELAY = 300;


	/** @var CacheActorService */
	private $cacheActorService;

	/** @var ActorsRequest */
	private $actorsRequest;

	/** @var CurlService */
	private $curlService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActivityService constructor.
	 *
	 * @param ActorsRequest $actorsRequest
	 * @param CacheActorService $cacheActorService
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActorsRequest $actorsRequest, CacheActorService $cacheActorService,
		CurlService $curlService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->actorsRequest = $actorsRequest;
		$this->cacheActorService = $cacheActorService;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $actor
	 */
	public function generateKeys(Person &$actor) {
		$res = openssl_pkey_new(
			[
				"digest_alg"       => "rsa",
				"private_key_bits" => 2048,
				"private_key_type" => OPENSSL_KEYTYPE_RSA,
			]
		);

		openssl_pkey_export($res, $privateKey);
		$publicKey = openssl_pkey_get_details($res)['key'];

		$actor->setPublicKey($publicKey);
		$actor->setPrivateKey($privateKey);
	}


	/**
	 * @param Request $request
	 * @param RequestQueue $queue
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function signRequest(Request $request, RequestQueue $queue) {
		$date = gmdate(self::DATE_HEADER);
		$path = $queue->getInstance();

		$localActor = $this->actorsRequest->getFromId($queue->getAuthor());

		$headersElements = ['content-length', 'date', 'host', 'digest'];
		$allElements = [
			'(request-target)' => 'post ' . $path->getPath(),
			'date'             => $date,
			'host'             => $path->getAddress(),
			'digest'           => $this->generateDigest($request->getDataBody()),
			'content-length'   => strlen($request->getDataBody())
		];

		$signing = $this->generateHeaders($headersElements, $allElements, $request);
		openssl_sign($signing, $signed, $localActor->getPrivateKey(), OPENSSL_ALGO_SHA256);

		$signed = base64_encode($signed);
		$signature = $this->generateSignature($headersElements, $localActor->getId(), $signed);

		$request->addHeader('Signature: ' . $signature);
	}


	/**
	 * @param array $elements
	 * @param array $data
	 * @param Request $request
	 *
	 * @return string
	 */
	private function generateHeaders(array $elements, array $data, Request $request): string {
		$signingElements = [];
		foreach ($elements as $element) {
			$signingElements[] = $element . ': ' . $data[$element];
			$request->addHeader($element . ': ' . $data[$element]);
		}

		return implode("\n", $signingElements);
	}


	/**
	 * @param array $elements
	 * @param string $actorId
	 * @param string $signed
	 *
	 * @return array
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
		$encoded = hash("sha256", utf8_encode($data), true);

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

		try {
			return $this->checkRequestSignature($request, $data);
		} catch (RequestContentException $e) {
			throw new SignatureIsGoneException();
		} catch (SignatureException $e) {
		}

		return '';
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
			$this->miscService->log(
				'Notice: LinkedDataSignatureMissingException while checkObject : ' . $e->getMessage()
				. ' --- ' . json_encode($object), 1
			);
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
		$signed = base64_decode($sign['signature']);
		$estimated = $this->generateEstimatedSignature($headers, $request);

		// TODO: check digest
		//	$this->generateDigest($data);

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
		string $publicKey, array $sign, string $estimated, string $signed
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
	 */
	private function generateEstimatedSignature(string $headers, IRequest $request): string {
		$keys = explode(' ', $headers);

		$target = '';
		try {
			$target = strtolower($request->getMethod()) . " " . $request->getRequestUri();
		} catch (Exception $e) {
		}

		$estimated = '';

		foreach ($keys as $key) {
			if ($key === '(request-target)') {
				$estimated .= "(request-target): " . $target . "\n";
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
	private function parseSignatureHeader($signatureHeader) {
		$sign = [];

		$entries = explode(',', $signatureHeader);
		foreach ($entries as $entry) {
			if ($entry === '' || !strpos($entry, '=')) {
				continue;
			}

			list($k, $v) = explode('=', $entry, 2);
			preg_match('/"([^"]+)"/', $v, $varr);
			$v = trim($varr[0], '"');

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
	private function getKeyOrigin($id) {
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


	/**
	 * @param string $url
	 *
	 * @return stdClass
	 * @throws NotPermittedException
	 * @throws JsonLdException
	 * @throws NotFoundException
	 */
	public static function documentLoader($url): stdClass {
		$recursion = 0;
		$x = debug_backtrace();
		if ($x) {
			foreach ($x as $n) {
				if ($n['function'] === __FUNCTION__) {
					$recursion++;
				}
			}
		}

		if ($recursion > 5) {
			exit();
		}

		$folder = self::getContextCacheFolder();
		$filename = parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH);
		$filename = str_replace('/', '.', $filename) . '.json';

		try {
			$cache = $folder->getFile($filename);
			self::updateContextCacheDocument($cache, $url);

			$data = json_decode($cache->getContent());
		} catch (NotFoundException $e) {
			$data = self::generateContextCacheDocument($folder, $filename, $url);
		}

		return $data;
	}


	/**
	 * @return ISimpleFolder
	 * @throws NotPermittedException
	 */
	private static function getContextCacheFolder(): ISimpleFolder {
		$path = 'context';

		$appData = OC::$server->getAppDataDir(Application::APP_NAME);
		try {
			$folder = $appData->getFolder($path);
		} catch (NotFoundException $e) {
			$folder = $appData->newFolder($path);
		}

		return $folder;
	}


	/**
	 * @param ISimpleFolder $folder
	 * @param string $filename
	 *
	 * @param string $url
	 *
	 * @return stdClass
	 * @throws JsonLdException
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 */
	private static function generateContextCacheDocument(
		ISimpleFolder $folder, string $filename, string $url
	): stdClass {

		try {
			$data = jsonld_default_document_loader($url);
			$content = json_encode($data);
		} catch (JsonLdException $e) {
			$context = file_get_contents(__DIR__ . '/../../context/' . $filename);
			if (is_bool($context)) {
				throw $e;
			}

			$content = $context;
			$data = json_decode($context);
		}

		$cache = $folder->newFile($filename);
		$cache->putContent($content);

		return $data;
	}


	/**
	 * @param ISimpleFile $cache
	 * @param string $url
	 *
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 */
	private static function updateContextCacheDocument(ISimpleFile $cache, string $url) {
		if ($cache->getMTime() < (time() - 98765)) {
			try {
				$data = jsonld_default_document_loader($url);
				$cache->putContent(json_encode($data));
			} catch (JsonLdException $e) {
			}
		}
	}

}

