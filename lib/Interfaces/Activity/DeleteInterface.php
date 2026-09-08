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
	/** The types a deleted id can refer to, in the order they are looked up. */
	private const DELETABLE_TYPES = ['Note', 'Person'];

	/**
	 * @throws InvalidOriginException
	 */
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getObjectId());

		if ($item->hasObject()) {
			$object = $item->getObject();
			try {
				$interface = AP::$activityPub->getInterfaceForItem($object);
				$interface->activity($item, $object);

				return;
			} catch (ItemUnknownException $e) {
				// A Tombstone, or any other object type this app has no handler for.
				// Its id is all that is needed to remove what it points at, so fall
				// through to the lookup below.
			}
		}

		$this->deleteById($item->getObjectId());
	}

	private function deleteById(string $objectId): void {
		foreach (self::DELETABLE_TYPES as $type) {
			try {
				$interface = AP::$activityPub->getInterfaceFromType($type);
				$object = $interface->getItemById($objectId);
				$interface->delete($object);

				return;
			} catch (ItemNotFoundException $e) {
			} catch (ItemUnknownException $e) {
			}
		}
	}
}
