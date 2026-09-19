<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\DirectoryAccount;
use OCA\Social\Model\Client\DirectorySource;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Looking for somebody to follow, in directories other servers publish.
 *
 * The app could already find a person whose full handle you had already been
 * told, and suggest people out of a follow graph that a new account has none
 * of. Neither is how anybody actually finds anyone: you know a name, or a
 * subject, and not which of forty thousand servers they are on. What was
 * missing was somewhere to *ask*.
 *
 * So this asks several servers at once. Every API it uses is one those servers
 * serve to anybody without a token, because a directory is the part of a
 * server that exists to be read by strangers — nothing here holds credentials
 * for anyone else's instance, and nothing here can be made to.
 *
 * **What it does not do is aggregate.** No list is stored, nothing is indexed,
 * and an answer is kept for five minutes so that typing does not hammer four
 * servers per keystroke. The people in it are not this instance's to hold, and
 * a copy would be a directory nobody agreed to be in.
 *
 * The kinds it can ask:
 *
 * - `local` — this instance's own directory and its cached actors. Always
 *   first, always present, never a network request.
 * - `mastodon` — `/api/v1/directory` and `/api/v1/accounts/lookup`, which is
 *   also what Pixelfed, Hometown, Glitch and most forks serve.
 * - `misskey` — `users/search`.
 * - `lemmy` — `search` with `type_=Users`.
 *
 * Mastodon has no public *search*, and that is a fact about Mastodon rather
 * than an omission here: `/api/v2/search` needs a token. What it does have is
 * an exact-handle lookup and a directory page, so a Mastodon-kind source is
 * asked both — the lookup answers "is there a `nextcloud` on that server",
 * the directory page answers "who is active there", and the query filters the
 * second. That is weaker than search and is reported as what it is, rather
 * than dressed up: see `DirectoryResults::isExhaustive()`.
 *
 * @see DirectorySource for why the kind is configured rather than sniffed
 */
class FediverseDirectoryService {
	/** The config key an administrator sets the sources with. */
	public const CONFIG_KEY = 'directories';

	/** How many people one source may contribute to a page. */
	public const LIMIT = 20;
	public const MAX_LIMIT = 40;

	/** How long one source has to answer before it is left out. */
	public const TIMEOUT = 4;

	/**
	 * How long the whole fan-out has, in seconds. Four sources at four seconds
	 * each is sixteen, which is longer than anybody waits for a search box, so
	 * the sources are asked until this is spent and the rest are reported as
	 * unasked rather than silently missing.
	 *
	 * A float, because it is added to `microtime(true)` and this codebase runs
	 * psalm in strict binary operands mode.
	 */
	public const BUDGET = 10.0;

	/** How long an answer is kept. Long enough for typing, short enough to be news. */
	public const CACHE_TTL = 300;

	/**
	 * How much of a Mastodon directory is read before the query is applied to
	 * it. Its own maximum page is 80, and this is one page: a server that will
	 * not answer a search cannot be made to by asking it eighty at a time
	 * until something matches, and trying would be this instance walking
	 * somebody else's user list.
	 */
	private const DIRECTORY_PAGE = 80;

	/** How many curated entries one search reads. Their API caps at 100. */
	private const WORDPRESS_PAGE = 20;

	/**
	 * The sources an instance starts with.
	 *
	 * **Two, and named for what they are.** The editorial line is the same one
	 * `StarterPackService` draws: the largest public instance of each of the
	 * two software families whose whole purpose is people posting to people,
	 * each serving the API used here to anybody without a token. Neither was
	 * chosen because somebody here likes it, and an administrator who wants
	 * different ones — or none — says so in the config below. This instance
	 * publishes its own directory to them on exactly the same terms.
	 */
	private const BUILTIN = [
		// a directory somebody keeps by hand, and the only one of these that
		// can answer "who writes about X" — asked first for that reason
		['host' => 'fedi.directory', 'kind' => DirectorySource::KIND_WORDPRESS, 'label' => 'Fedi.Directory'],
		['host' => 'mastodon.social', 'kind' => DirectorySource::KIND_MASTODON],
		['host' => 'misskey.io', 'kind' => DirectorySource::KIND_MISSKEY],
		['host' => 'lemmy.world', 'kind' => DirectorySource::KIND_LEMMY],
		['host' => 'pixelfed.social', 'kind' => DirectorySource::KIND_MASTODON],
	];

