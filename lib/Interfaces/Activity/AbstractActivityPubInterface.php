<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;

class AbstractActivityPubInterface implements IActivityPubInterface {
	public function processIncomingRequest(ACore $item): void {
	}

	public function processResult(ACore $item): void {
	}

	/**
	 * @throws ItemNotFoundException
	 */
	public function getItem(ACore $item): ACore {
		throw new ItemNotFoundException();
	}

	/**
	 * @throws ItemNotFoundException
	 */
	public function getItemById(string $id): ACore {
		throw new ItemNotFoundException();
	}

	public function save(ACore $item): void {
	}

	public function update(ACore $item): void {
	}

	public function delete(ACore $item): void {
	}

	public function event(ACore $item, string $source): void {
	}

	public function activity(ACore $activity, ACore $item): void {
	}
}
