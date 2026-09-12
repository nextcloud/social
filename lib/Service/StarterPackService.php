<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\StarterPack;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starter packs: a named handful of accounts worth following.
 *
 * What this exists for is the one question a new account has that no algorithm
 * here can answer. `SuggestionService` works off the follow graph, and on day
 * one there is none -- it falls back to whoever posted recently, which is a list
 * of strangers sorted by luck. A pack is a human answer to a human question.
 *
 * **The packs shipped below are an editorial choice, and a small one on
 * purpose.** They are the official accounts of the projects this app federates
 * with, which is the one set that is defensible without this file becoming a
 * directory nobody agreed to be in: they are organisations rather than people,
 * they are unambiguously the accounts they claim to be, and none of them was
 * added because somebody here liked them. Anything broader is a judgement an
 * instance should make for itself, which is what the config below is for.
 *
 * An administrator replaces or extends them with the `starter_packs` app value,
 * a JSON array of `{slug, name, description, handles}`. Three states, and they
 * are deliberately distinguishable:
 *
 * - unset: the shipped packs;
 * - `[]`: no packs at all, which is the right answer for an instance that would
 *   rather suggest nobody;
 * - a list: the shipped packs, with any entry whose slug matches one of them
 *   replacing it, plus the rest added -- so a shipped pack can be edited rather
 *   than only added to.
 *
 * Nothing here writes a row. The accounts are not this instance's to own and the
 * handles are the only durable reference to them.
 */
class StarterPackService {
	/** The config key an administrator overrides the shipped packs with. */
	public const CONFIG_KEY = 'starter_packs';

	/** How many handles one pack may hold, resolved or not. */
	public const MAX_HANDLES = 50;

	/**
	 * The shipped packs.
	 *
	 * Official project accounts only -- see the class docblock for why the list
	 * is this narrow. Handles are `user@host` without a leading `@`.
	 */
	private const BUILTIN = [
		[
			'slug' => 'fediverse-projects',
			'name' => 'The projects behind the network',
			'description' => 'The official accounts of the software this app talks to. A reasonable first follow for anyone who wants to know what is changing under them.',
			'handles' => [
				'nextcloud@mastodon.xyz',
				'Mastodon@mastodon.social',
				'pixelfed@mastodon.social',
			],
		],
		[
			'slug' => 'photography',
			'name' => 'Photography',
			'description' => 'Where the pictures are. Pixelfed is the photo-sharing side of the same network, and these are its own accounts.',
			'handles' => [
				'pixelfed@mastodon.social',
				'dansup@mastodon.social',
			],
		],
	];

