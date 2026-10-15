<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Reader\SuggestedStarterPacks;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\StarterPacksRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The starter packs other servers publish, read in: the collections of the
 * fediverse, and Bluesky's suggested starter packs.
 *
 * Mastodon 4.6 calls them Collections and federates them as FEP-7aa9
 * `FeaturedCollection`s: a named, ordered list of accounts a curator
 * recommends, linked from the curator's actor as `featuredCollections`.
 * Each entry is a `FeaturedItem` naming the account (`featuredObject`) and
 * the account's own consent to be in it (`featureAuthorization`), which the
 * FEP requires a reader to check: an item whose authorization does not
 * resolve, is not a `FeatureAuthorization`, names another collection or
 * account, or does not live on the account's own server is left out.
 *
 * Which curators are read is the `starter_pack_sources` app value: a JSON
 * array of handles (`user@host`, every public collection of that account),
 * collection addresses (that one collection) and `bluesky:suggested`
 * (Bluesky's suggested starter packs, read by `SuggestedStarterPacks` while
 * Bluesky is on). Unset is the shipped list; `[]` reads nobody and removes
 * what was read. A collection that is not `discoverable`, is marked
 * `sensitive`, or has no verified member is not offered to somebody looking
 * for their first follows.
 *
 * Read by `Cron\StarterPackSources` once a day and by
 * `occ social:starter-packs:refresh`; never on a page. A curator that cannot
 * be reached keeps what was read from it last time.
 */
class CollectionPackService {
	/** The app value naming the curators and collections to read. */
	public const CONFIG_KEY = 'starter_pack_sources';

	/** The source naming Bluesky's suggested starter packs. */
	public const BLUESKY_SUGGESTED = 'bluesky:suggested';

	/** Read when the app value is unset: @Gargron's curated collections on mastodon.social, and Bluesky's suggestions. */
	public const DEFAULT_SOURCES = [
		'Gargron@mastodon.social',
		self::BLUESKY_SUGGESTED,
	];

	/** The `origin` of a collection read from the fediverse. */
	public const ORIGIN = 'fediverse';

	/** The most sources read in one pass. */
	public const MAX_SOURCES = 20;

	/** The most collections read from one curator. */
	public const MAX_COLLECTIONS = 20;

	/** The most entries read from one collection, the FEP's own suggestion. */
	public const MAX_ITEMS = 150;

	private const TYPE_COLLECTION = 'FeaturedCollection';
	private const TYPE_ITEM = 'FeaturedItem';
	private const TYPE_AUTHORIZATION = 'FeatureAuthorization';

