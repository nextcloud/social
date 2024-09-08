<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
