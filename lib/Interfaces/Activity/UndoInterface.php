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

class UndoInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ActivityObjectResolver $objectResolver,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		// `object` may be a link rather than an embedded object: Mastodon embeds,
		// most others do not, and returning here meant an Undo from those peers
		// undid nothing.
		try {
			$object = $this->objectResolver->resolve($item);
		} catch (ItemNotFoundException $e) {
			$this->logger->notice('Undo refers to an unknown object', [
				'activity' => $item->getId(),
				'object' => $item->getObjectId(),
				'origin' => $item->getOrigin(),
			]);

			return;
		}

		try {
			$interface = AP::instance()->getInterfaceForItem($object);
			$interface->activity($item, $object);
		} catch (ItemUnknownException $e) {
		}
	}
}
