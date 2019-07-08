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


namespace OCA\Social\Interfaces\Object;


use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\MiscService;


class ImageInterface extends DocumentInterface implements IActivityPubInterface {


	/**
	 * DocumentInterface constructor.
	 *
	 * @param CacheDocumentsRequest $cacheDocumentsRequest
	 * @param MiscService $miscService
	 */
	public function __construct(
		CacheDocumentsRequest $cacheDocumentsRequest, MiscService $miscService
	) {
		parent::__construct($cacheDocumentsRequest, $miscService);
	}


	/**
	 * @param ACore $activity
	 * @param ACore $item
	 */
	public function activity(Acore $activity, ACore $item) {
		parent::activity($activity, $item);
	}


	/**
	 * @param ACore $item
	 */
	public function processIncomingRequest(ACore $item) {
		parent::processIncomingRequest($item);
	}


	/**
	 * @param ACore $item
	 */
	public function processResult(ACore $item) {
		parent::processResult($item);
	}


	/**
	 * @param ACore $item
	 *
	 * @return ACore
	 * @throws ItemNotFoundException
	 */
	public function getItem(ACore $item): ACore {
		throw new ItemNotFoundException();
	}


	/**
	 * @param string $id
	 *
	 * @return ACore
	 * @throws ItemNotFoundException
	 */
	public function getItemById(string $id): ACore {
		return parent::getItemById($id);
	}

	/**
	 * @param ACore $item
	 */
	public function save(ACore $item) {
		parent::save($item);
	}


	/**
	 * @param ACore $item
	 */
	public function update(ACore $item) {
		parent::update($item);
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
		parent::delete($item);
	}


	/**
	 * @param ACore $item
	 * @param string $source
	 */
	public function event(ACore $item, string $source) {
		parent::event($item, $source);
	}


}

