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
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Model\ActivityPub\Follow;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Service\ICoreService;
use OCA\Social\Service\MiscService;


class FollowService implements ICoreService {


	/** @var FollowsRequest */
	private $followsRequest;

	/** @var MiscService */
	private $miscService;


	/**
	 * NoteService constructor.
	 *
	 * @param FollowsRequest $followsRequest
	 * @param MiscService $miscService
	 */
	public function __construct(FollowsRequest $followsRequest, MiscService $miscService) {
		$this->followsRequest = $followsRequest;
		$this->miscService = $miscService;
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

