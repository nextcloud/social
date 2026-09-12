<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\AP;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;

class UpdateInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		if (!$item->hasObject()) {
			return;
		}
		$object = $item->getObject();

		try {
			$service = AP::instance()->getInterfaceForItem($item->getObject());
			$service->activity($item, $object);
		} catch (ItemUnknownException $e) {
		}
	}
}
