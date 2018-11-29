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


use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OC\User\NoUserException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\FollowDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Service\ActivityPub\PersonService;


/**
 * Class ActorService
 *
 * @package OCA\Social\Service
 */
class ActorService {


	use TArrayTools;


	/** @var ActorsRequest */
	private $actorsRequest;

	/** @var FollowsRequest */
	private $followsRequest;

	/** @var NotesRequest */
	private $notesRequest;

	/** @var PersonService */
	private $personService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActorService constructor.
	 *
	 * @param ActorsRequest $actorsRequest
	 * @param FollowsRequest $followsRequest
	 * @param NotesRequest $notesRequest
	 * @param PersonService $personService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActorsRequest $actorsRequest, FollowsRequest $followsRequest, NotesRequest $notesRequest,
		PersonService $personService, ConfigService $configService, MiscService $miscService
	) {
		$this->actorsRequest = $actorsRequest;
		$this->followsRequest = $followsRequest;
		$this->notesRequest = $notesRequest;
		$this->personService = $personService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $username
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */

	public function getActor(string $username): Person {
		$actor = $this->actorsRequest->getFromUsername($username);

		return $actor;
	}

	/**
	 * @param string $id
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getActorById(string $id): Person {
		$actor = $this->actorsRequest->getFromId($id);

		return $actor;
	}


	/**
	 * @param string $userId
	 * @param bool $create
	 *
	 * @return Person
	 * @throws AccountAlreadyExistsException
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 */
	public function getActorFromUserId(string $userId, bool $create = false): Person {
		$this->miscService->confirmUserId($userId);
		try {
			$actor = $this->actorsRequest->getFromUserId($userId);
		} catch (ActorDoesNotExistException $e) {
			if ($create) {
				$this->createActor($userId, $userId);
				$actor = $this->actorsRequest->getFromUserId($userId);
			} else {
				throw new ActorDoesNotExistException();
			}
		}

		return $actor;
	}


	/**
	 * Method should be called by the frontend and will generate a fresh Social account for
	 * the user, using the userId and the username.
	 *
	 * Pair of keys are created at this point.
	 *
	 * Return exceptions if an account already exist for this user or if the username is already
	 * taken
	 *
	 * @param string $userId
	 * @param string $username
	 *
	 * @throws AccountAlreadyExistsException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 */
	public function createActor(string $userId, string $username) {

		$this->miscService->confirmUserId($userId);
		$this->checkActorUsername($username);

		try {
			$this->actorsRequest->getFromUsername($username);
			throw new AccountAlreadyExistsException('actor with that name already exist');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		try {
			$this->actorsRequest->getFromUserId($userId);
			throw new AccountAlreadyExistsException('account for this user already exist');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		$this->configService->setCoreValue('public_webfinger', 'social/lib/webfinger.php');

		$actor = new Person();
		$actor->setUserId($userId);
		$actor->setPreferredUsername($username);

		$this->generateKeys($actor);
		$this->actorsRequest->create($actor);

		// generate cache.
		$this->cacheLocalActorByUsername($username, true);
	}


	/**
	 * @param Person $local
	 * @param Person $actor
	 *
	 * @return array
	 */
	public function getLinksBetweenPersons(Person $local, Person $actor): array {

		$links = [
			'follower'  => false,
			'following' => false
		];

		try {
			$this->followsRequest->getByPersons($local->getId(), $actor->getId());
			$links['following'] = true;
		} catch (FollowDoesNotExistException $e) {
		}

		try {
			$this->followsRequest->getByPersons($actor->getId(), $local->getId());
			$links['follower'] = true;
		} catch (FollowDoesNotExistException $e) {
		}

		return $links;
	}


	/**
	 * @param string $username
	 * @param bool $refresh
	 *
	 * @throws SocialAppConfigException
	 */
	public function cacheLocalActorByUsername(string $username, bool $refresh = false) {
		try {
			$actor = $this->getActor($username);;
			$count = [
				'followers', $this->followsRequest->countFollowers($actor->getId()),
				'following', $this->followsRequest->countFollowing($actor->getId()),
				'post', $this->notesRequest->countNotesFromActorId($actor->getId())
			];
			$actor->addDetailArray('count', $count);

			$this->personService->cacheLocalActor($actor, $refresh);
		} catch (ActorDoesNotExistException $e) {
		}
	}


	/**
	 * @param $username
	 */
	private function checkActorUsername($username) {
		$accepted = 'qwertyuiopasdfghjklzxcvbnm';

		return;
	}


	/**
	 * @param Person $actor
	 */
	private function generateKeys(Person &$actor) {
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
	 * @throws Exception
	 * @return int
	 */
	public function manageCacheLocalActors(): int {
		$update = $this->actorsRequest->getAll();

		foreach ($update as $item) {
			try {
				$this->cacheLocalActorByUsername($item->getPreferredUsername(), true);
			} catch (Exception $e) {
			}
		}

		return sizeof($update);
	}


}
