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
	 *
	 * @param ACore $item
	 */
	public function processIncomingRequest(ACore $item);


	/**
	 * Freshly imported item can be processed/parsed on result of outgoing request.
	 *
	 * @param ACore $item
	 */
	public function processResult(ACore $item);


	/**
	 * When an activity is triggered by an 'Model\ActivityPub\Activity' model.
	 *
	 * !! This should be the only way of interaction between 2 IActivityPubInterface !!
	 *
	 * @param ACore $activity
	 * @param ACore $item
	 */
	public function activity(ACore $activity, ACore $item);


	/**
	 * get Item by its Id.
	 *
	 * @param string $id
	 *
	 * @return ACore
	 * @throws ItemNotFoundException
	 */
	public function getItemById(string $id): ACore;


	/**
	 * get Item when Id is not known.
	 *
	 * @param ACore $item
	 *
	 * @return ACore
	 * @throws ItemNotFoundException
	 */
	public function getItem(ACore $item): ACore;


	/**
	 * Save the current item.
	 *
	 * !! Should not be called from an other IActivityPubInterface !!
	 *
	 * @param ACore $item
	 * @throws ItemAlreadyExistsException
	 */
	public function save(ACore $item);


	/**
	 * Update the current item.
	 *
	 * !! Should not be called from an other IActivityPubInterface !!
	 *
	 * @param ACore $item
	 * @throws ItemNotFoundException
	 */
	public function update(ACore $item);


	/**
	 * Event on the current item.
	 *
	 * !! Should not be called from an other IActivityPubInterface !!
	 *
	 * @param ACore $item
	 * @param string $source
	 */
	public function event(ACore $item, string $source);


	/**
	 * Delete the current item.
	 *
	 * !! Should not be called from an other IActivityPubInterface !!
	 *
	 * @param ACore $item
	 */
	public function delete(ACore $item);

}

