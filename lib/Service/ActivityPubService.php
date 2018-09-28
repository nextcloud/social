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


use daita\Model\Request;
use DateTime;
use Exception;
use OC\User\NoUserException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\APIRequestException;
use OCA\Social\Exceptions\InvalidAccessTokenException;
use OCA\Social\Exceptions\MovedPermanentlyException;
use OCA\Social\Model\ActivityPub\ActivityCreate;
use OCA\Social\Model\ActivityPub\Core;
use OCA\Social\Model\ActivityPub\Note;
use OCA\Social\Model\APHosts;
use OCP\IRequest;

class ActivityPubService {


	const CONTEXT_ACTIVITYSTREAMS = 'https://www.w3.org/ns/activitystreams';
	const CONTEXT_SECURITY = 'https://w3id.org/security/v1';

	const TO_PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

	const DATE_FORMAT = 'D, d M Y H:i:s T';
	const DATE_DELAY = 30;

	/** @var ActivityStreamsService */
	private $activityStreamsService;

	/** @var ActorsRequest */
	private $actorsRequest;

	/** @var ActorService */
	private $actorService;

	/** @var ConfigService */
	private $configService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActivityStreamsService constructor.
	 *
	 * @param ActivityStreamsService $activityStreamsService
	 * @param CurlService $curlService
	 * @param ActorsRequest $actorsRequest
	 * @param ActorService $actorService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActivityStreamsService $activityStreamsService,
		CurlService $curlService,
		ActorsRequest $actorsRequest,
		ActorService $actorService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->activityStreamsService = $activityStreamsService;
		$this->curlService = $curlService;
		$this->actorsRequest = $actorsRequest;
		$this->actorService = $actorService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	public function test() {


	}


	/**
	 * @param string $userId
	 *
	 * @param Core $item
	 * @param Core $activity
	 *
	 * @return array
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 * @throws APIRequestException
	 * @throws InvalidAccessTokenException
	 * @throws MovedPermanentlyException
	 */
	public function createActivity($userId, Core $item, Core &$activity = null): array {

		$activity = new ActivityCreate(true);
//		$this->activityStreamsService->initCore($activity);

		$actor = $this->actorService->getActorFromUserId($userId);
		$activity->setId($item->getId() . '/activity');

		if ($item->getToArray() !== []) {
			$activity->setToArray($item->getToArray());
		} else {
			$activity->setTo($item->getTo());
		}

		$activity->setActor($actor);
		$activity->setObject($item);

		$this->setupCore($activity);
		$result = $this->request($activity);

		return $result;
	}


	/**
	 * @param Core $activity
	 *
	 * @return array
	 * @throws APIRequestException
	 * @throws InvalidAccessTokenException
	 * @throws MovedPermanentlyException
	 */
	public function request(Core $activity) {
		$hosts = $this->getAPHostsFromActivity($activity);

		$result = [];
		foreach ($hosts as $host) {
			$request = $this->generateRequest($host, $activity);
			$result[] = $this->curlService->request($request);
		}

		return $result;
	}


	/**
	 * @param APHosts $host
	 * @param Core $activity
	 *
	 * @return Request
	 */
	public function generateRequest(APHosts $host, Core $activity): Request {

		$document = json_encode($activity);
		$date = date(self::DATE_FORMAT);

		$localActor = $activity->getActor();
		$uriIds = $host->getUriIds();
		$remoteActor = $this->getRemoteActor($uriIds[0]);

		$localActorLink =
			$this->configService->getRoot() . '@' . $localActor->getPreferredUsername();
		$signature = "(request-target): post /users/testSocial/inbox\nhost: " . $host->getAddress()
					 . "\ndate: " . $date;

		openssl_sign($signature, $signed, $localActor->getPrivateKey(), OPENSSL_ALGO_SHA256);

		$signed = base64_encode($signed);
		$header =
			'keyId="' . $localActorLink . '",headers="(request-target) host date",signature="'
			. $signed . '"';

		$request = new Request('/users/testSocial/inbox', Request::TYPE_POST);
		$request->addHeader('Host: ' . $host->getAddress());
		$request->addHeader('Date: ' . $date);
		$request->addHeader('Signature: ' . $header);

		$request->setDataJson($document);
		$request->setAddress($host->getAddress());

		return $request;
	}


