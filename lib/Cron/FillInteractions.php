<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\Interaction\InteractionService;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Reads who liked, boosted or quoted one post on the networks it lives on
 * (`InteractionService::fill()`). Queued by `RemoteFetchQueue::fillInteractions()`
 * when somebody looks, so — like `FillThread` — it is not registered in `info.xml`.
 */
class FillInteractions extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private InteractionService $interactions,
		private RemoteFetchQueue $queue,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$post = is_array($argument) ? (string)($argument['post'] ?? '') : '';
		$type = is_array($argument) ? (string)($argument['type'] ?? '') : '';
		if ($post !== '' && $type !== '') {
			if ($this->interactions->fill($post, $type)) {
				// A later collection page may have failed or remain; allow the next
				// visible poll to queue another bounded continuation.
				$this->queue->completeInteractionFill($post, $type);
			}
		}
	}
}
