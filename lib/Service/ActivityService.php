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
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Tombstone;
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

	/** @var NotesRequest */
	private $notesRequest;

	/** @var FollowsRequest */
	private $followsRequest;

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
	 * @param NotesRequest $notesRequest
	 * @param FollowsRequest $followsRequest
	 * @param CurlService $curlService
	 * @param ActorService $actorService
	 * @param PersonService $personService
	 * @param InstanceService $instanceService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActorsRequest $actorsRequest, NotesRequest $notesRequest, FollowsRequest $followsRequest,
		CurlService $curlService, ActorService $actorService,
		PersonService $personService, InstanceService $instanceService,
		ConfigService $configService,
		MiscService $miscService
	) {
		$this->curlService = $curlService;
		$this->actorsRequest = $actorsRequest;
		$this->notesRequest = $notesRequest;
		$this->followsRequest = $followsRequest;
		$this->actorService = $actorService;
		$this->personService = $personService;
		$this->instanceService = $instanceService;
		$this->configService = $configService;
		$this->miscService = $miscService;
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
	 * @throws ActorDoesNotExistException
	 */
	public function createActivity(Person $actor, ACore $item, ACore &$activity = null
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

		$result = $this->request($activity);

		return $result;
	}


	/**
	 * @param ACore $item
	 *
	 * @throws ActorDoesNotExistException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function deleteActivity(ACore $item) {
		$delete = new Delete();
		$delete->setId($item->getId() . '#delete');
		$delete->setActorId($item->getActorId());

		$tombstone = new Tombstone($delete);
		$tombstone->setId($item->getId());

		$delete->setObject($tombstone);
		$delete->addInstancePaths($item->getInstancePaths());

		$this->request($delete);
	}


	/**
	 * @param string $id
	 *
	 * @return ACore
	 * @throws InvalidResourceException
	 */
	public function getItem(string $id): ACore {
		if ($id === '') {
			throw new InvalidResourceException();
		}

		$requests = [
			$this->notesRequest
		];

		foreach ($requests as $request) {
			try {
				$toDelete = $request->getNoteById($id);

				return $toDelete;
			} catch (Exception $e) {
			}
		}

		throw new InvalidResourceException();
	}


	/**
	 * @param ACore $activity
	 *
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws ActorDoesNotExistException
	 */
	public function manageRequest(ACore $activity) {
		$result = $this->request($activity);
		$this->miscService->log('Activity: ' . json_encode($activity));
		$this->miscService->log('Result: ' . json_encode($result));
	}


	/**
	 * @param ACore $activity
	 *
	 *
	 * @return array
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws ActorDoesNotExistException
	 */
	public function request(ACore &$activity) {
		$this->setupCore($activity);
//		$hosts = $this->instanceService->getInstancesFromActivity($activity);

		$result = [];
//		foreach ($hosts as $host) {
//			foreach ($host->getInstancePaths() as $path) {
		foreach ($activity->getInstancePaths() as $instancePath) {
			if ($instancePath->getType() === InstancePath::TYPE_FOLLOWERS) {
				$result = array_merge($result, $this->requestToFollowers($activity, $instancePath));
			} else {
				$result[] = $this->generateRequest($instancePath, $activity);
			}
		}

//		}

		return $result;
	}


	/**
	 * @param ACore $activity
	 * @param InstancePath $instancePath
	 *
	 * @return array
	 * @throws ActorDoesNotExistException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	private function requestToFollowers(ACore &$activity, InstancePath $instancePath): array {
		$result = [];

		$sharedInboxes = [];
		$follows = $this->followsRequest->getByFollowId($instancePath->getUri());
		foreach ($follows as $follow) {
			if (!$follow->gotActor()) {
				// TODO - check if cache can be empty at this point ?
				continue;
			}

			$sharedInbox = $follow->getActor()
								  ->getSharedInbox();
			if (in_array($sharedInbox, $sharedInboxes)) {
				continue;
			}

			$sharedInboxes[] = $sharedInbox;
			$result[] = $this->generateRequest(
				new InstancePath($sharedInbox, InstancePath::TYPE_GLOBAL), $activity
			);
		}

		return $result;
	}


	/**
	 * @param InstancePath $path
	 * @param ACore $activity
	 *
	 * @return Request[]
	 * @throws ActorDoesNotExistException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function generateRequest(InstancePath $path, ACore $activity): array {
		$document = json_encode($activity);
		$date = gmdate(self::DATE_FORMAT);
		$localActor = $this->getActorFromItem($activity);

		$localActorLink =
			$this->configService->getUrlRoot() . '@' . $localActor->getPreferredUsername();
		$signature = "(request-target): post " . $path->getPath() . "\nhost: " . $path->getAddress()
					 . "\ndate: " . $date;

		openssl_sign($signature, $signed, $localActor->getPrivateKey(), OPENSSL_ALGO_SHA256);

		$signed = base64_encode($signed);
		$header =
			'keyId="' . $localActorLink . '",headers="(request-target) host date",signature="'
			. $signed . '"';

		$requestType = Request::TYPE_GET;
		if ($path->getType() === InstancePath::TYPE_INBOX
			|| $path->getType() === InstancePath::TYPE_GLOBAL
			|| $path->getType() === InstancePath::TYPE_FOLLOWERS) {
			$requestType = Request::TYPE_POST;
		}

		$request = new Request($path->getPath(), $requestType);
		$request->addHeader('Host: ' . $path->getAddress());
		$request->addHeader('Date: ' . $date);
		$request->addHeader('Signature: ' . $header);

		$request->setDataJson($document);
		$request->setAddress($path->getAddress());

		return $this->curlService->request($request);
	}


	/**
	 * @param IRequest $request
	 *
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RequestException
	 * @throws SignatureException
	 */
	public function checkRequest(IRequest $request) {
		$dTime = new DateTime($request->getHeader('date'));
		$dTime->format(self::DATE_FORMAT);

		if ($dTime->getTimestamp() < (time() - self::DATE_DELAY)) {
			throw new SignatureException('object is too old');
		}

		$this->checkSignature($request);
	}


	/**
	 * @param ACore $activity
	 *
	 * @return Person
	 * @throws SocialAppConfigException
	 * @throws ActorDoesNotExistException
	 */
	private function getActorFromItem(Acore $activity): Person {
		if ($activity->gotActor()) {
			return $activity->getActor();
		}

		$actorId = $activity->getActorId();

		return $this->actorService->getActorById($actorId);
	}


	/**
	 * @param IRequest $request
	 *
	 * @throws InvalidResourceException
	 * @throws RequestException
	 * @throws SignatureException
	 * @throws MalformedArrayException
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

		if ($publicKey === '' || openssl_verify($estimated, $signed, $publicKey, 'sha256') !== 1) {
			throw new SignatureException('signature cannot be checked');
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
			$coreService->parse($activity);
		}

		if ($activity->gotObject()) {
			$this->setupCore($activity->getObject());
		}
	}


}

