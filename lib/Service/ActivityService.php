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
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\Request410Exception;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Tombstone;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
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

	/** @var QueueService */
	private $queueService;

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
	 * @param QueueService $queueService
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
		QueueService $queueService, CurlService $curlService, ActorService $actorService,
		PersonService $personService, InstanceService $instanceService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->actorsRequest = $actorsRequest;
		$this->notesRequest = $notesRequest;
		$this->followsRequest = $followsRequest;
		$this->queueService = $queueService;
		$this->curlService = $curlService;
		$this->actorService = $actorService;
		$this->personService = $personService;
		$this->instanceService = $instanceService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $actor
	 * @param ACore $item
	 * @param ACore $activity
	 *
	 * @return string
	 * @throws Exception
	 */
	public function createActivity(Person $actor, ACore $item, ACore &$activity = null
	): string {

		$activity = new Create();
		$item->setParent($activity);

//		$this->activityStreamsService->initCore($activity);

		$activity->setObject($item);
		$activity->setId($item->getId() . '/activity');
		$activity->setInstancePaths($item->getInstancePaths());

//		if ($item->getToArray() !== []) {
//			$activity->setToArray($item->getToArray());
//		} else {
//			$activity->setTo($item->getTo());
//		}

		$activity->setActor($actor);

		return $this->request($activity);
	}


	/**
	 * @param ACore $item
	 *
	 * @return string
	 * @throws Exception
	 */
	public function deleteActivity(ACore $item): string {
		$delete = new Delete();
		$delete->setId($item->getId() . '#delete');
		$delete->setActorId($item->getActorId());

		$tombstone = new Tombstone($delete);
		$tombstone->setId($item->getId());

		$delete->setObject($tombstone);
		$delete->addInstancePaths($item->getInstancePaths());

		return $this->request($delete);
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
	 * @return string
	 * @throws Exception
	 */
	public function request(ACore $activity): string {
		$this->setupCore($activity);

		$author = $this->getAuthorFromItem($activity);
		$instancePaths = $this->generateInstancePaths($activity);
		$token = $this->queueService->generateRequestQueue($instancePaths, $activity, $author);

		try {
			$directRequest = $this->queueService->getPriorityRequest($token);
			$this->manageRequest($directRequest);
		} catch (NoHighPriorityRequestException $e) {
		} catch (EmptyQueueException $e) {
			return '';
		}

		$this->curlService->asyncWithToken($token);

		return $token;
	}


	/**
	 * @param RequestQueue $queue
	 *
	 * @throws ActorDoesNotExistException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function manageRequest(RequestQueue $queue) {

		try {
			$this->queueService->initRequest($queue);
		} catch (QueueStatusException $e) {
			return;
		}

		$result = $this->generateRequest(
			$queue->getInstance(), $queue->getActivity(), $queue->getAuthor()
		);

		try {
			if ($this->getint('_code', $result, 500) === 202) {
				$this->queueService->endRequest($queue, true);
			} else {
				$this->queueService->endRequest($queue, false);
			}
		} catch (QueueStatusException $e) {
		}
	}


	/**
	 * @param ACore $activity
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePaths(ACore $activity): array {
		$instancePaths = [];
		foreach ($activity->getInstancePaths() as $instancePath) {
			if ($instancePath->getType() === InstancePath::TYPE_FOLLOWERS) {
				$instancePaths = array_merge(
					$instancePaths, $this->generateInstancePathsFollowers($instancePath)
				);
			} else {
				$instancePaths[] = $instancePath;
			}
		}

		return $instancePaths;
	}


	/**
	 * @param InstancePath $instancePath
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePathsFollowers(InstancePath $instancePath): array {
		$follows = $this->followsRequest->getByFollowId($instancePath->getUri());

		$sharedInboxes = [];
		$instancePaths = [];
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
			$instancePaths[] = new InstancePath(
				$sharedInbox, InstancePath::TYPE_GLOBAL, $instancePath->getPriority()
			);
//			$result[] = $this->generateRequest(
//				new InstancePath($sharedInbox, InstancePath::TYPE_GLOBAL), $activity
//			);
		}

		return $instancePaths;
	}


	/**
	 * @param InstancePath $path
	 * @param string $activity
	 * @param string $author
	 *
	 * @return Request[]
	 * @throws ActorDoesNotExistException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function generateRequest(InstancePath $path, string $activity, string $author): array {
//		$document = json_encode($activity);
		$date = gmdate(self::DATE_FORMAT);
		$localActor = $this->getActorFromAuthor($author);

		$localActorLink =
			$this->configService->getUrlSocial() . '@' . $localActor->getPreferredUsername();
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

		$request->setDataJson($activity);
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
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws SignatureIsGoneException
	 */
	public function checkRequest(IRequest $request) {
		$dTime = new DateTime($request->getHeader('date'));
		$dTime->format(self::DATE_FORMAT);

		if ($dTime->getTimestamp() < (time() - self::DATE_DELAY)) {
			throw new SignatureException('object is too old');
		}

		try {
			$this->checkSignature($request);
		} catch (Request410Exception $e) {
			throw new SignatureIsGoneException();
		}

	}


	/**
	 * @param ACore $activity
	 *
	 * @return string
	 */
	private function getAuthorFromItem(Acore $activity): string {
		if ($activity->gotActor()) {
			return $activity->getActor()
							->getId();
		}

		return $activity->getActorId();
	}


	/**
	 * @param string $author
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	private function getActorFromAuthor(string $author): Person {
		return $this->actorService->getActorById($author);
	}


	/**
	 * @param IRequest $request
	 *
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws Request410Exception
	 * @throws RequestException
	 * @throws SignatureException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
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
	 * @throws InvalidResourceException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws Request410Exception
	 */
	private function retrieveKey($keyId): string {
		$actor = $this->personService->getFromId($keyId);

		return $actor->getPublicKey();
	}


	/**
	 * @deprecated !??? - do we need this !?
	 *
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

