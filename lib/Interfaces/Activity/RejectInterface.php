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
use OCA\Social\Model\ActivityPub\Object\Follow;
use Psr\Log\LoggerInterface;

class RejectInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ActivityObjectResolver $objectResolver,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws InvalidOriginException the follow was not addressed to this actor
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		// see AcceptInterface: a link rather than an embedded object is normal
		// outside Mastodon, and used to be dropped
		try {
			$object = $this->objectResolver->resolve($item);
		} catch (ItemNotFoundException $e) {
			$this->logger->notice('Reject refers to an unknown object', [
				'activity' => $item->getId(),
				'object' => $item->getObjectId(),
				'origin' => $item->getOrigin(),
			]);

			return;
		}

		// A follow request is answered by the account it was addressed to and by
		// nobody else. The interface below checks the host of that account,
		// which on a shared server is every account on it — so any of them
		// could reject a request meant for a neighbour.
		if ($object instanceof Follow) {
			$item->checkActor($object->getObjectId(), $item->getActorId());
		}

		try {
			$service = AP::instance()->getInterfaceForItem($object);
			$service->activity($item, $object);
		} catch (ItemUnknownException $e) {
		}
	}
}