	public function __construct(
		private ConfigService $configService,
		private CacheActorService $cacheActorService,
		private FollowService $followService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every pack, without resolving anybody.
	 *
	 * The index is drawn from names and counts alone. Resolving a handle means a
	 * WebFinger lookup and an actor fetch against somebody else's server, and
	 * doing that for every handle of every pack to draw a list of pack *names*
	 * would make this page wait on the internet for nothing.
	 *
	 * @return StarterPack[]
	 */
	public function packs(): array {
		$configured = $this->configured();

		// An explicit empty list means "suggest nobody", which is a real answer
		// for an instance that would rather not. `null` is "there is nothing
		// usable here" -- unset, or malformed -- and falls back to what ships,
		// because a typo must not be the reason a page is empty.
		if ($configured === []) {
			return [];
		}

		$packs = [];
		foreach (self::BUILTIN as $definition) {
			$pack = $this->fromDefinition($definition, StarterPack::SOURCE_BUILTIN);
			if ($pack !== null) {
				$packs[$pack->getSlug()] = $pack;
			}
		}

		foreach ($configured ?? [] as $definition) {
			// `{"a": "b"}` decodes to an array whose *entries* are strings, so
			// "is it an array" is not enough to know an entry is a definition
			if (!is_array($definition)) {
				continue;
			}

			$pack = $this->fromDefinition($definition, StarterPack::SOURCE_LOCAL);
			if ($pack !== null) {
				// a configured pack with a shipped slug replaces it, so an
				// instance can edit what is shipped rather than only add to it
				$packs[$pack->getSlug()] = $pack;
			}
		}

		return array_values($packs);
	}

	/**
	 * One pack, with its handles resolved to profiles.
	 *
	 * A handle that will not resolve -- the server is down, the account moved or
	 * was deleted, the host never existed -- is reported rather than dropped
	 * silently. A pack that quietly shrinks looks like one somebody wrote badly.
	 *
	 * @throws ItemNotFoundException when there is no such pack
	 */
	public function pack(string $slug): StarterPack {
		foreach ($this->packs() as $pack) {
			if ($pack->getSlug() === $slug) {
				return $this->resolve($pack);
			}
		}

		throw new ItemNotFoundException('unknown starter pack');
	}

	/**
	 * Follows everyone in a pack that can be reached.
	 *
	 * One that cannot be reached is skipped rather than failing the lot: the
	 * point of the button is that somebody does not have to follow six accounts
	 * by hand, and refusing all six because one host is down would defeat it.
	 *
	 * @return string[] the handles that were followed
	 *
	 * @throws ItemNotFoundException when there is no such pack
	 */
	public function followAll(Person $viewer, string $slug): array {
		$followed = [];
		foreach ($this->pack($slug)->getAccounts() as $account) {
			if ($account->getId() === $viewer->getId()) {
				continue;
			}

			try {
				$this->followService->followAccount($viewer, $account->getAccount());
				$followed[] = $account->getAccount();
			} catch (Throwable $e) {
				$this->logger->info('[StarterPackService] could not follow an account from a pack', [
					'account' => $account->getAccount(),
					'pack' => $slug,
					'exception' => $e,
				]);
			}
		}

		return $followed;
	}

	/** @return StarterPack the same pack, with `accounts` and `unresolved` filled in */
	private function resolve(StarterPack $pack): StarterPack {
		$accounts = [];
		$unresolved = [];

		foreach ($pack->getHandles() as $handle) {
			try {
				$accounts[] = $this->cacheActorService->getFromAccount($handle);
			} catch (Throwable $e) {
				$unresolved[] = $handle;
				$this->logger->debug('[StarterPackService] could not resolve a handle', [
					'handle' => $handle,
					'exception' => $e->getMessage(),
				]);
			}
		}

		return $pack->setAccounts($accounts)->setUnresolved($unresolved);
	}

	/**
	 * What the administrator configured, as three distinguishable answers.
	 *
	 * `null` is "nothing usable here" -- unset, or malformed. Malformed is a log
	 * line and never an exception: this is read on a page somebody opened, and a
	 * typo in a config value must not be the reason a screen is blank with no
	 * explanation, so it falls back to what ships rather than to nothing.
	 *
	 * `[]` is an administrator saying "suggest nobody", which is a different
	 * statement and is honoured.
	 *
	 * A JSON *object* decodes to an array too, so the caller checks each entry
	 * as well -- `is_array()` here says nothing about what is inside.
	 *
	 * @return array<int, mixed>|null
	 */
	private function configured(): ?array {
		$raw = trim((string)$this->configService->getAppValue(self::CONFIG_KEY));
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			$this->logger->warning(
				'[StarterPackService] the ' . self::CONFIG_KEY . ' app value is not a JSON array; ignoring it'
			);

			return null;
		}

		return $decoded;
	}

	/**
	 * @param array $definition one entry from the shipped list or the config
	 *
	 * @return StarterPack|null null when the entry is unusable
	 */
	private function fromDefinition(array $definition, string $source): ?StarterPack {
		$slug = $this->slug((string)($definition['slug'] ?? ''));
		if ($slug === '') {
			return null;
		}

		$handles = [];
		foreach ((array)($definition['handles'] ?? []) as $handle) {
			$handle = $this->handle((string)$handle);
			if ($handle !== '' && !in_array($handle, $handles, true)) {
				$handles[] = $handle;
			}

			if (count($handles) >= self::MAX_HANDLES) {
				break;
			}
		}

		if ($handles === []) {
			return null;
		}

		return new StarterPack(
			$slug,
			(string)($definition['name'] ?? $slug),
			(string)($definition['description'] ?? ''),
			$handles,
			$source
		);
	}

	/** A slug is a path segment, so it is held to what one may contain. */
	private function slug(string $slug): string {
		$slug = strtolower(trim($slug));

		return preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? $slug : '';
	}

	/**
	 * `user@host`, with any leading `@` taken off.
	 *
	 * Shaped rather than trusted: these strings reach a WebFinger lookup, and
	 * one that is not a handle should be refused here rather than turned into a
	 * request to whatever it happens to look like.
	 */
	private function handle(string $handle): string {
		$handle = ltrim(trim($handle), '@');

		return preg_match('/^[\w.\-]+@[\w.\-]+\.[a-zA-Z]{2,}$/', $handle) ? $handle : '';
	}
}
