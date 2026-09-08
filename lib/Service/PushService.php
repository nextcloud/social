<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Tells connected web clients about new timeline entries through the
 * notify_push app, so they refresh immediately instead of on their next
 * poll. Entirely optional: when notify_push is not installed every call is
 * a cheap no-op and clients keep polling.
 */
class PushService {
	/** the custom notify_push event name web clients listen on */
	public const EVENT = 'social_timeline';

	private const QUEUE_CLASS = 'OCA\\NotifyPush\\Queue\\IQueue';

	private bool $probed = false;
	private ?object $queue = null;

	public function __construct(
		private DetailsService $detailsService,
		private StreamService $streamService,
		private LoggerInterface $logger,
	) {
	}

	public function onNewStream(string $streamId): void {
		$queue = $this->getQueue();
		if ($queue === null) {
			return;
		}

		try {
			$stream = $this->streamService->getStreamById($streamId);
		} catch (StreamNotFoundException $e) {
			return;
		}

		try {
			$details = $this->detailsService->generateDetailsFromStream($stream);
		} catch (Exception $e) {
			$this->logger->debug('no viewer details for push', ['exception' => $e]);

			return;
		}

		$userIds = [];
		foreach (array_merge($details->getHomeViewers(), $details->getDirectViewers()) as $viewer) {
			/** @var Person $viewer */
			if ($viewer->isLocal() && $viewer->getUserId() !== '') {
				$userIds[$viewer->getUserId()] = true;
			}
		}

		foreach (array_keys($userIds) as $userId) {
			try {
				$queue->push('notify_custom', [
					'user' => (string)$userId,
					'message' => self::EVENT,
				]);
			} catch (Exception $e) {
				$this->logger->debug('notify_push delivery failed', ['exception' => $e]);

				return;
			}
		}
	}

	/**
	 * The notify_push queue, when that app is installed — probed once per
	 * request. Overridable for tests.
	 */
	protected function getQueue(): ?object {
		if (!$this->probed) {
			$this->probed = true;
			try {
				if (class_exists(self::QUEUE_CLASS)) {
					$this->queue = Server::get(self::QUEUE_CLASS);
				}
			} catch (Exception $e) {
				$this->queue = null;
			}
		}

		return $this->queue;
	}
}
