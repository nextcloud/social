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
use OCA\Social\Model\ActivityPub\Stream;

class DeleteInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	/** The types a deleted id can refer to, in the order they are looked up. */
	private const DELETABLE_TYPES = ['Note', 'Person'];

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getObjectId());

		if ($item->hasObject()) {
			$object = $item->getObject();
			try {
				$interface = AP::instance()->getInterfaceForItem($object);
				$interface->activity($item, $object);

				return;
			} catch (ItemUnknownException $e) {
				// A Tombstone, or any other object type this app has no handler for.
				// Its id is all that is needed to remove what it points at, so fall
				// through to the lookup below.
			}
		}

		$this->deleteById($item->getObjectId(), $item->getActorId());
	}

	/**
	 * @throws InvalidOriginException the id names a stored post of someone else
	 */
	private function deleteById(string $objectId, string $actorId): void {
		foreach (self::DELETABLE_TYPES as $type) {
			try {
				$interface = AP::instance()->getInterfaceFromType($type);
				$object = $interface->getItemById($objectId);
			} catch (ItemNotFoundException $e) {
				continue;
			} catch (ItemUnknownException $e) {
				continue;
			}

			// The origin check stops at the host. A post is removed only on its
			// author's word — Mastodon looks a deleted status up by uri *and*
			// account — or any user of the author's server could take it down.
			// An actor deleting itself is not a Stream and is matched by origin
			// as before.
			if ($object instanceof Stream && $object->getAttributedTo() !== $actorId) {
				throw new InvalidOriginException(
					'DeleteInterface::deleteById - actor: ' . $actorId
					. ' - attributedTo: ' . $object->getAttributedTo()
				);
			}

			$interface->delete($object);

			return;
		}
	}
}