	/**
	 * @param IRequest $request
	 *
	 * @throws APIRequestException
	 * @throws InvalidAccessTokenException
	 * @throws MovedPermanentlyException
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


	/**
	 * @param Core $activity
	 *
	 * @return APHosts[]
	 */
	private function getAPHostsFromActivity(Core $activity) {

		$hosts = [];
//		$uriIds = [];
		$this->addAPHosts($activity->getTo(), $hosts);
		foreach ($activity->getToArray() as $to) {
			$this->addAPHosts($to, $hosts);
//			$uriIds[] = $to;
		}

		if ($activity instanceof Note) {
			/** @var Note $activity */
			$this->addAPHosts($activity->getInReplyTo(), $hosts);
//			$uriIds[] = $activity->getInReplyTo();
		}

//		$uriId = $this->cleaningHosts($uriId);

		return $hosts;
	}


	/**
	 * @param string $uriId
	 * @param APHosts[] $hosts
	 */
	private function addAPHosts(string $uriId, array &$hosts) {
		$address = $this->getHostFromUriId($uriId);
		if ($address === '') {
			return;
		}

		foreach ($hosts as $host) {
			if ($host->getAddress() === $address) {
				$host->addUriId($uriId);

				return;
			}
		}

		$apHost = new APHosts($address);
		$apHost->addUriId($uriId);
		$hosts[] = $apHost;
	}


	/**
	 * @param string $uriId
	 *
	 * @return mixed
	 * @throws APIRequestException
	 * @throws InvalidAccessTokenException
	 * @throws MovedPermanentlyException
	 */
	private function getRemoteActor(string $uriId) {
		$actor = $this->actorService->getFromUri($uriId);

		return $actor;
	}


	/**
	 * @param string $uriId
	 *
	 * @return string
	 */
	private function getHostFromUriId(string $uriId) {
		$ignoreThose = [
			'',
			'https://www.w3.org/ns/activitystreams#Public'
		];

		if (in_array($uriId, $ignoreThose)) {
			return '';
		}

		$url = parse_url($uriId);
		if (!is_array($url) || !array_key_exists('host', $url)) {
			return '';
		}

		return $url['host'];
	}


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
	 * @param IRequest $request
	 *
	 * @throws APIRequestException
	 * @throws InvalidAccessTokenException
	 * @throws MovedPermanentlyException
	 * @throws Exception
	 */
	private function checkSignature(IRequest $request) {
		$signatureHeader = $request->getHeader('Signature');

		$sign = $this->parseSignatureHeader($signatureHeader);
		$this->miscService->mustContains(['keyId', 'headers', 'signature'], $sign);

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
	 */
	private function generateEstimatedSignature(string $headers, IRequest $request): string {
		$keys = explode(' ', $headers);

		$estimated = "(request-target): post /apps/social/@maxence/inbox";
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
	 * @return array
	 * @throws MovedPermanentlyException
	 * @throws APIRequestException
	 * @throws InvalidAccessTokenException
	 */
	private function retrieveKey($keyId) {
		$actor = $this->retrieveObject($keyId);

		return $actor['publicKey']['publicKeyPem'];
	}

//
//	/**
//	 * @param $id
//	 *
//	 * @return mixed
//	 * @throws MovedPermanentlyException
//	 * @throws APIRequestException
//	 * @throws InvalidAccessTokenException
//	 */
//	private function retrieveObject($id) {
//		$url = parse_url($id);
//
//		$request = new Request($url['path'], Request::TYPE_GET);
//		$request->setAddress($url['host']);
//
////		$key = $url['fragment'];
//
//		return $this->curlService->request($request);
//	}


	/**
	 * @param Core $activity
	 */
	private function setupCore(Core $activity) {

//		$this->initCore($activity);
		if ($activity->isTopLevel()) {
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


//	public function setRoot(IActivityPub $actor) {
//		$actor->setRoot('https://test.artificial-owl.com/apps/social');
//	}


}
