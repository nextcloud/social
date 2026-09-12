<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\AP;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Finishes detaching a deleted account from the posts that addressed it.
 *
 * Queued by `PersonInterface::delete()` when more posts name the account than
 * one request should rewrite, so — like `Cron\DomainPurge` and unlike `Cache`
 * and `Queue` — it is not registered in `info.xml`: a job listed there is added
 * once at install time with no argument, and this one is meaningless without an
 * actor.
 *
 * **Why it exists at all.** The deletion arrives as a `Delete` in an inbox
 * request that a peer is waiting on. Rewriting every post that ever addressed a
 * long-lived account does not fit in that request, and the failure is not
 * merely slow: the peer times out and re-sends the `Delete`, so the work starts
 * again from the beginning — for ever, on exactly the accounts that have the
 * most of it.
 *
 * **Why it re-queues itself.** Same reason the domain purge does. A pass does a
 * bounded number of rows and puts itself back on the list while any remain,
 * and each pass finds its own work by asking what is still stored, so a pass
 * that dies costs only its own progress.
 */
class ActorCleanup extends QueuedJob {
	/**
	 * Rows per run. Each one may read a post and write it back, so this is a
	 * bound on the cron slot rather than on the work: what is left is the next
	 * run's.
	 */
	private const ROWS_PER_RUN = 2000;

	public function __construct(
		ITimeFactory $time,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$actorId = is_array($argument) ? (string)($argument['actor'] ?? '') : '';
		if ($actorId === '') {
			return;
		}

		$actor = new Person();
		$actor->setId($actorId);

		try {
			/** @var PersonInterface $interface */
			$interface = AP::instance()->getInterfaceFromType(Person::TYPE);
			if ($interface->detachRecipient($actor, self::ROWS_PER_RUN)) {
				$interface->forgetRecipient($actorId);
				$this->logger->info('[Cron\\ActorCleanup] detached a deleted account', [
					'actor' => $actorId,
				]);

				return;
			}

			$this->jobList->add(self::class, ['actor' => $actorId]);
		} catch (\Throwable $e) {
			// not retried for ever: the rows it did rewrite stay rewritten, and
			// what is left is a post addressed to an account that is gone —
			// which the visibility filter already treats as nobody
			$this->logger->warning('[Cron\\ActorCleanup] could not finish detaching an account', [
				'actor' => $actorId, 'exception' => $e,
			]);
		}
	}
}
