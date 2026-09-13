<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\DomainBlocksRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * Per-account blocks of a whole instance.
 *
 * One account's list, enforced for that account only: the admin's
 * instance-wide access list is `FediverseService`, which decides what the
 * *server* federates with and is a different thing with a different audience.
 * Nothing here is federated and the blocked instance is never told.
 *
 * A block is held as a domain and applied to the *host of an actor id*, which
 * is the one form of an account's instance that every row the timeline reads
 * already carries — see `DomainBlocksRequestBuilder::filterDomainBlocked()`,
 * which is where a blocked instance stops reaching the timelines.
 */
class DomainBlockService {
	/**
	 * What `/api/v1/domain_blocks` hands back in one page, and what it accepts
	 * being asked for — Mastodon's own default and ceiling.
	 */
	public const LIMIT = 100;
	public const MAX_LIMIT = 200;

	/**
	 * How much of one account's block list is kept in memory. An account with
	 * more blocked instances than this is asked in SQL instead — the list is
	 * there to save queries, not to decide anything it does not hold.
	 */
	private const CACHE_LIMIT = 1000;

	/**
	 * The blocked domains of one account, for the duration of one request.
	 *
	 * A relationship is built one account at a time, so without this a client
	 * asking about a page of forty accounts would read the same short list
	 * forty times. Every write below drops the entry, so nothing in one request
	 * can read a list its own call changed.
	 *
	 * @var array<string, string[]> actor id => domains
	 */
	private array $cached = [];

	public function __construct(
		private DomainBlocksRequest $domainBlocksRequest,
		private ConfigService $configService,
	) {
	}

	/**
	 * The domain as it is stored and compared: lowercase, no scheme, no port,
	 * no path, no leading `@`, no trailing dot.
	 *
	 * A client sends what a user typed — `Example.COM`, `@user@example.com`,
	 * `https://example.com/@user` are all the same instance — and one stored
	 * form is what makes blocking twice a no-op and makes the timeline
	 * comparison an equality rather than a guess.
	 *
	 * @throws InvalidResourceException there is no domain in what was sent, or
	 *                                  what is left is not one: the LIKE
	 *                                  pattern of the timeline join is built
	 *                                  out of this value, so a `%` or a `_`
	 *                                  reaching the table would be a block that
	 *                                  silently matched other instances
	 */
	public static function normalise(string $domain): string {
		$domain = trim($domain);
		if (str_contains($domain, '://')) {
			$domain = (string)parse_url($domain, PHP_URL_HOST);
		}

		// `@user@example.com` and `user@example.com` are both the handle of an
		// account on the instance being named
		$at = strrpos($domain, '@');
		if ($at !== false) {
			$domain = substr($domain, $at + 1);
		}

		$domain = strtolower(rtrim(trim($domain, '/'), '.'));
		$colon = strpos($domain, ':');
		if ($colon !== false) {
			$domain = substr($domain, 0, $colon);
		}

		if ($domain !== '' && preg_match('/[^\x20-\x7e]/', $domain) === 1 && function_exists('idn_to_ascii')) {
			// an internationalised domain is stored the way an actor id spells
			// it, which is always its punycode form
			$ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
			$domain = ($ascii === false) ? $domain : $ascii;
		}

		if ($domain === '' || strlen($domain) > 255) {
			throw new InvalidResourceException('domain is required');
		}

		if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $domain) !== 1) {
			throw new InvalidResourceException("'" . $domain . "' is not a domain");
		}

		return $domain;
	}

	/**
	 * The instance an account is on: the host of its actor id, which is the
	 * only part of an account that the stream rows and the relationship lookup
	 * both have without a second query.
	 */
	public static function domainOf(string $actorId): string {
		$host = parse_url($actorId, PHP_URL_HOST);

		return is_string($host) ? strtolower($host) : '';
	}

	/**
	 * @return string[] newest block first
	 */
	public function getBlocked(Person $viewer, int $limit = self::LIMIT): array {
		return $this->domainBlocksRequest->getByActor($viewer->getId(), $limit);
	}

	/**
	 * @throws InvalidResourceException the domain is not one, or it is this
	 *                                  instance's own — blocking that would
	 *                                  hide the account's own posts from it,
	 *                                  and Mastodon refuses it for the same
	 *                                  reason
	 */
	public function block(Person $viewer, string $domain): string {
		$domain = self::normalise($domain);
		if ($this->isLocal($domain)) {
			throw new InvalidResourceException('cannot block your own instance');
		}

		unset($this->cached[$viewer->getId()]);
		// the read filter carries the list as constants rather than
		// joining it, so the memo behind it has to go as well
		CoreRequestBuilder::forgetBlockedDomains();
		$this->domainBlocksRequest->save($viewer->getId(), $domain);

		return $domain;
	}

	/**
	 * @throws InvalidResourceException
	 */
	public function unblock(Person $viewer, string $domain): string {
		$domain = self::normalise($domain);

		unset($this->cached[$viewer->getId()]);
		// the read filter carries the list as constants rather than
		// joining it, so the memo behind it has to go as well
		CoreRequestBuilder::forgetBlockedDomains();
		$this->domainBlocksRequest->delete($viewer->getId(), $domain);

		return $domain;
	}

	/** Whether the viewer has blocked the instance the account is on. */
	public function isBlocking(string $viewerId, string $actorId): bool {
		$domain = self::domainOf($actorId);
		if ($domain === '' || $viewerId === '') {
			return false;
		}

		$domains = $this->domains($viewerId);
		if (in_array($domain, $domains, true)) {
			return true;
		}

		if (count($domains) < self::CACHE_LIMIT) {
			return false;
		}

		return $this->domainBlocksRequest->isBlocked($viewerId, $domain);
	}

	private function isLocal(string $domain): bool {
		return in_array(
			$domain,
			[strtolower($this->configService->getSocialAddress()), strtolower($this->configService->getCloudHost())],
			true
		);
	}

	/**
	 * @return string[]
	 */
	private function domains(string $viewerId): array {
		if (!array_key_exists($viewerId, $this->cached)) {
			// the whole list, not one lookup per account: it is a handful of
			// short strings, and the relationship of every account in a page is
			// asked about it
			$this->cached[$viewerId] = $this->domainBlocksRequest->getByActor($viewerId, self::CACHE_LIMIT);
		}

		return $this->cached[$viewerId];
	}
}
