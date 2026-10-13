<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Chat\ChatPoller;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\NotificationPoller;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads Bluesky every two minutes: the feeds of the authors somebody here
 * follows, then what Bluesky did to local accounts, then the direct
 * messages they received, as many of each as are due within the request
 * ceiling and the pass's wall-clock budget — a slow AppView ends the pass
 * at the budget, and what is still due is read on the next one.
 */
class AtprotoSync extends TimedJob {
	private const INTERVAL = 2 * 60;
	/**
	 * How long one pass may spend on the three steps, in seconds: well
	 * inside the interval, so two passes cannot meet, the way `Cron\Cache`
	 * budgets its own. Each step takes what is due and checks the clock
	 * between accounts, so nothing is lost when it runs out.
	 */
	public const MAX_DURATION = 90;

	public function __construct(
		ITimeFactory $time,
		private AtprotoConfig $config,
		private FeedPoller $poller,
		private NotificationPoller $notifications,
		private ChatPoller $chat,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		if (!$this->config->isEnabled()) {
			return;
		}
		$deadline = $this->time->getTime() + self::MAX_DURATION;
		$steps = [
			'feeds' => fn (): array => $this->poller->poll(FeedPoller::BATCH, $deadline),
			'notifications' => fn (): array => $this->notifications->poll(NotificationPoller::BATCH, $deadline),
			'direct messages' => fn (): array => $this->chat->poll(ChatPoller::BATCH, $deadline),
		];
		foreach ($steps as $step => $run) {
			if ($this->time->getTime() >= $deadline) {
				$this->logger->warning('Bluesky sync out of time after ' . self::MAX_DURATION . 's; ' . $step . ' waits for the next run');
				continue;
			}
			try {
				$result = $run();
				if (($result['stored'] ?? 0) > 0 || ($result['handled'] ?? 0) > 0) {
					$this->logger->info('Bluesky ' . $step . ' read', $result);
				}
			} catch (Throwable $e) {
				$this->logger->warning('Bluesky sync step failed: ' . $step, ['exception' => $e]);
			}
		}
	}
}
