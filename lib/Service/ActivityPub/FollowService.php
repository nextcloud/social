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


namespace OCA\Social\Service\ActivityPub;


use Exception;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\FollowDoesNotExistException;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Follow;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ICoreService;
use OCA\Social\Service\MiscService;


class FollowService implements ICoreService {


	/** @var FollowsRequest */
	private $followsRequest;

	/** @var PersonService */
	private $personService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * NoteService constructor.
	 *
	 * @param FollowsRequest $followsRequest
	 * @param PersonService $personService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		FollowsRequest $followsRequest, PersonService $personService, ConfigService $configService,
		MiscService $miscService
	) {
		$this->followsRequest = $followsRequest;
		$this->personService = $personService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $actor
	 * @param string $account
	 *
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function followAccount(Person $actor, string $account) {
		$remoteActor = $this->personService->getFromAccount($account);
		$follow = new Follow();
		$follow->generateUniqueId($this->configService->getCloudAddress());
		$follow->setActorId($actor->getId());
		$follow->setObjectId($remoteActor->getId());

		try {
			$this->followsRequest->getByPersons($actor, $remoteActor);
		} catch (FollowDoesNotExistException $e) {
			$this->followsRequest->save($follow);
		}
	}


	/**
	 * @param Person $actor
	 * @param string $account
	 *
	 * @throws RequestException
	 */
	public function unfollowAccount(Person $actor, string $account) {
		$remoteActor = $this->personService->getFromAccount($account);

		try {
			$follow = $this->followsRequest->getByPersons($actor, $remoteActor);
			$this->followsRequest->delete($follow);
		} catch (FollowDoesNotExistException $e) {
		}
	}


	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	public function getFollowers(Person $actor): OrderedCollection {
		$collection = new OrderedCollection();
		$collection->setId($actor->getFollowers());
		$collection->setTotalItems(20);
		$collection->setFirst('...');

		return $collection;
	}


	/**
	 * This method is called when saving the Follow object
	 *
	 * @param ACore $follow
	 *
	 * @throws Exception
	 */
	public function save(ACore $follow) {
		/** @var Follow $follow */

		if ($follow->getMetaBool('Undo') === false) {
			$this->followsRequest->save($follow);
		} else {
			$this->followsRequest->delete($follow);
		}
	}


}

