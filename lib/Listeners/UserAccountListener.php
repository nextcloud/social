<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Service\AccountService;
use OCP\Accounts\UserUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class UserAccountListener implements IEventListener {
	public function __construct(
		private AccountService $accountService,
		private LoggerInterface $logger
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserUpdatedEvent)) {
			return;
		}

		$user = $event->getUser();
		try {
			$this->accountService->cacheLocalActorByUsername($user->getUID());
		} catch (\Exception $e) {
			$this->logger->warning('issue while updating user account', ['exception' => $e]);
		}
	}
}
