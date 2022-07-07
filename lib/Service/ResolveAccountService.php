<?php

namespace OCA\Social\Service;

use OCA\Social\Entity\Account;
use OCP\Http\Client\IClientService;

class AccountResolverOption {
	/**
	 * @var bool Whether we should follow webfinger redirection
	 */
	public bool $followWebfingerRediction = true;

	/**
	 * @var bool Whether we should attempt to fetch the account from webfinger
	 */
	public bool $queryWebfinger = true;

	static public function default(): AccountResolverOption {
		return new AccountResolverOption();
	}
}

class ResolveAccountService {
	private IClientService $clientService;

	public function __construct(IClientService $clientService) {
		$this->clientService = $clientService;
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

		[$confirmedUserName, $confirmedDomain] = $webFinger;

		if ($confirmedDomain !== $domain || $confirmedUserName !== $userName) {
			$webFinger = $this->requestWebfinger($confirmedUserName, $confirmedDomain);

			if ($webFinger === null) {
				return null;
			}

			[$newConfirmedUserName, $newConfirmedDomain] = $webFinger;
			if ($confirmedDomain !== $newConfirmedDomain || $confirmedUserName !== $newConfirmedUserName) {
				// Hijack attempt
				return null;
			}
		}

		// TODO

		return null;
	}

	private function requestWebfinger($userName, $domain): ?array {
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
			$webFinger = json_decode($response->getBody());
			$subject = $webFinger['subject'];
			[$confirmedUserName, $confirmedDomain] = explode('@');
		} catch (\Exception $e) {

		}
	}

	public function resolveAccount(Account $account, AccountResolverOption $option): ?Account {
		if (!$account->isLocal() && $option->queryWebfinger && $account->possiblyStale()) {
			return $this->resolveMention($account->getUserName(), $account->getDomain(), $option);
		}
		return $account;
	}

	public function requestWebfinger():
}
