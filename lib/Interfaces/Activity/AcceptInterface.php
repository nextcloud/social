<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\AP;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use Psr\Log\LoggerInterface;

class AcceptInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ActivityObjectResolver $objectResolver,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		// An Accept whose `object` is a link used to be dropped, which left the
		// follow pending forever: no posts arrive, and a later unfollow sends an
		// Undo for a follow the peer thinks it granted.
		try {
			$object = $this->objectResolver->resolve($item);
		} catch (ItemNotFoundException $e) {
			$this->logger->notice('Accept refers to an unknown object', [
				'activity' => $item->getId(),
				'object' => $item->getObjectId(),
				'origin' => $item->getOrigin(),
			]);

			return;
		}

		try {
			$service = AP::instance()->getInterfaceForItem($object);
			$service->activity($item, $object);
		} catch (ItemUnknownException $e) {
		}
	}
}