	public function __construct(
		private ConfigService $configService,
		private CacheActorService $cacheActorService,
		private CurlService $curlService,
		private StarterPacksRequest $starterPacksRequest,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * The handles and collection addresses to read.
	 *
	 * @return list<string>
	 */
	public function sources(): array {
		$raw = trim((string)$this->configService->getAppValue(self::CONFIG_KEY));
		if ($raw === '') {
			return self::DEFAULT_SOURCES;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			$this->logger->warning('[CollectionPackService] the ' . self::CONFIG_KEY . ' app value is not a JSON array; reading the shipped sources');

			return self::DEFAULT_SOURCES;
		}

		$sources = [];
		foreach ($decoded as $source) {
			$source = is_string($source) ? self::source($source) : '';
			if ($source !== '' && !in_array($source, $sources, true)) {
				$sources[] = $source;
			}
		}

		return array_slice($sources, 0, self::MAX_SOURCES);
	}

	/**
	 * Reads every source again and stores what it publishes now.
	 *
	 * @return array{sources: int, packs: int, members: int, refused: int, failed: int}
	 */
	public function refresh(): array {
		$sources = $this->sources();
		$result = ['sources' => count($sources), 'packs' => 0, 'members' => 0, 'refused' => 0, 'failed' => 0];
		$seen = [];
		/** @var array<string, true> $read the curators read in full this pass */
		$read = [];

		foreach ($sources as $source) {
			if ($source === self::BLUESKY_SUGGESTED) {
				continue;
			}
			try {
				[$curator, $collections] = $this->collectionsOf($source);
			} catch (Throwable $e) {
				$result['failed']++;
				$this->logger->notice('[CollectionPackService] could not read a source of starter packs', [
					'source' => $source, 'exception' => $e,
				]);
				continue;
			}

			// two sources may name one curator, a handle and one of its
			// collections; what was read for it once is not read again
			if (isset($read[$curator->getId()])) {
				continue;
			}
			$read[$curator->getId()] = true;
			foreach ($collections as $collection) {
				$pack = $this->pack($curator, $collection, $result['refused']);
				if ($pack === null) {
					continue;
				}

				$this->starterPacksRequest->save($pack);
				$seen[md5($pack['uri'])] = true;
				$result['packs']++;
				$result['members'] += count($pack['members']);
				$this->cacheMembers($pack['members']);
			}
		}

		$this->forgetGone($sources, $seen, $read);
		$this->refreshBluesky(in_array(self::BLUESKY_SUGGESTED, $sources, true), $result);

		return $result;
	}

	/**
	 * Bluesky's suggested starter packs, read while they are a source and
	 * Bluesky is on, and removed when either stops being so.
	 *
	 * @param array{sources: int, packs: int, members: int, refused: int, failed: int} $result
	 */
	private function refreshBluesky(bool $wanted, array &$result): void {
		$config = $this->container?->get(AtprotoConfig::class);
		$suggested = $this->container?->get(SuggestedStarterPacks::class);
		if (!$suggested instanceof SuggestedStarterPacks) {
			return;
		}

		if (!$wanted || !$config instanceof AtprotoConfig || !$config->isEnabled()) {
			$suggested->forgetAllBut();

			return;
		}

		try {
			$read = $suggested->refresh();
			$result['packs'] += $read['packs'];
			$result['members'] += $read['members'];
		} catch (Throwable $e) {
			$result['failed']++;
			$this->logger->notice('[CollectionPackService] could not read Bluesky\'s suggested starter packs', ['exception' => $e]);
		}
	}

	/**
	 * The curator and the collection documents one source names.
	 *
	 * @return array{0: Person, 1: list<array<string, mixed>>}
	 */
	private function collectionsOf(string $source): array {
		if (str_starts_with($source, 'https://')) {
			$collection = $this->curlService->retrieveObject($source);
			$curator = $this->cacheActorService->getFromId(self::id($collection['attributedTo'] ?? ''));

			return [$curator, [$collection]];
		}

		$curator = $this->cacheActorService->getFromAccount($source);
		// the actor document as it is now: a copy cached before the account
		// had any collection carries no link to them
		$document = $this->curlService->retrieveObject($curator->getId());
		$listId = self::id($document['featuredCollections'] ?? '');
		if ($listId === '') {
			return [$curator, []];
		}

		$entries = [];
		foreach ($this->entriesOf($this->curlService->retrieveObject($listId)) as $entry) {
			if (count($entries) >= self::MAX_COLLECTIONS) {
				break;
			}
			if (is_array($entry) && self::isType($entry, self::TYPE_COLLECTION)) {
				$entries[] = $entry;
			} elseif (self::id($entry) !== '') {
				$entries[] = $this->curlService->retrieveObject(self::id($entry));
			}
		}

		return [$curator, $entries];
	}

	/**
	 * The entries of a collection, from its first page where it is paged.
	 *
	 * @param array<string, mixed> $collection
	 *
	 * @return list<mixed>
	 */
	private function entriesOf(array $collection): array {
		foreach (['orderedItems', 'items'] as $key) {
			if (is_array($collection[$key] ?? null)) {
				return array_values($collection[$key]);
			}
		}

		$first = $collection['first'] ?? null;
		if (is_array($first)) {
			return $this->entriesOf($first);
		}
		if (self::id($first) !== '') {
			return $this->entriesOf($this->curlService->retrieveObject(self::id($first)));
		}

		return [];
	}

	/**
	 * One collection as a row, or null when it is not one to offer.
	 *
	 * @param array<string, mixed> $collection
	 *
	 * @return array{uri: string, slug: string, origin: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, members: list<string>, fetched: int}|null
	 */
	private function pack(Person $curator, array $collection, int &$refused): ?array {
		$uri = self::id($collection['id'] ?? '');
		if ($uri === '' || !self::isType($collection, self::TYPE_COLLECTION)
			|| !$this->sameActor(self::id($collection['attributedTo'] ?? ''), $curator->getId())
			|| self::host($uri) !== self::host($curator->getId())
			|| ($collection['discoverable'] ?? true) === false
			|| ($collection['sensitive'] ?? false) === true) {
			return null;
		}

		$members = $this->verifiedMembers($uri, array_slice($this->entriesOf($collection), 0, self::MAX_ITEMS), $refused);
		if ($members === []) {
			return null;
		}

		$name = self::text(self::mapped($collection, 'name'), 255);
		$language = self::language($collection);

		return [
			'uri' => $uri,
			'slug' => self::slug($name, $uri),
			'origin' => self::ORIGIN,
			'curator' => $curator->getId(),
			'curator_acct' => $curator->getAccount(),
			'name' => ($name !== '') ? $name : $curator->getAccount(),
			'summary' => self::text(self::mapped($collection, 'summary'), 1000),
			'topic' => self::topic($collection['topic'] ?? null),
			'language' => $language,
			'url' => self::url($collection['url'] ?? null),
			'members' => $members,
			'fetched' => $this->timeFactory->getTime(),
		];
	}

	/**
	 * The accounts of a collection whose consent to be in it holds, in the
	 * curator's order.
	 *
	 * @param list<mixed> $items
	 *
	 * @return list<string>
	 */
	private function verifiedMembers(string $collectionId, array $items, int &$refused): array {
		$wanted = [];
		foreach ($items as $item) {
			if (!is_array($item) || !self::isType($item, self::TYPE_ITEM)) {
				continue;
			}
			$member = self::id($item['featuredObject'] ?? '');
			$authorization = self::id($item['featureAuthorization'] ?? '');
			if ($member === '' || $authorization === '' || isset($wanted[$member])) {
				continue;
			}
			$wanted[$member] = $authorization;
		}
		if ($wanted === []) {
			return [];
		}

		$documents = $this->curlService->retrieveObjectsMany(array_values(array_unique(array_values($wanted))));

		$members = [];
		foreach ($wanted as $member => $authorization) {
			if (self::authorizes($documents[$authorization] ?? null, $authorization, $member, $collectionId, $this->sameActor(...))) {
				$members[] = $member;
			} else {
				$refused++;
			}
		}

		return $members;
	}

	/**
	 * Whether a document is the member's own consent to be in this collection:
	 * a `FeatureAuthorization` served from the address it was asked for, on
	 * the member's server, naming the member and this collection.
	 *
	 * @param array<string, mixed>|null $document
	 * @param callable(string, string): bool $sameActor whether two ids name one actor
	 */
	public static function authorizes(?array $document, string $requested, string $member, string $collectionId, callable $sameActor): bool {
		if ($document === null || !self::isType($document, self::TYPE_AUTHORIZATION)) {
			return false;
		}

		$id = self::id($document['id'] ?? '');

		return $id !== ''
			&& self::host($id) === self::host($requested)
			&& self::host($id) === self::host($member)
			&& self::id($document['interactingObject'] ?? '') === $collectionId
			&& $sameActor(self::id($document['interactionTarget'] ?? ''), $member);
	}

	/**
	 * Whether two ids name one actor. Mastodon gives an account a second,
	 * numbered address (`/ap/users/<n>`) beside `/users/<name>`, and one
	 * document may name it by either; where the two differ, both are looked
	 * up, and the cache knows an actor by every address it was fetched at.
	 */
	private function sameActor(string $one, string $other): bool {
		if ($one === '' || $other === '') {
			return false;
		}
		if ($one === $other) {
			return true;
		}
		if (self::host($one) !== self::host($other)) {
			return false;
		}

		try {
			return $this->cacheActorService->getFromId($one)->getId() === $this->cacheActorService->getFromId($other)->getId();
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Fetches the members not cached yet, so an opened pack draws from the
	 * cache rather than waiting on every member's server.
	 *
	 * @param list<string> $members
	 */
	private function cacheMembers(array $members): void {
		$cached = $this->cacheActorService->getCachedFromIds($members);
		foreach ($members as $member) {
			if (isset($cached[$member])) {
				continue;
			}
			try {
				$this->cacheActorService->getFromId($member);
			} catch (Throwable $e) {
				$this->logger->debug('[CollectionPackService] a member of a starter pack could not be fetched', [
					'actor' => $member, 'exception' => $e->getMessage(),
				]);
			}
		}
	}

	/**
	 * Removes the packs no source publishes any more: a collection a curator
	 * read in full this pass no longer lists, and the collections of a
	 * source taken out of the list. A curator that could not be reached
	 * keeps its packs until it can be.
	 *
	 * @param list<string> $sources
	 * @param array<string, true> $seen
	 * @param array<string, true> $read
	 */
	private function forgetGone(array $sources, array $seen, array $read): void {
		$configured = array_map('strtolower', $sources);
		foreach ($this->starterPacksRequest->getAll() as $pack) {
			if ($pack['origin'] !== self::ORIGIN || isset($seen[md5($pack['uri'])])) {
				continue;
			}
			$stillNamed = in_array(strtolower($pack['curator_acct']), $configured, true)
				|| in_array(strtolower($pack['uri']), $configured, true);
			if (isset($read[$pack['curator']]) || !$stillNamed) {
				$this->starterPacksRequest->deleteById($pack['id']);
			}
		}
	}

	/** A configured source shaped: `user@host`, or an `https://` address. */
	public static function source(string $source): string {
		$source = trim($source);
		if ($source === self::BLUESKY_SUGGESTED) {
			return $source;
		}
		if (str_starts_with($source, 'https://')) {
			return filter_var($source, FILTER_VALIDATE_URL) !== false ? $source : '';
		}
		$source = ltrim($source, '@');

		return preg_match('/^[\w.\-]+@[\w.\-]+\.[a-zA-Z]{2,}$/', $source) ? $source : '';
	}

	/**
	 * A path segment for the pack: its name made safe, and a hash of its id
	 * so that two collections of the same name are two packs.
	 */
	public static function slug(string $name, string $uri): string {
		$base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
		$base = trim(substr($base, 0, 40), '-');

		return (($base !== '') ? $base : 'collection') . '-' . substr(md5($uri), 0, 8);
	}

	/** The id an ActivityStreams value names: a string, or an object's `id` or `href`. */
	private static function id(mixed $value): string {
		if (is_string($value)) {
			return $value;
		}
		if (is_array($value)) {
			return self::id($value['id'] ?? $value['href'] ?? '');
		}

		return '';
	}

	/** @param array<string, mixed> $object */
	private static function isType(array $object, string $type): bool {
		return in_array($type, (array)($object['type'] ?? []), true);
	}

	private static function host(string $id): string {
		return strtolower((string)parse_url($id, PHP_URL_HOST));
	}

	/**
	 * A natural-language property, or the first value of its map.
	 *
	 * @param array<string, mixed> $object
	 */
	private static function mapped(array $object, string $property): string {
		if (is_string($object[$property] ?? null) && $object[$property] !== '') {
			return $object[$property];
		}
		foreach ((array)($object[$property . 'Map'] ?? []) as $value) {
			if (is_string($value) && $value !== '') {
				return $value;
			}
		}

		return '';
	}

	/** The language the collection is written in, where its maps say. */
	private static function language(array $collection): string {
		foreach (['nameMap', 'summaryMap'] as $map) {
			$keys = array_keys((array)($collection[$map] ?? []));
			if (count($keys) === 1 && is_string($keys[0]) && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $keys[0])) {
				return $keys[0];
			}
		}

		return '';
	}

	/** ActivityStreams text is HTML: shown here as plain text, and bounded. */
	private static function text(string $html, int $length): string {
		$text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		return mb_substr((string)preg_replace('/\s+/u', ' ', $text), 0, $length);
	}

	private static function topic(mixed $topic): string {
		$name = is_array($topic) ? (string)($topic['name'] ?? '') : '';

		return mb_substr(ltrim(trim($name), '#'), 0, 100);
	}

	private static function url(mixed $url): string {
		$url = self::id(is_array($url) && array_is_list($url) ? ($url[0] ?? '') : $url);

		return str_starts_with($url, 'https://') ? $url : '';
	}
}
