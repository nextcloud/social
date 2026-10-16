<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\AccountNotSetUpException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use Throwable;

/**
 * The signed-in person's own account, for a route that acts as it, and the
 * one answer for somebody who has not set it up yet: 403
 * `account-not-set-up` with the setup page's address.
 *
 * Looked up, never made. An account is made where somebody asks for one —
 * `LocalController::accountCreate()`, from the setup page or the sign-in
 * page an app opens, `occ social:account:create` and the account import —
 * and a read that made one would hand them a handle nobody chose.
 *
 * The class using it has an `AccountService` in `$accountService`.
 */
trait OwnAccount {
	/**
	 * @throws AccountNotSetUpException
	 */
	protected function ownAccountOf(string $userId): Person {
		try {
			return $this->accountService->getActorFromUserId($userId);
		} catch (ActorDoesNotExistException $e) {
			throw AccountNotSetUpException::withSetupUrl($e);
		}
	}

	protected static function notSetUp(AccountNotSetUpException $e): DataResponse {
		return new DataResponse($e->toArray(), Http::STATUS_FORBIDDEN);
	}

	/**
	 * `$answer`, unless the person has no account here at all, which is
	 * what they are told instead: for the routes that find nothing of
	 * theirs to act on and would otherwise say only that.
	 */
	protected function unlessNotSetUp(string $userId, DataResponse $answer): DataResponse {
		try {
			$this->ownAccountOf($userId);
		} catch (AccountNotSetUpException $e) {
			return self::notSetUp($e);
		} catch (Throwable) {
		}

		return $answer;
	}
}
