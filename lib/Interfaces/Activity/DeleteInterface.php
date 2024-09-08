<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;

class DeleteInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	/**
	 * @throws InvalidOriginException
	 */
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getObjectId());

		if (!$item->hasObject()) {
			$types = ['Note', 'Person'];
			foreach ($types as $type) {
				try {
					$interface = AP::$activityPub->getInterfaceFromType($type);
					$object = $interface->getItemById($item->getObjectId());
					$interface->delete($object);

					return;
				} catch (ItemNotFoundException $e) {
				} catch (ItemUnknownException $e) {
				}
			}

			return;
		}

		$object = $item->getObject();
		try {
			$interface = AP::$activityPub->getInterfaceForItem($object);
			$interface->activity($item, $object);
		} catch (ItemUnknownException $e) {
		}
	}
}
