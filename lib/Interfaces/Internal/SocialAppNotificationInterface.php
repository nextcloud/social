<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Internal;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\MiscService;

class SocialAppNotificationInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	private StreamRequest $streamRequest;
	private MiscService $miscService;

	public function __construct(StreamRequest $streamRequest, MiscService $miscService) {
		$this->streamRequest = $streamRequest;
		$this->miscService = $miscService;
	}

	public function save(ACore $item): void {
		/** @var SocialAppNotification $notification */
		$notification = $item;
		if ($notification->getId() === '') {
			return;
		}

		$notification->setPublished(date("c"));
		$notification->convertPublished();

		$this->miscService->log(
			'Generating notification: ' . json_encode($notification, JSON_UNESCAPED_SLASHES), 1
		);
		$this->streamRequest->save($notification);
	}

	public function update(ACore $item): void {
		/** @var SocialAppNotification $notification */
		$notification = $item;
		$this->miscService->log(
			'Updating notification: ' . json_encode($notification, JSON_UNESCAPED_SLASHES), 1
		);
		$this->streamRequest->update($notification, true);
	}

	public function delete(ACore $item): void {
		/** @var Stream $item */
		$this->streamRequest->deleteById($item->getId(), SocialAppNotification::TYPE);
	}
}
