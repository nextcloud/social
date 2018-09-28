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


use daita\Traits\TArrayTools;
use Exception;
use OC\User\NoUserException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\ActorAlreadyExistsException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\APIRequestException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidAccessTokenException;
use OCA\Social\Exceptions\MovedPermanentlyException;
use OCA\Social\Model\ActivityPub\Actor;

class ActorService {


	use TArrayTools;


	/** @var UriIdService */
	private $uriIdService;

	/** @var ConfigService */
	private $configService;

	/** @var ActorsRequest */
	private $actorsRequest;

	/** @var CacheActorsRequest */
	private $cacheActorsRequest;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActivityStreamsService constructor.
	 *
	 * @param ActorsRequest $actorsRequest
	 * @param CacheActorsRequest $cacheActorsRequest
	 * @param UriIdService $uriIdService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActorsRequest $actorsRequest,
		CacheActorsRequest $cacheActorsRequest, UriIdService $uriIdService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->configService = $configService;
		$this->uriIdService = $uriIdService;
		$this->actorsRequest = $actorsRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $username
	 *
	 * @return Actor
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 */
	public function getActor(string $username): Actor {
		$actor = $this->actorsRequest->getFromUsername($username);

		return $actor;
	}


	/**
	 * @param string $userId
	 *
	 * @return Actor
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 */
	public function getActorFromUserId(string $userId): Actor {
		$this->miscService->confirmUserId($userId);
		$actor = $this->actorsRequest->getFromUserId($userId);

		return $actor;
	}


	/**
	 * @param string $uriId
	 *
	 * @return Actor
	 * @throws APIRequestException
	 * @throws InvalidAccessTokenException
	 * @throws MovedPermanentlyException
	 * @throws Exception
	 */
	public function getFromUri(string $uriId) {

		try {
			$cache = $this->cacheActorsRequest->getFromUrl($uriId);

			return $this->generateActor($cache->getActor());
		} catch (CacheActorDoesNotExistException $e) {
			$object = $this->uriIdService->retrieveObject($uriId);
			$actor = $this->generateActor($object);
			$this->cacheActorsRequest->create($actor, $object);

			return $actor;
		}
	}


	/**
	 * @param array $object
	 *
	 * @return Actor
	 */
	public function generateActor(array $object) {
		$actor = new Actor();

		$actor->setId($this->get('id', $object));
		$actor->setFollowers($this->get('followers', $object));
		$actor->setFollowing($this->get('following', $object));
		$actor->setInbox($this->get('inbox', $object));
		$actor->setOutbox($this->get('outbox', $object));
		$actor->setPublicKey($object['publicKey']['publicKeyPem']);
		$actor->setPreferredUsername($this->get('preferredUsername', $object));
		$actor->setAccount('@' . $actor->getPreferredUsername() . '@' . $object['_address']);

//		$actor->setSharedInbox($this->get(''))

		return $actor;
	}


	/**
	 * @param string $userId
	 *
	 * @throws ActorAlreadyExistsException
	 * @throws NoUserException
	 * @throws Exception
	 */
	public function createActor(string $userId, string $username) {

		$this->miscService->confirmUserId($userId);
		$this->checkActorUsername($username);
		$this->configService->setCoreValue('public_webfinger', 'social/lib/webfinger.php');

		try {
			$this->actorsRequest->getFromUsername($username);
			throw new ActorAlreadyExistsException('actor with that name already exist');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		try {
			$this->actorsRequest->getFromUserId($userId);
			throw new ActorAlreadyExistsException('account for this user already exist');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		$actor = new Actor();
		$actor->setUserId($userId);
		$actor->setPreferredUsername($username);

		$this->generateKeys($actor);
		$this->actorsRequest->create($actor);
	}


	/**
	 * @param $username
	 *
	 * @return bool
	 */
	private function checkActorUsername($username) {
		return;
	}

	/**
	 * @param Actor $actor
	 */
	private function generateKeys(Actor &$actor) {
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


}
