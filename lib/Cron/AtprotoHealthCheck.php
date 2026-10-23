<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\AtprotoStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

class AtprotoHealthCheck extends TimedJob {
	private const INTERVAL = 15 * 60;

	public function __construct(
		ITimeFactory $time,
		private AtprotoConfig $config,
		private AtprotoStatusService $status,
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
		try {
			$checks = $this->status->checks(true);
			$errors = array_values(array_filter($checks, static fn (array $check): bool => $check['state'] === 'error'));
			if ($errors !== []) {
				$this->logger->warning('Bluesky service requirements are failing', ['checks' => $errors]);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Public Bluesky reachability checks failed', ['exception' => $e]);
		}
	}
}
