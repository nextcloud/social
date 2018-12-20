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


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Traits\TArrayTools;
use DateTime;
use Exception;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\LinkedDataSignatureMissingException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\Request410Exception;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Model\RequestQueue;
use OCP\IRequest;

class SignatureService {


	use TArrayTools;


	const ORIGIN_HEADER = 1;
	const ORIGIN_SIGNATURE = 2;


	const DATE_FORMAT = 'D, d M Y H:i:s T';
	const DATE_DELAY = 30;


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
		$date = gmdate(self::DATE_FORMAT);
		$path = $queue->getInstance();

		$localActor = $this->actorsRequest->getFromId($queue->getAuthor());

		$localActorLink =
			$this->configService->getUrlSocial() . '@' . $localActor->getPreferredUsername();
		$signature = "(request-target): post " . $path->getPath() . "\nhost: " . $path->getAddress()
					 . "\ndate: " . $date;

		openssl_sign($signature, $signed, $localActor->getPrivateKey(), OPENSSL_ALGO_SHA256);
		$signed = base64_encode($signed);

		$header = 'keyId="' . $localActorLink
				  . '",algorithm="rsa-sha256",headers="(request-target) host date",signature="'
				  . $signed . '"';

		$request->addHeader('Host: ' . $path->getAddress());
		$request->addHeader('Date: ' . $date);
		$request->addHeader('Signature: ' . $header);
	}


	/**
	 * @param IRequest $request
	 *
	 * @return string
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestException
	 * @throws SignatureException
	 * @throws SignatureIsGoneException
	 * @throws SocialAppConfigException
	 * @throws UnknownItemException
	 */
	public function checkRequest(IRequest $request): string {
		$dTime = new DateTime($request->getHeader('date'));
		$dTime->format(self::DATE_FORMAT);

		if ($dTime->getTimestamp() < (time() - self::DATE_DELAY)) {
			throw new SignatureException('object is too old');
		}

		try {
			$origin = $this->checkRequestSignature($request);
		} catch (Request410Exception $e) {
			throw new SignatureIsGoneException();
		}

		return $origin;
	}


	/**
	 * @param ACore $object
	 *
	 * @return bool
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws Request410Exception
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws UnknownItemException
	 */
	public function checkObject(ACore $object): bool {
		try {
			$actorId = $object->getActorId();

			$signature = new LinkedDataSignature();
			$signature->import(json_decode($object->getSource(), true));
			$signature->setPublicKey($this->retrieveKey($actorId));
			if ($signature->verify()) {
				$object->setOrigin(
					$this->getKeyOrigin($actorId), SignatureService::ORIGIN_SIGNATURE
				);

				return true;
			}
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
		$signature->setCreated($object->getPublished());
		$signature->setObject(json_decode(json_encode($object), true));

		try {
			$signature->sign();
			$object->setSignature($signature);
		} catch (LinkedDataSignatureMissingException $e) {
		}
	}


	/**
	 * @param IRequest $request
	 *
	 * @return string
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws Request410Exception
	 * @throws RequestException
	 * @throws SignatureException
	 * @throws SocialAppConfigException
	 * @throws UnknownItemException
	 */
	private function checkRequestSignature(IRequest $request): string {
		$signatureHeader = $request->getHeader('Signature');

		$sign = $this->parseSignatureHeader($signatureHeader);
		$this->mustContains(['keyId', 'headers', 'signature'], $sign);

		$keyId = $sign['keyId'];
		$origin = $this->getKeyOrigin($keyId);

		$headers = $sign['headers'];
		$signed = base64_decode($sign['signature']);
		$estimated = $this->generateEstimatedSignature($headers, $request);

		$algorithm = 'sha256';
		if ($sign['algorithm'] === 'rsa-sha256') {
			$algorithm = 'sha256';
		}

		$publicKey = $this->retrieveKey($keyId);

		if ($publicKey === ''
			|| openssl_verify($estimated, $signed, $publicKey, $algorithm) !== 1) {
			throw new SignatureException('signature cannot be checked');
		}

		return $origin;
	}


	/**
	 * @param string $headers
	 * @param IRequest $request
	 *
	 * @return string
	 */
	private function generateEstimatedSignature(string $headers, IRequest $request): string {
		$keys = explode(' ', $headers);

		$target = '';
		try {
			$target = strtolower($request->getMethod()) . " " . $request->getRequestUri();
		} catch (Exception $e) {
		}

		$estimated = "(request-target): " . $target;

		foreach ($keys as $key) {
			if ($key === '(request-target)') {
				continue;
			}

			$estimated .= "\n" . $key . ': ' . $request->getHeader($key);
		}

		return $estimated;
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
			list($k, $v) = explode('=', $entry, 2);
			preg_match('/"([^"]+)"/', $v, $varr);
			$v = trim($varr[0], '"');

			$sign[$k] = $v;
		}

		return $sign;
	}


	/**
	 * @param $keyId
	 *
	 * @return string
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws Request410Exception
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws UnknownItemException
	 * @throws RedundancyLimitException
	 * @throws InvalidOriginException
	 */
	private function retrieveKey($keyId): string {
		$actor = $this->cacheActorService->getFromId($keyId);

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

		throw new InvalidOriginException();
	}


}
