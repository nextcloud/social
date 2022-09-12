<?php

namespace OCA\Social\Service;

use OCA\Circles\Tools\Model\NCWebfinger;
use OCA\Social\Entity\Account;
use OCA\Social\Service\ActivityPub\RemoteAccountFetcher;
use OCA\Social\Service\ActivityPub\RemoteAccountFetchOption;
use OCP\Http\Client\IClientService;
use OCP\IRequest;

class AccountResolverOption {
	/**
	 * @var bool Whether we should follow webfinger redirection
	 */
	public bool $followWebfingerRedirection = true;

	/**
	 * @var bool Whether we should attempt to fetch the account from webfinger
	 */
	public bool $queryWebfinger = true;

	static public function default(): self {
		return new self();
	}
}

class ResolveAccountService {
	private IClientService $clientService;
	private IRequest $request;
	private TrustedDomainChecker $trustedDomainChecker;
	private AccountFinder $accountFinder;
	private RemoteAccountFetcher $remoteAccountFetcher;

	public function __construct(
		IClientService $clientService,
		IRequest $request,
		TrustedDomainChecker $trustedDomainChecker,
		AccountFinder $accountFinder,
		RemoteAccountFetcher $remoteAccountFetcher
	) {
		$this->clientService = $clientService;
		$this->request = $request;
		$this->trustedDomainChecker = $trustedDomainChecker;
		$this->accountFinder = $accountFinder;
		$this->remoteAccountFetcher = $remoteAccountFetcher;
	}

	/**
	 * @param string $userName The username of the user
	 * @param string $domain The domain of the user
	 * @return Account|null
	 */
	public function resolveMention(string $userName, string $domain, AccountResolverOption $option): ?Account {
		$webFinger = $this->requestWebfinger($userName, $domain);
		if ($webFinger === null) {
			return null;
		}

		[$confirmedUserName, $confirmedDomain] = $webFinger->getSubject();

		if ($confirmedDomain !== $domain || $confirmedUserName !== $userName) {
			if (!$option->followWebfingerRedirection) {
				return null;
			}

			$webFinger = $this->requestWebfinger($confirmedUserName, $confirmedDomain);
			if ($webFinger === null) {
				return null;
			}

			[$newConfirmedUserName, $newConfirmedDomain] = $webFinger->getSubject();
			if ($confirmedDomain !== $newConfirmedDomain || $confirmedUserName !== $newConfirmedUserName) {
				// Hijack attempt
				return null;
			}
			$confirmedDomain = $newConfirmedDomain;
			$confirmedUserName = $newConfirmedUserName;
		}

		if ($confirmedDomain === $this->request->getServerHost()) {
			$confirmedDomain = null;
		}

		if ($this->trustedDomainChecker->check($confirmedDomain)) {
			return null; // blocked
		}

		$account = $this->accountFinder->findRemote($userName, $domain);

		if ($account !== null && ($account->isLocal() || !$account->possiblyStale())) {
			return $account;
		}

		return $this->fetchAccount($webFinger);
	}

	private function requestWebfinger($userName, $domain): ?NCWebfinger {
		$client = $this->clientService->newClient();

		$uri = 'acct:' . $userName . '@' . $domain;
		if (str_ends_with($domain, '.onion')) {
			$url = 'http://' . $domain . '/.well-known/webfinger?resource=' . $uri;
		} else {
			$url = 'https://' . $domain . '/.well-known/webfinger?resource=' . $uri;
		}
		$response = $client->get($url, [
			'headers' => [
				'Accept' => 'application/jrd+json, application/json',
			],
		]);

		if ($response->getStatusCode() !== 200) {
			// TODO mark server as unavailable
			return null;
		}

		try {
			$webFinger = new NCWebfinger(json_decode($response->getBody()));
		} catch (\Exception $e) {
			return null;
		}
		return $webFinger;
	}

	public function resolveAccount(Account $account, AccountResolverOption $option): ?Account {
		if (!$account->isLocal() && $option->queryWebfinger && $account->possiblyStale()) {
			return $this->resolveMention($account->getUserName(), $account->getDomain(), $option);
		}
		return $account;
	}

	public function fetchAccount(NCWebfinger $webfinger): ?Account {
		// TODO lock

		$actorUrl = $webfinger->getLink('self');
		if (!$actorUrl) {
			return null;
		}

		return $this->remoteAccountFetcher->fetch($actorUrl, RemoteAccountFetchOption::default());
	}
}
