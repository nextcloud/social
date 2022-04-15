<?php

declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
		$this->streamRequest->update($notification);
	}

	public function delete(ACore $item): void {
		/** @var Stream $item */
		$this->streamRequest->deleteById($item->getId(), SocialAppNotification::TYPE);
	}
}