	/** How many of the servers this instance federates with are asked. */
	public const CONFIG_PEERS = 'directory_peers';
	public const PEERS_DEFAULT = 4;
	/** Whether the server directory below is asked which servers exist. */
	public const CONFIG_DISCOVERY = 'directory_discovery';

	/**
	 * A directory *of servers*, asked which ones are worth asking about
	 * people. It publishes a curated list with each server's software, which
	 * is exactly what is needed to know which API to speak to it.
	 */
	private const DISCOVERY_HOST = 'fediverse.info';
	private const DISCOVERY_PATH = '/api/_meta-api/instances/list';
	/** How many servers a discovery pass contributes. */
	private const DISCOVERY_LIMIT = 3;

	/** How long a server list, or one server's software, is remembered. */
	private const DISCOVERY_TTL = 86400;
	private const SOFTWARE_TTL = 604800;

	/**
	 * What a server calls its software, and which of the APIs above that is.
	 * Everything Mastodon-compatible is asked as Mastodon, which is what
	 * those projects intend.
	 */
	private const SOFTWARE_KINDS = [
		'mastodon' => DirectorySource::KIND_MASTODON,
		'hometown' => DirectorySource::KIND_MASTODON,
		'glitchcafe' => DirectorySource::KIND_MASTODON,
		'pixelfed' => DirectorySource::KIND_MASTODON,
		'pleroma' => DirectorySource::KIND_MASTODON,
		'akkoma' => DirectorySource::KIND_MASTODON,
		'iceshrimp' => DirectorySource::KIND_MISSKEY,
		'misskey' => DirectorySource::KIND_MISSKEY,
		'sharkey' => DirectorySource::KIND_MISSKEY,
		'firefish' => DirectorySource::KIND_MISSKEY,
		'calckey' => DirectorySource::KIND_MISSKEY,
		'foundkey' => DirectorySource::KIND_MISSKEY,
		'lemmy' => DirectorySource::KIND_LEMMY,
	];

	private ICache $cache;

	public function __construct(
		private CurlService $curlService,
		private ConfigService $configService,
		private DirectoryService $directoryService,
		private CacheActorService $cacheActorService,
		private FediverseService $fediverseService,
		private InstanceStatsRequest $instanceStatsRequest,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('social.directories');
	}

	/**
	 * Every source that will be asked, this instance first.
	 *
	 * @return DirectorySource[]
	 */
	public function sources(): array {
		$sources = [new DirectorySource(
			$this->configService->getCloudHost(),
			DirectorySource::KIND_LOCAL,
			$this->configService->getCloudHost()
		)];

		foreach ($this->configured() ?? self::BUILTIN as $entry) {
			$source = $this->fromDefinition(is_array($entry) ? $entry : []);
			if ($source !== null) {
				$sources[] = $source;
			}
		}

		foreach ([...$this->peerSources(), ...$this->discoveredSources()] as $source) {
			$sources[] = $source;
		}

		return $this->deduplicated($sources);
	}

	/**
	 * The servers this instance actually federates with, most-known first.
	 *
	 * An editorial list is somebody else's idea of where people are; this is
	 * this instance's own. The servers its accounts already follow people on
	 * are, by definition, the ones its people are interested in, and asking
	 * them costs nothing that following them did not already cost. Ranked by
	 * how many of their accounts are cached here, because that is the
	 * strongest signal available for "we deal with them a lot".
	 *
	 * The software has to be known before a server can be asked, since the
	 * kind decides the API — so each one is asked its NodeInfo once and the
	 * answer is kept for a week. A server whose software this cannot speak to
	 * is left out rather than asked in a language it does not answer.
	 *
	 * @return DirectorySource[]
	 */
	private function peerSources(): array {
		$raw = trim((string)$this->configService->getAppValue(self::CONFIG_PEERS));
		$wanted = ($raw === '') ? self::PEERS_DEFAULT : (int)$raw;
		if ($wanted < 1) {
			return [];
		}

		$sources = [];
		foreach ($this->instanceStatsRequest->remoteHostCounts() as $host => $seen) {
			if (count($sources) >= $wanted) {
				break;
			}

			$host = (string)$host;
			if ($host === '' || !$this->allowed($host)) {
				continue;
			}

			$kind = $this->softwareKind($host);
			if ($kind === null) {
				continue;
			}

			$sources[] = new DirectorySource($host, $kind, $host, DirectorySource::ORIGIN_FEDERATION);
		}

		return $sources;
	}

