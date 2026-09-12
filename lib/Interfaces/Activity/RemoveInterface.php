<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;

class RemoveInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private FeaturedCollection $featuredCollection,
	) {
	}

	/**
	 * `Remove{object, target}` where target is the actor's `featured`
	 * collection is an unpin. See FeaturedCollection.
	 *
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		$this->featuredCollection->toggle($item, false);
	}
}
