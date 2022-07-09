<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Mention;
use OCA\Social\Entity\Status;

class PostDeliveryService {
	private FeedManager $feedManager;
	private AccountFinder $accountFinder;

	public function __construct(FeedManager $feedManager, AccountFinder $accountFinder) {
		$this->feedManager = $feedManager;
		$this->accountFinder = $accountFinder;
	}

	public function run(Account $author, Status $status): void {
		// deliver to self
		if ($status->isLocal()) {
			$this->feedManager->addToHome($author->getId(), $status);
		}

		// deliver to mentioned accounts
		$localFollowers = $this->accountFinder->getLocalFollowersOf($author);
		$status->getActiveMentions()->forAll(function (Mention $mention) use ($status): void{
			if ($mention->getAccount()->isLocal()) {
				$this->deliverLocalAccount($status, $mention->getAccount());
			}
		});

		// deliver to local followers
		$localFollowers->forAll(function (Account $account) use ($status): void {
			$this->deliverLocalAccount($status, $account);
		});

	}

	public function deliverLocalAccount(Status $status, Account $account) {
		assert($account->isLocal());

		// TODO create notification

		$this->feedManager->addToHome($account->getId(), $status);
	}
}