	/**
	 * Servers a directory of servers says exist.
	 *
	 * The list carries each server's software, so nothing has to be sniffed,
	 * and it is kept for a day: which servers exist is not news that changes
	 * between two searches. It is the weakest of the three ways a source gets
	 * here — somebody else's editorial choice about servers this instance has
	 * never spoken to — so it comes last and contributes few.
	 *
	 * @return DirectorySource[]
	 */
	private function discoveredSources(): array {
		if (trim((string)$this->configService->getAppValue(self::CONFIG_DISCOVERY)) === '0') {
			return [];
		}

		$cached = $this->cache->get('discovery');
		if (!is_string($cached)) {
			try {
				$listed = $this->curlService->retrieveJson(
					'get',
					'https://' . self::DISCOVERY_HOST . self::DISCOVERY_PATH,
					['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
				);
				$cached = json_encode($listed['data'] ?? $listed);
			} catch (Throwable $e) {
				$this->logger->debug('[FediverseDirectoryService] no server list', ['exception' => $e]);
				// remembered as empty as well, so a directory that is down is
				// not asked again on the next keystroke
				$cached = '[]';
			}

			$this->cache->set('discovery', $cached, self::DISCOVERY_TTL);
		}

		$rows = json_decode($cached, true);
		$sources = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			if (count($sources) >= self::DISCOVERY_LIMIT) {
				break;
			}

			$host = strtolower(trim((string)(is_array($row) ? ($row['domain'] ?? '') : '')));
			$software = strtolower(trim((string)(is_array($row) ? ($row['software_name'] ?? '') : '')));
			$kind = self::SOFTWARE_KINDS[$software] ?? null;
			if ($host === '' || $kind === null || !$this->allowed($host)) {
				continue;
			}

			$sources[] = new DirectorySource($host, $kind, $host, DirectorySource::ORIGIN_DISCOVERED);
		}

		return $sources;
	}

