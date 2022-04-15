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


namespace OCA\Social\Interfaces;

use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * Interface ICoreService
 *
 * @package OCA\Social\Service
 */
interface IActivityPubInterface {

	/**
	 * Freshly imported item can be processed/parsed on incoming Request.
	 */
	public function processIncomingRequest(ACore $item): void;


	/**
	 * Freshly imported item can be processed/parsed on result of outgoing request.
	 */
	public function processResult(ACore $item): void;


	/**
	 * When an activity is triggered by an 'Model\ActivityPub\Activity' model.
	 *
	 * !! This should be the only way of interaction between 2 IActivityPubInterface !!
	 */
	public function activity(ACore $activity, ACore $item): void;


	/**
	 * Get Item by its Id.
	 * @throws ItemNotFoundException
	 */
	public function getItemById(string $id): ACore;


	/**
	 * Get Item when Id is not known.
	 * @throws ItemNotFoundException
	 */
	public function getItem(ACore $item): ACore;


	/**
	 * Save the current item.
	 *
	 * !! Should not be called from an other IActivityPubInterface !!
	 *
	 * @throws ItemAlreadyExistsException
	 */
	public function save(ACore $item): void;


	/**
	 * Update the current item.
	 *
	 * !! Should not be called from an other IActivityPubInterface !!
	 *
	 * @throws ItemNotFoundException
	 */
	public function update(ACore $item): void;


	/**
	 * Event on the current item.
	 *
	 * !! Should not be called from an other IActivityPubInterface !!
	 */
	public function event(ACore $item, string $source): void;


	/**
	 * Delete the current item.
	 *
	 * !! Should not be called from an other IActivityPubInterface !!
	 */
	public function delete(ACore $item): void;
}
