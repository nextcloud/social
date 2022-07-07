<?php

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OCA\Social\Entity\Mention;
use OCA\Social\Entity\Status;
use OCP\IRequest;

class ProcessMentionsService {
	private Collection $previousMentions;
	private Collection $currentMentions;
	private IRequest $request;
	private AccountFinder $accountFinder;
	private ResolveAccountService $resolveAccountService;

	public function __construct(IRequest $request, AccountFinder $accountFinder, ResolveAccountService $resolveAccountService) {
		$this->previousMentions = new ArrayCollection();
		$this->currentMentions = new ArrayCollection();
		$this->request = $request;
		$this->accountFinder = $accountFinder;
		$this->resolveAccountService = $resolveAccountService;
	}

	public function run(Status $status) {
		if (!$status->isLocal()) {
			return;
		}

		$this->previousMentions = $status->getActiveMentions();
		$this->currentMentions = new ArrayCollection();

		if (preg_match('/@(([a-z0-9_]([a-z0-9_\.-]+[a-z0-9_]+)+)(@[[:word:]\.\-]+[[:word:]]+)?)/i', $status->getText(), $matches)) {
			$host = $this->request->getServerHost();
			for ($i = 0; $i < count($matches[0]); $i++) {
				$completeMatch = $matches[0][$i];
				$userName = $matches[2][$i];
				$domain = $matches[2][$i] === '' ? '' : substr($matches[2][$i], 1);

				$isLocal = $domain === '' || $host === $domain;
				if ($isLocal) {
					$domain = null;
				} else {
					// normalize domain name
					$domain = parse_url('https://' . $domain,  PHP_URL_HOST);
				}

				$mentionnedAccount = $this->accountFinder->findRemote($userName, $domain);
				assert($mentionnedAccount !== null);

				if (!$mentionnedAccount) {
					// try to resolve it
					$mentionnedAccount = $this->resolveAccountService->resolveMention($userName, $domain, AccountResolverOption::default());
				}

				if (!$mentionnedAccount) {
					// give up
					continue;
				}
				$mentions = $this->previousMentions->filter(fn (Mention $mention) => $mention->getAccount()->getId() === $mentionnedAccount->getId());
				if ($mentions->isEmpty()) {
					$mention = new Mention();
					$mention->setStatus($status);
					$mention->setAccount($mentionnedAccount);
				} else {
					$mention = $mentions->first();
				}
				$this->currentMentions->add($mention);
				str_replace($completeMatch, $mentionnedAccount->getAccountName(), $status->getText());
			}
		}
	}
}
