<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the followers' servers once more about the local accounts named
 * apart from their login, so they read the icon again.
 *
 * The icon of a local account used to be Nextcloud's avatar route, which
 * names the login; it is now this app's route of the username, and a server
 * that copied the actor keeps the old address until it is told. Only the
 * accounts whose username is not the login gave anything away, so only they
 * are told. `PAGE` accounts a run, and a run queues the next one with where
 * it got to; queued once, by `Migration\RepublishAvatarAddresses`, so it is
 * not registered in `info.xml`.
 */
class RepublishAvatars extends QueuedJob {
	public const PAGE = 50;

	public function __construct(
		ITimeFactory $time,
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$after = is_array($argument) ? (string)($argument['after'] ?? '') : '';
		$page = $this->actorsRequest->getPage(self::PAGE, $after);

		foreach ($page as $actor) {
			$after = ActorsRequest::rowPrimOf($actor);
			$userId = $actor->getUserId();
			if ($actor->getDeleted() > 0 || $userId === ''
				|| str_starts_with($userId, 'team/') || str_starts_with($userId, 'channel/')
				|| strtolower($userId) === strtolower($actor->getPreferredUsername())) {
				continue;
			}

			try {
				$this->accountService->republishActor($actor);
			} catch (Throwable $e) {
				// one account that cannot be told is no reason to leave the rest
				$this->logger->warning('could not tell the followers the new avatar address', [
					'actor' => $actor->getId(), 'exception' => $e,
				]);
			}
		}

		if (count($page) === self::PAGE) {
			$this->jobList->add(self::class, ['after' => $after]);
		}
	}
}
