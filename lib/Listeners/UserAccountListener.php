<?php

declare(strict_types=1);

/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2023, Maxence Lange <maxence@artificial-owl.com>
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
