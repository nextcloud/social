<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service\Feed;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Mention;
use OCA\Social\Entity\Status;
use OCA\Social\Service\AccountFinder;
use OCA\Social\Service\Feed\FeedManager;

class PostDeliveryService {
	private FeedManager $feedManager;
	private AccountFinder $accountFinder;

	public function __construct(FeedManager $feedManager, AccountFinder $accountFinder) {
		$this->feedManager = $feedManager;
		$this->accountFinder = $accountFinder;
	}

	public function run(Status $status): void {
		$author = $status->getAccount();
		// deliver to self
		if ($status->isLocal()) {
			$this->feedManager->addToHome($author->getId(), $status);
		}

		// deliver to mentioned accounts
		$status->getActiveMentions()->forAll(function ($mention) use ($status): void{
			if ($mention && $mention->getAccount()->isLocal()) {
				$this->deliverLocalAccount($status, $mention->getAccount());
			}
		});

		// deliver to local followers
		$localFollowers = $this->accountFinder->getLocalFollowersOf($author);
		foreach ($localFollowers as $follower) {
			$this->deliverLocalAccount($status, $follower->getAccount());
		};
	}

	public function deliverLocalAccount(Status $status, Account $account) {
		assert($account->isLocal());

		// TODO create notification

		$this->feedManager->addToHome($account->getId(), $status);
	}
}