	/**
	 * What software a server runs, from its NodeInfo, or null when it does not
	 * say or runs something none of these APIs fit.
	 */
	private function softwareKind(string $host): ?string {
		$key = 'software/' . $host;
		$known = $this->cache->get($key);
		if (is_string($known)) {
			return ($known === '') ? null : $known;
		}

		$kind = null;
		try {
			$index = $this->curlService->retrieveJson(
				'get',
				'https://' . $host . '/.well-known/nodeinfo',
				['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
			);

			$document = '';
			foreach ($index['links'] ?? [] as $link) {
				$href = (string)(is_array($link) ? ($link['href'] ?? '') : '');
				// any schema version: they differ in what else they carry, not
				// in the name of the software
				if ($href !== '' && str_starts_with($href, 'https://' . $host . '/')) {
					$document = $href;
				}
			}

			if ($document !== '') {
				$nodeinfo = $this->curlService->retrieveJson(
					'get',
					$document,
					['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
				);
				$name = strtolower(trim((string)($nodeinfo['software']['name'] ?? '')));
				$kind = self::SOFTWARE_KINDS[$name] ?? null;
			}
		} catch (Throwable $e) {
			$this->logger->debug('[FediverseDirectoryService] no nodeinfo', [
				'host' => $host, 'exception' => $e,
			]);
		}

		// the miss is remembered too: a server that does not publish NodeInfo
		// will not start doing so before the next search
		$this->cache->set($key, $kind ?? '', self::SOFTWARE_TTL);

		return $kind;
	}

	/** Whether this instance is willing to talk to that host at all. */
	private function allowed(string $host): bool {
		if ($host === $this->configService->getCloudHost()) {
			return false;
		}

		try {
			$this->fediverseService->authorized($host);
		} catch (Throwable $e) {
			return false;
		}

		return true;
	}

	/**
	 * One entry per host, the first to claim it winning: a server named in the
	 * configured list and again by federation is one source, asked once, and
	 * keeps the label and the origin the administrator gave it.
	 *
	 * @param DirectorySource[] $sources
	 *
	 * @return DirectorySource[]
	 */
	private function deduplicated(array $sources): array {
		$seen = [];
		foreach ($sources as $source) {
			$seen[$source->getHost()] ??= $source;
		}

		return array_values($seen);
	}

	/**
	 * Asks the sources about `$query` and merges what they say.
	 *
	 * A source that fails is **reported**, not dropped: "nobody by that name"
	 * and "that server did not answer" are different answers and a reader
	 * deciding whether to try another spelling needs to know which one they
	 * got.
	 *
	 * @param string $host one source's host, or '' for all of them
	 *
	 * @return array{accounts: DirectoryAccount[], sources: array<int, array<string, mixed>>}
	 */
	public function search(string $query, string $host = '', int $limit = self::LIMIT): array {
		$query = trim(ltrim(trim($query), '@'));
		$limit = max(1, min(self::MAX_LIMIT, $limit));

		$accounts = [];
		$reports = [];
		$deadline = microtime(true) + self::BUDGET;

		foreach ($this->sources() as $source) {
			if ($host !== '' && $source->getHost() !== $host) {
				continue;
			}

			if (!$source->isLocal() && microtime(true) >= $deadline) {
				$reports[] = $this->report($source, 'skipped', 0);
				continue;
			}

			try {
				$found = $this->ask($source, $query, $limit);
				$reports[] = $this->report($source, 'ok', count($found));
				foreach ($found as $account) {
					// the first source to name somebody keeps them: sources are
					// asked in the order they are configured, and this instance
					// is asked first, so a person this server already knows is
					// offered as the copy it knows
					$accounts[$account->getAcct()] ??= $account;
				}
			} catch (Throwable $e) {
				$this->logger->debug('[FediverseDirectoryService] a directory did not answer', [
					'host' => $source->getHost(), 'exception' => $e,
				]);
				$reports[] = $this->report($source, 'failed', 0);
			}
		}

		$accounts = $this->withoutBlockedHosts($accounts);
		$this->markKnown($accounts);

		return ['accounts' => array_values($accounts), 'sources' => $reports];
	}

	/**
	 * @return DirectoryAccount[]
	 *
	 * @throws Throwable when the source could not be asked
	 */
	private function ask(DirectorySource $source, string $query, int $limit): array {
		if ($source->isLocal()) {
			return $this->askLocal($query, $limit);
		}

		$cacheKey = $this->cacheKey($source, $query, $limit);
		$cached = $this->cache->get($cacheKey);
		if (is_string($cached)) {
			return $this->decode($cached, $source);
		}

		$found = match ($source->getKind()) {
			DirectorySource::KIND_MISSKEY => $this->askMisskey($source, $query, $limit),
			DirectorySource::KIND_LEMMY => $this->askLemmy($source, $query, $limit),
			DirectorySource::KIND_WORDPRESS => $this->askWordpress($source, $query, $limit),
			default => $this->askMastodon($source, $query, $limit),
		};

		$this->cache->set($cacheKey, json_encode(array_map(
			static fn (DirectoryAccount $account): array => $account->jsonSerialize(), $found
		)), self::CACHE_TTL);

		return $found;
	}

	/**
	 * This instance's own people: the ones who opted in to being listed, and
	 * the remote accounts it already holds.
	 *
	 * No network, so it is never the reason a search is slow, and it is asked
	 * even when every other source is unreachable.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askLocal(string $query, int $limit): array {
		$host = $this->configService->getCloudHost();

		$people = ($query === '')
			? $this->directoryService->page('active', $limit, 0)
			: $this->cacheActorService->searchCachedAccounts($query);

		$found = [];
		foreach (array_slice($people, 0, $limit) as $person) {
			if (!($person instanceof Person)) {
				continue;
			}

			$acct = $person->getAccount();
			if ($acct === '') {
				continue;
			}

			$account = new DirectoryAccount(
				str_contains($acct, '@') ? $acct : $acct . '@' . $host,
				$host,
				DirectorySource::KIND_LOCAL
			);
			$account->setDisplayName($person->getDisplayName() ?: $person->getPreferredUsername())
				->setNote($person->getDescription())
				->setAvatar($person->getAvatar())
				->setUrl($person->getId())
				->setKnown(true);

			$found[] = $account;
		}

		return $found;
	}

	/**
	 * A Mastodon-compatible source, asked the two public questions it has.
	 *
	 * The lookup is exact and is what finds a person somebody half-remembers
	 * the handle of. The directory page is who that server is happy to be
	 * known for, and the query is applied here because the endpoint takes
	 * none — which is why an empty query is the *better* case for this kind of
	 * source rather than the degenerate one.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askMastodon(DirectorySource $source, string $query, int $limit): array {
		$found = [];

		if ($query !== '' && !str_contains($query, ' ')) {
			try {
				$exact = $this->get($source, '/api/v1/accounts/lookup', ['acct' => $query]);
				$account = $this->fromMastodon($exact, $source);
				if ($account !== null) {
					$found[$account->getAcct()] = $account;
				}
			} catch (Throwable $e) {
				// no such handle there, which is an answer rather than a failure
				$this->logger->debug('[FediverseDirectoryService] no exact handle', [
					'host' => $source->getHost(), 'exception' => $e,
				]);
			}
		}

		$page = $this->get($source, '/api/v1/directory', [
			'limit' => self::DIRECTORY_PAGE, 'offset' => 0, 'order' => 'active', 'local' => 'true',
		]);

		foreach ($page as $row) {
			if (count($found) >= $limit) {
				break;
			}

			$account = $this->fromMastodon(is_array($row) ? $row : [], $source);
			if ($account !== null && $this->matches($account, $query)) {
				$found[$account->getAcct()] ??= $account;
			}
		}

		return array_values($found);
	}

	/**
	 * Misskey, which has a real substring search over names and handles.
	 *
	 * `origin: combined` rather than `local`: a Misskey server knows about
	 * people elsewhere too, and a reader looking for somebody does not care
	 * which server was the one that had heard of them.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askMisskey(DirectorySource $source, string $query, int $limit): array {
		if ($query === '') {
			// its only listing endpoint is `users`, which is a different
			// question with a different shape; an unsearched Misskey source
			// contributes nothing rather than something misleading
			return [];
		}

		$rows = $this->post($source, '/api/users/search', [
			'query' => $query, 'limit' => $limit, 'detail' => true, 'origin' => 'combined',
		]);

		$found = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$username = trim((string)($row['username'] ?? ''));
			if ($username === '') {
				continue;
			}

			// `host` is null for that server's own people, which is the one
			// thing that makes a Misskey handle ambiguous if taken at face
			// value
			$host = trim((string)($row['host'] ?? '')) ?: $source->getHost();

			$account = new DirectoryAccount($username . '@' . $host, $source->getHost(), $source->getKind());
			$account->setDisplayName((string)($row['name'] ?? ''))
				->setNote((string)($row['description'] ?? ''))
				->setAvatar((string)($row['avatarUrl'] ?? ''))
				->setUrl((string)($row['uri'] ?? '') ?: 'https://' . $host . '/@' . $username)
				->setFollowersCount((int)($row['followersCount'] ?? -1))
				->setStatusesCount((int)($row['notesCount'] ?? -1))
				->setBot((bool)($row['isBot'] ?? false));

			$found[] = $account;
		}

		return $found;
	}

	/**
	 * A directory somebody keeps by hand, published as a WordPress site.
	 *
	 * Its REST API searches the entries, and an entry is a person written
	 * about rather than a row in a user table — so this is the one source
	 * that can answer a *subject*. What comes back is prose, and the handle
	 * is in it: `@someone@example.org`, which is how those entries name
	 * people because it is how the fediverse names people.
	 *
	 * A handle is only ever read out of an entry's own text. Nothing follows
	 * the links in it, so an entry cannot make this instance fetch an address
	 * somebody else chose.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askWordpress(DirectorySource $source, string $query, int $limit): array {
		if ($query === '') {
			// the endpoint is a search; with nothing to search for it answers
			// with whatever is newest, which is not a directory
			return [];
		}

		$rows = $this->get($source, '/wp-json/wp/v2/posts', [
			'search' => $query,
			'per_page' => min($limit, self::WORDPRESS_PAGE),
			'_fields' => 'title,excerpt,content,link',
		]);

		$found = [];
		foreach ($rows as $row) {
			if (count($found) >= $limit) {
				break;
			}

			$row = is_array($row) ? $row : [];
			$handle = $this->handleIn(
				(string)($row['content']['rendered'] ?? '') . ' ' . (string)($row['excerpt']['rendered'] ?? '')
			);
			if ($handle === '') {
				continue;
			}

			$host = substr($handle, (int)strrpos($handle, '@') + 1);
			if (!$this->allowed($host)) {
				continue;
			}

			$account = new DirectoryAccount($handle, $host, $source->getKind());
			$account->setDisplayName(trim(html_entity_decode(strip_tags((string)($row['title']['rendered'] ?? '')))))
				->setNote(trim(html_entity_decode(strip_tags((string)($row['excerpt']['rendered'] ?? '')))))
				->setUrl((string)($row['link'] ?? ''));

			$found[$handle] ??= $account;
		}

		return array_values($found);
	}

	/**
	 * The first fediverse handle written in a piece of prose.
	 *
	 * Deliberately strict about what a handle is — letters, digits, dot, dash
	 * and underscore around a single `@`, and a host with a dot in it. An
	 * entry that names an account some other way contributes nobody, which is
	 * better than contributing something that is not an account.
	 */
	private function handleIn(string $text): string {
		$text = html_entity_decode(strip_tags($text));
		if (preg_match('/@([A-Za-z0-9_.-]+)@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)/', $text, $match) !== 1) {
			return '';
		}

		return strtolower($match[1] . '@' . $match[2]);
	}

	/**
	 * Lemmy, whose search is public and whose people carry their full actor id
	 * — so the handle is read out of that rather than assembled, and a person
	 * that Lemmy server merely knows about is attributed to the server they
	 * are actually on.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askLemmy(DirectorySource $source, string $query, int $limit): array {
		if ($query === '') {
			return [];
		}

		$answer = $this->get($source, '/api/v3/search', [
			'q' => $query, 'type_' => 'Users', 'listing_type' => 'All', 'limit' => $limit,
		]);

		$found = [];
		foreach ((array)($answer['users'] ?? []) as $row) {
			$person = is_array($row) ? (array)($row['person'] ?? []) : [];
			$name = trim((string)($person['name'] ?? ''));
			$actorId = (string)($person['actor_id'] ?? '');
			$host = (string)parse_url($actorId, PHP_URL_HOST);
			if ($name === '' || $host === '') {
				continue;
			}

			$account = new DirectoryAccount($name . '@' . $host, $source->getHost(), $source->getKind());
			$account->setDisplayName((string)($person['display_name'] ?? ''))
				->setNote((string)($person['bio'] ?? ''))
				->setAvatar((string)($person['avatar'] ?? ''))
				->setUrl($actorId)
				->setBot((bool)($person['bot_account'] ?? false));

			$found[] = $account;
		}

		return $found;
	}

	/**
	 * @param array<string, mixed> $row one Mastodon `Account`
	 */
	private function fromMastodon(array $row, DirectorySource $source): ?DirectoryAccount {
		$acct = trim((string)($row['acct'] ?? ''));
		if ($acct === '') {
			return null;
		}

		// a Mastodon `acct` is bare for that server's own people and
		// `user@host` for everybody else
		if (!str_contains($acct, '@')) {
			$acct .= '@' . $source->getHost();
		}

		$account = new DirectoryAccount($acct, $source->getHost(), $source->getKind());
		$account->setDisplayName((string)($row['display_name'] ?? ''))
			->setNote((string)($row['note'] ?? ''))
			->setAvatar((string)($row['avatar'] ?? ''))
			->setUrl((string)($row['url'] ?? ''))
			->setFollowersCount((int)($row['followers_count'] ?? -1))
			->setStatusesCount((int)($row['statuses_count'] ?? -1))
			->setBot((bool)($row['bot'] ?? false));

		return $account;
	}

	/**
	 * Whether a directory row answers the query, for the one kind of source
	 * that cannot be asked.
	 */
	private function matches(DirectoryAccount $account, string $query): bool {
		if ($query === '') {
			return true;
		}

		$needle = mb_strtolower($query);
		foreach ([$account->getAcct(), $account->getDisplayName(), $account->getNote()] as $field) {
			if (str_contains(mb_strtolower($field), $needle)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drops everybody on a host this instance will not federate with.
	 *
	 * The fetch itself is already guarded — `CurlService` refuses a host the
	 * access list excludes — but that guards the server being *asked*, and
	 * these are people it is telling us about. Offering a follow button for
	 * somebody on a blocked domain would be this instance recommending exactly
	 * what it refuses to deliver to.
	 *
	 * @param array<string, DirectoryAccount> $accounts
	 *
	 * @return array<string, DirectoryAccount>
	 */
	private function withoutBlockedHosts(array $accounts): array {
		return array_filter(
			$accounts,
			function (DirectoryAccount $account): bool {
				$host = $account->getHost();
				if ($host === '') {
					return false;
				}

				try {
					$this->fediverseService->authorized($host);
				} catch (Throwable $e) {
					return false;
				}

				return !$this->fediverseService->isSilenced($host);
			}
		);
	}

	/**
	 * Marks the people this instance already holds, in one pass over what it
	 * has rather than a lookup per row.
	 *
	 * Nothing is fetched here. A row that is not marked is not a row that is
	 * unknown to the fediverse — only one this server has not met yet.
	 *
	 * @param array<string, DirectoryAccount> $accounts
	 */
	private function markKnown(array $accounts): void {
		foreach ($accounts as $account) {
			if ($account->isKnown()) {
				continue;
			}

			try {
				$this->cacheActorService->getFromAccount($account->getAcct(), false);
				$account->setKnown(true);
			} catch (Throwable $e) {
				// not held here, which is the ordinary case for a search that
				// went looking somewhere else
			}
		}
	}

	/**
	 * @param array<string, string|int> $params
	 *
	 * @return array<mixed>
	 */
	private function get(DirectorySource $source, string $path, array $params): array {
		return $this->curlService->retrieveJson(
			'get',
			'https://' . $source->getHost() . $path . '?' . http_build_query($params),
			// a plain REST endpoint, so plain REST headers: the ActivityPub
			// `Accept` this service otherwise sends asks a fediverse server for
			// the other representation of whatever it is being asked about
			['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
		);
	}

	/**
	 * @param array<string, mixed> $body
	 *
	 * @return array<mixed>
	 */
	private function post(DirectorySource $source, string $path, array $body): array {
		return $this->curlService->retrieveJson(
			'post',
			'https://' . $source->getHost() . $path,
			[
				'timeout' => self::TIMEOUT,
				'body' => (string)json_encode($body),
				'json_headers' => false,
				'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
			]
		);
	}

	/**
	 * @return array{host: string, kind: string, label: string, status: string, count: int}
	 */
	private function report(DirectorySource $source, string $status, int $count): array {
		return $source->jsonSerialize() + ['status' => $status, 'count' => $count];
	}

	private function cacheKey(DirectorySource $source, string $query, int $limit): string {
		return sha1($source->getKind() . '|' . $source->getHost() . '|' . mb_strtolower($query) . '|' . $limit);
	}

	/**
	 * @return DirectoryAccount[]
	 */
	private function decode(string $raw, DirectorySource $source): array {
		$rows = json_decode($raw, true);
		if (!is_array($rows)) {
			return [];
		}

		$accounts = [];
		foreach ($rows as $row) {
			if (!is_array($row) || ($row['acct'] ?? '') === '') {
				continue;
			}

			$account = new DirectoryAccount((string)$row['acct'], $source->getHost(), $source->getKind());
			$account->setDisplayName((string)($row['display_name'] ?? ''))
				->setNote((string)($row['note'] ?? ''))
				->setAvatar((string)($row['avatar'] ?? ''))
				->setUrl((string)($row['url'] ?? ''))
				->setFollowersCount((int)($row['followers_count'] ?? -1))
				->setStatusesCount((int)($row['statuses_count'] ?? -1))
				->setBot((bool)($row['bot'] ?? false));

			$accounts[] = $account;
		}

		return $accounts;
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private function fromDefinition(array $definition): ?DirectorySource {
		$host = strtolower(trim((string)($definition['host'] ?? '')));
		// a host and nothing else: somebody will write a URL here, and the
		// scheme and path are this class's to decide
		$host = (string)(parse_url(str_contains($host, '://') ? $host : 'https://' . $host, PHP_URL_HOST) ?? '');
		if ($host === '') {
			return null;
		}

		$kind = strtolower(trim((string)($definition['kind'] ?? DirectorySource::KIND_MASTODON)));
		if (!in_array($kind, DirectorySource::KINDS, true) || $kind === DirectorySource::KIND_LOCAL) {
			// an unknown kind is read as the one most servers speak rather than
			// dropped: a typo in a config value must not be why a source is
			// missing from a page
			$kind = DirectorySource::KIND_MASTODON;
		}

		return new DirectorySource($host, $kind, trim((string)($definition['label'] ?? '')));
	}

	/**
	 * @return array<mixed>|null null when there is nothing usable configured
	 */
	private function configured(): ?array {
		$raw = trim((string)$this->configService->getAppValue(self::CONFIG_KEY));
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			$this->logger->warning(
				'[FediverseDirectoryService] the ' . self::CONFIG_KEY . ' app value is not a JSON array; ignoring it'
			);

			return null;
		}

		return $decoded;
	}
}
