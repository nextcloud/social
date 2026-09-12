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
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
	}

	#[\Override]
	public function processResult(ACore $item): void {
	}

	/**
	 * @throws ItemNotFoundException
	 */
	#[\Override]
	public function getItem(ACore $item): ACore {
		throw new ItemNotFoundException();
	}

	/**
	 * @throws ItemNotFoundException
	 */
	#[\Override]
	public function getItemById(string $id): ACore {
		throw new ItemNotFoundException();
	}

	#[\Override]
	public function save(ACore $item): void {
	}

	#[\Override]
	public function update(ACore $item): void {
	}

	#[\Override]
	public function delete(ACore $item): void {
	}

	#[\Override]
	public function event(ACore $item, string $source): void {
	}

	#[\Override]
	public function activity(ACore $activity, ACore $item): void {
	}
}
