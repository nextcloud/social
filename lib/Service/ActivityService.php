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


use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Traits\TArrayTools;
use DateTime;
use Exception;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityPub\PersonService;
use OCP\IRequest;

class ActivityService {


	use TArrayTools;


	const REQUEST_INBOX = 1;

	const CONTEXT_ACTIVITYSTREAMS = 'https://www.w3.org/ns/activitystreams';
	const CONTEXT_SECURITY = 'https://w3id.org/security/v1';

	const TO_PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

	const DATE_FORMAT = 'D, d M Y H:i:s T';
	const DATE_DELAY = 30;

	/** @var ActorsRequest */
	private $actorsRequest;

	/** @var ActorService */
	private $actorService;

	/** @var PersonService */
	private $personService;

	/** @var InstanceService */
	private $instanceService;

	/** @var ConfigService */
	private $configService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActivityService constructor.
	 *
	 * @param ActorsRequest $actorsRequest
	 * @param CurlService $curlService
	 * @param ActorService $actorService
	 * @param PersonService $personService
	 * @param InstanceService $instanceService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActorsRequest $actorsRequest, CurlService $curlService, ActorService $actorService,
		PersonService $personService, InstanceService $instanceService,
		ConfigService $configService,
		MiscService $miscService
	) {
		$this->curlService = $curlService;
		$this->actorsRequest = $actorsRequest;
		$this->actorService = $actorService;
		$this->personService = $personService;
		$this->instanceService = $instanceService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	public function test() {


	}


	/**
	 * @param Person $actor
	 * @param ACore $item
	 * @param int $type
	 * @param ACore $activity
	 *
	 * @return array
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function createActivity(Person $actor, ACore $item, int $type, ACore &$activity = null
	): array {

		$activity = new Create();
		$item->setParent($activity);

//		$this->activityStreamsService->initCore($activity);

		$activity->setObject($item);
		$activity->setId($item->getId() . '/activity');
		$activity->addInstancePaths($item->getInstancePaths());

//		if ($item->getToArray() !== []) {
//			$activity->setToArray($item->getToArray());
//		} else {
//			$activity->setTo($item->getTo());
//		}

		$activity->setActor($actor);

		$result = $this->request($activity, $type);

		return $result;
	}


	/**
	 * @param ACore $activity
	 * @param int $type
	 *
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function manageRequest(ACore $activity, int $type) {
		$result = $this->request($activity, $type);
		$this->miscService->log('Activity: ' . json_encode($activity));
		$this->miscService->log('Result: ' . json_encode($result));
	}


	/**
	 * @param ACore $activity
	 *
	 * @param int $type
	 *
	 * @return array
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function request(ACore &$activity, int $type) {
		$this->setupCore($activity);
		$hosts = $this->instanceService->getInstancesFromActivity($activity);

		$result = [];
		foreach ($hosts as $host) {
			foreach ($host->getInstancePaths() as $path) {
				$result[] = $this->generateRequest($host->getAddress(), $path, $type, $activity);
			}
		}

		return $result;
	}


	/**
	 * @param string $address
	 * @param InstancePath $path
	 * @param int $type
	 * @param ACore $activity
	 *
	 * @return Request[]
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws ActorDoesNotExistException
	 */
	public function generateRequest(string $address, InstancePath $path, int $type, ACore $activity
	): array {
		$document = json_encode($activity);
		$date = gmdate(self::DATE_FORMAT);
		$localActor = $this->getActorFromActivity($activity);

		$localActorLink =
			$this->configService->getUrlRoot() . '@' . $localActor->getPreferredUsername();
		$signature = "(request-target): post " . $path->getPath() . "\nhost: " . $address
					 . "\ndate: " . $date;

		openssl_sign($signature, $signed, $localActor->getPrivateKey(), OPENSSL_ALGO_SHA256);

		$signed = base64_encode($signed);
		$header =
			'keyId="' . $localActorLink . '",headers="(request-target) host date",signature="'
			. $signed . '"';

		$requestType = Request::TYPE_GET;
		if ($type === self::REQUEST_INBOX) {
			$requestType = Request::TYPE_POST;
		}


		$request = new Request($path->getPath(), $requestType);
		$request->addHeader('Host: ' . $address);
		$request->addHeader('Date: ' . $date);
		$request->addHeader('Signature: ' . $header);

		$request->setDataJson($document);
		$request->setAddress($address);

		return $this->curlService->request($request);
	}


	/**
	 * @param IRequest $request
	 *
	 * @throws Exception
	 */
	public function checkRequest(IRequest $request) {
		$dTime = new DateTime($request->getHeader('date'));
		$dTime->format(self::DATE_FORMAT);

		if ($dTime->getTimestamp() < (time() - self::DATE_DELAY)) {
			throw new Exception('object is too old');
		}

		$this->checkSignature($request);
	}


//	/**
//	 * @param Core $activity
//	 *
//	 * @return array
//	 */
//	private function getHostsFromActivity(Core $activity) {
//
//		$hosts = [];
//		$hosts[] = $this->getHostFromUriId($activity->getTo());
//		foreach ($activity->getToArray() as $to) {
//			$hosts[] = $this->getHostFromUriId($to);
//		}
//
//		if ($activity instanceof Note) {
//			/** @var Note $activity */
//			$hosts[] = $this->getHostFromUriId($activity->getInReplyTo());
//		}
//
//		$hosts = $this->cleaningHosts($hosts);
//
//		return $hosts;
//	}


//	/**
//	 * @param array $hosts
//	 *
//	 * @return array
//	 */
//	private function cleaningHosts(array $hosts) {
//		$ret = [];
//		foreach ($hosts as $host) {
//			if ($host === '') {
//				continue;
//			}
//
//			$ret[] = $host;
//		}
//
//		return $ret;
//	}


	/**
	 * @param ACore $activity
	 *
	 * @return Person
	 * @throws SocialAppConfigException
	 * @throws ActorDoesNotExistException
	 */
	private function getActorFromActivity(Acore $activity): Person {
		if ($activity->gotActor()) {
			return $activity->getActor();
		}

		$actorId = $activity->getActorId();

		return $this->actorService->getActorById($actorId);
	}


	/**
	 * @param IRequest $request
	 *
	 * @throws Exception
	 */
	private function checkSignature(IRequest $request) {
		$signatureHeader = $request->getHeader('Signature');

		$sign = $this->parseSignatureHeader($signatureHeader);
		$this->mustContains(['keyId', 'headers', 'signature'], $sign);

		$keyId = $sign['keyId'];
		$headers = $sign['headers'];
		$signed = base64_decode($sign['signature']);
		$estimated = $this->generateEstimatedSignature($headers, $request);

		$publicKey = $this->retrieveKey($keyId);

		if (openssl_verify($estimated, $signed, $publicKey, 'sha256') !== 1) {
			throw new Exception('signature cannot be checked');
		}

	}


	/**
	 * @param string $headers
	 * @param IRequest $request
	 *
	 * @return string
	 * @throws Exception
	 */
	private function generateEstimatedSignature(string $headers, IRequest $request): string {
		$keys = explode(' ', $headers);

		$remoteTarget = strtolower($request->getMethod()) . " " . $request->getPathInfo();
		$estimated = "(request-target): " . $remoteTarget;

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
	 * @throws RequestException
	 * @throws InvalidResourceException
	 */
	private function retrieveKey($keyId): string {
		$actor = $this->personService->getFromId($keyId);

		return $actor->getPublicKey();
	}


	/**
	 * @param ACore $activity
	 */
	private function setupCore(ACore $activity) {

//		$this->initCore($activity);
		if ($activity->isRoot()) {
			$activity->addEntry('@context', self::CONTEXT_ACTIVITYSTREAMS);
		}

		$coreService = $activity->savingAs();
		if ($coreService !== null) {
			$coreService->save($activity);
		}

		if ($activity->gotObject()) {
			$this->setupCore($activity->getObject());
		}
	}


}

