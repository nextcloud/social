<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\CacheActorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches and caches one remote actor a page needed and did not have.
 *
 * Queued with the actor id by `RemoteFetchQueue::resolveActors()`, or with
 * a `user@host` handle by `RemoteFetchQueue::resolveAccounts()`, so — like
 * `DomainPurge` — it is not registered in `info.xml`. The page that asked
 * showed what was cached; the next look at it finds the actor here.
 */
class ResolveActor extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private CacheActorService $cacheActorService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$id = is_array($argument) ? (string)($argument['id'] ?? '') : '';
		$account = is_array($argument) ? (string)($argument['account'] ?? '') : '';
		$refresh = is_array($argument) && ($argument['refresh'] ?? false) === true;
		if ($id === '' && $account === '') {
			return;
		}

		try {
			// a cached actor costs one query and no request
			if ($id !== '') {
				$this->cacheActorService->getFromId($id, $refresh);
			} else {
				$this->cacheActorService->getFromAccount($account);
			}
		} catch (Throwable $e) {
			// gone, refused or unreachable: the page goes on without it, and
			// the next page that names it queues it again
			$this->logger->info('[Cron\\ResolveActor] could not resolve ' . ($id !== '' ? $id : $account), [
				'id' => $id, 'account' => $account, 'exception' => $e,
			]);
		}
	}
}
