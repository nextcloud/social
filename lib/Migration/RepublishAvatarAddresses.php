<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Cron\RepublishAvatars;
use OCA\Social\Service\ConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Queues `Cron\RepublishAvatars`, which tells the followers' servers the new
 * avatar address of every local account named apart from its login, off the
 * upgrade. The marker keeps a later upgrade from queueing it again.
 */
class RepublishAvatarAddresses implements IRepairStep {
	private const MARKER = 'migration_avatar_addresses_republished';

	public function __construct(
		private IJobList $jobList,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Tell other servers the new avatar address of accounts named apart from their login';
	}

	#[\Override]
	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt(self::MARKER) === 1) {
			return;
		}

		$this->jobList->add(RepublishAvatars::class, ['after' => '']);
		$this->configService->setAppValue(self::MARKER, '1');
		$output->info('queued the new avatar addresses to be told to other servers');
	}
}
