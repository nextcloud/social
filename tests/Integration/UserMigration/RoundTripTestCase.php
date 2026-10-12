<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\UserMigration;

use OCA\Social\AP;
use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\FilterController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\RelationController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\MigrationArchiveService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\PostImportService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Integration\Api\ApiResponse;
use OCA\Social\Tests\Integration\Api\ApiTestCase;
use OCP\Server;

/**
 * What both halves of an account round trip share: the accounts of another
 * server, cached here the way a fetch caches them so nothing reaches for the
 * network, and the *views* — the Mastodon API's own answers about one
 * account, reduced to what means the same thing on any server.
 *
 * A view names accounts by `user@host` and posts by their URI, never by a
 * row id: those are numbers of the server that answered, and the point is to
 * hold the answers of two servers against each other.
 */
abstract class RoundTripTestCase extends ApiTestCase {
	/** the server the remote accounts of the scenario live on */
	protected const HOST = 'roundtrip.example';
	/** the server the account blocks as a whole */
	protected const BLOCKED_DOMAIN = 'spam.roundtrip.example';
	/** the account that is exported */
	protected const SOURCE = 'alice';
	/** the account an archive is imported into */
	protected const TARGET = 'dora';

	/** the files of a bundle: the archive, and what the exporting server saw */
	protected const BUNDLE_ARCHIVE = 'archive.zip';
	protected const BUNDLE_VIEWS = 'expected.json';

	/** @var string[] actor ids of other servers this test cached, removed again in tearDown */
	private array $seeded = [];

	#[\Override]
	protected function accountNames(): array {
		return ['alice', 'bob', 'carol', 'dora'];
	}

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->forgetHost();
	}

	#[\Override]
	protected function tearDown(): void {
		$this->forgetSeeded();
		$this->forgetHost();
		parent::tearDown();
	}

	/** An account of `roundtrip.example`, cached as a fetch caches one. */
	protected function remote(string $name): Person {
		return $this->seedActor([
			'id' => 'https://' . self::HOST . '/users/' . $name,
			'username' => $name,
			'account' => $name . '@' . self::HOST,
			'name' => ucfirst($name),
		]);
	}

	/**
	 * Caches an account of another server from what a view or a seed holds
	 * of it, and answers it as it is cached.
	 *
	 * @param array{id: string, username: string, account: string, name: string} $seed
	 */
	protected function seedActor(array $seed): Person {
		$id = $seed['id'];
		$actor = new Person();
		$actor->setId($id)
			->setPreferredUsername($seed['username'])
			->setName($seed['name'])
			->setAccount($seed['account'])
			->setInbox($id . '/inbox')
			->setSharedInbox((string)parse_url($id, PHP_URL_SCHEME) . '://' . (string)parse_url($id, PHP_URL_HOST) . '/inbox')
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setOutbox($id . '/outbox')
			->setUrl($id);
		$actor->setCreation(time() - 3600);
		$actor->setSource((string)json_encode(['id' => $id, 'type' => Person::TYPE]));
		Server::get(CacheActorsRequest::class)->save($actor);
		$this->seeded[] = $id;

		return Server::get(CacheActorsRequest::class)->getFromId($id);
	}

	/**
	 * A public post of an account of another server, stored the way the inbox
	 * stores one that arrived.
	 *
	 * @param array{id: string, attributedTo: string, content: string, published: string} $seed
	 * @return string the post's row id, which the status routes take
	 */
	protected function seedPost(array $seed): string {
		$note = new Note();
		$note->setId($seed['id']);
		$note->setAttributedTo($seed['attributedTo']);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$seed['attributedTo'] . '/followers']);
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setContent($seed['content']);
		$note->setPublished($seed['published']);
		$note->convertPublished();
		$note->setLocal(false);
		Server::get(StreamRequest::class)->save($note);

		return (string)Server::get(StreamRequest::class)->getStreamById($seed['id'])->getNid();
	}

	/**
	 * Delivers an activity to the inbox the way ActivityPubController does
	 * once the request's signature named the actor's host.
	 *
	 * @param array<string, mixed> $activity
	 */
	protected function deliver(array $activity): void {
		$importService = Server::get(ImportService::class);
		$item = $importService->importFromJson((string)json_encode($activity, JSON_UNESCAPED_SLASHES));
		$item->setOrigin((string)parse_url($item->getActorId(), PHP_URL_HOST), SignatureService::ORIGIN_HEADER, time());
		$importService->parseIncomingRequest($item);
	}

	/** The `user@host` a local account goes by on this server. */
	protected function handleOf(string $name): string {
		return $this->account($name)->getAccount();
	}

	/**
	 * Everything the Mastodon API says about one account, in the terms that
	 * survive a move.
	 *
	 * @param string[] $handles the accounts whose relationship to this one
	 *                          is asked about
	 * @return array<string, mixed>
	 */
	protected function views(string $name, array $handles): array {
		$own = (string)$this->account($name)->getNid();
		$credentials = $this->ok($this->call(AccountApiController::class, 'verifyCredentials', [], [], $name));

		$lists = [];
		foreach ($this->ok($this->call(ListController::class, 'index', [], [], $name)) as $list) {
			$members = $this->ok($this->call(ListController::class, 'accounts', ['id' => (int)$list->id], [], $name));
			$lists[$list->title] = [
				'replies_policy' => $list->replies_policy,
				'exclusive' => $list->exclusive,
				'accounts' => $this->handles($members, $name),
			];
		}
		ksort($lists);

		$filters = [];
		foreach ($this->ok($this->call(FilterController::class, 'index', [], [], $name)) as $filter) {
			$context = $filter->context;
			sort($context);
			$filters[$filter->title] = [
				'context' => $context,
				'filter_action' => $filter->filter_action,
				'keywords' => array_map(
					static fn (\stdClass $keyword): array => [$keyword->keyword, $keyword->whole_word],
					$filter->keywords
				),
			];
		}

		$domains = $this->ok($this->call(RelationController::class, 'domainBlocks', [], [], $name));
		sort($domains);

		return [
			'profile' => [
				'note' => $credentials->source->note,
				'fields' => array_map(
					static fn (\stdClass $field): array => [$field->name, $field->value],
					$credentials->source->fields
				),
				'locked' => $credentials->locked,
				'discoverable' => $credentials->discoverable,
				'indexable' => $credentials->indexable,
				'header' => $this->storedPictureSize((string)$credentials->header),
			],
			'relationships' => $this->relationships($name, $handles),
			'following' => $this->handles($this->ok($this->call(AccountApiController::class, 'accountFollowing', ['account' => $own], ['limit' => 80], $name)), $name),
			'followers' => $this->handles($this->ok($this->call(AccountApiController::class, 'accountFollowers', ['account' => $own], ['limit' => 80], $name)), $name),
			'mutes' => $this->handles($this->ok($this->call(AccountApiController::class, 'mutes', [], [], $name)), $name),
			'blocks' => $this->handles($this->ok($this->call(AccountApiController::class, 'blocks', [], [], $name)), $name),
			'lists' => $lists,
			'filters' => $filters,
			'domain_blocks' => $domains,
			'bookmarks' => $this->uris($this->ok($this->call(TimelineApiController::class, 'bookmarks', [], ['limit' => 40], $name))),
			'favourites' => $this->uris($this->ok($this->call(TimelineApiController::class, 'favourites', [], ['limit' => 40], $name))),
			'statuses' => $this->statuses($name),
			'aliases' => Server::get(MigrationService::class)->listAliases(self::PREFIX . $name),
		];
	}

	/**
	 * The account's own posts, oldest first, as what was written rather than
	 * where: the text, the warning, the audience, when, the pictures and the
	 * poll.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function statuses(string $name): array {
		$statuses = $this->ok($this->call(
			AccountApiController::class, 'accountStatuses', ['account' => (string)$this->account($name)->getNid()], ['limit' => 40], $name
		));

		$views = array_map(static fn (\stdClass $status): array => [
			'text' => trim(html_entity_decode(strip_tags((string)$status->content))),
			'spoiler_text' => $status->spoiler_text,
			'sensitive' => $status->sensitive,
			'visibility' => $status->visibility,
			'created_at' => $status->created_at,
			'media' => array_map(static fn (\stdClass $media): array => [
				'type' => $media->type,
				'description' => $media->description,
				'size' => [$media->meta->original->width ?? null, $media->meta->original->height ?? null],
			], $status->media_attachments),
			'poll' => ($status->poll === null)
				? null : array_map(static fn (\stdClass $option): string => $option->title, $status->poll->options),
		], $statuses);
		usort($views, static fn (array $a, array $b): int => strcmp($a['created_at'], $b['created_at']) ?: strcmp($a['text'], $b['text']));

		return $views;
	}

	/**
	 * How this account stands towards each of the others, by handle.
	 *
	 * `following` is a follow either way it can stand — accepted, or asked
	 * for and not answered yet. Which of the two depends on the other server
	 * and how fast it answers, not on what the archive carried.
	 *
	 * @param string[] $handles
	 * @return array<string, array<string, mixed>>
	 */
	private function relationships(string $name, array $handles): array {
		$ids = [];
		foreach ($handles as $handle) {
			try {
				$ids[(string)Server::get(CacheActorService::class)->getFromAccount($handle, false)->getNid()] = strtolower($handle);
			} catch (CacheActorDoesNotExistException) {
				$this->fail($handle . ' is not cached on this server');
			}
		}

		$answer = $this->ok($this->call(AccountApiController::class, 'relationships', [], ['id' => array_map('strval', array_keys($ids))], $name));
		$relationships = [];
		foreach ($answer as $relationship) {
			$relationships[$ids[$relationship->id]] = [
				'following' => $relationship->following || $relationship->requested,
				'followed_by' => $relationship->followed_by,
				'blocking' => $relationship->blocking,
				'muting' => $relationship->muting,
				'muting_notifications' => $relationship->muting_notifications,
				'mute_expires_at' => $relationship->mute_expires_at,
				'domain_blocking' => $relationship->domain_blocking,
			];
		}
		ksort($relationships);

		return $relationships;
	}

	/**
	 * The `user@host` of every account of a list, sorted. A local account's
	 * `acct` is its bare name, so this server's own host is put after it.
	 *
	 * @param list<\stdClass> $accounts
	 * @return string[]
	 */
	protected function handles(array $accounts, string $name): array {
		$host = substr((string)strrchr($this->handleOf($name), '@'), 1);
		$handles = array_map(
			static fn (\stdClass $account): string => strtolower(str_contains($account->acct, '@') ? $account->acct : $account->acct . '@' . $host),
			$accounts
		);
		sort($handles);

		return $handles;
	}

	/**
	 * @param list<\stdClass> $statuses
	 * @return string[]
	 */
	private function uris(array $statuses): array {
		$uris = array_map(static fn (\stdClass $status): string => $status->uri, $statuses);
		sort($uris);

		return $uris;
	}

	/**
	 * The pixel size of a picture this server stored a copy of, or null when
	 * the URL names no copy of its own.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	protected function storedPictureSize(string $url): ?array {
		$name = pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name) !== 1) {
			return null;
		}

		$stream = Server::get(CacheDocumentService::class)->getFromUuid($name)->read();
		$this->assertIsResource($stream);
		$size = getimagesizefromstring((string)stream_get_contents($stream));
		fclose($stream);
		$this->assertNotFalse($size, $url . ' is a picture');

		return [$size[0], $size[1]];
	}

	/**
	 * The import half: the archive of a bundle into `dora`, then the posts of
	 * the same archive through the posts import, each held against the views
	 * the exporting server wrote down.
	 *
	 * Where the bundle came from another server, what this one would have
	 * fetched while importing — the accounts and posts the archive names —
	 * is cached first, from the bundle, since there is no network to fetch
	 * it from.
	 */
	protected function importAndCompare(string $bundle): void {
		$manifest = json_decode((string)file_get_contents($bundle . '/' . self::BUNDLE_VIEWS), true, 512, JSON_THROW_ON_ERROR);
		$archive = $bundle . '/' . self::BUNDLE_ARCHIVE;
		$expected = $manifest['views'];
		$handles = $manifest['handles'];

		if ($manifest['socialUrl'] !== $this->socialUrl()) {
			foreach ($manifest['seed']['actors'] as $actor) {
				$this->seedActor($actor);
			}
			foreach ($manifest['seed']['posts'] as $post) {
				$this->seedPost($post);
			}
		}

		$fresh = $this->views(self::TARGET, $handles);
		foreach (['note', 'fields', 'header'] as $part) {
			$this->assertNotEquals($expected['profile'][$part], $fresh['profile'][$part], 'a fresh account has no ' . $part . ' of its own yet');
		}

		$log = Server::get(MigrationArchiveService::class)->import(self::PREFIX . self::TARGET, $archive);
		$imported = $this->views(self::TARGET, $handles);
		$why = "\nthe import said:\n" . implode("\n", $log);

		$this->assertSame($expected['profile'], $imported['profile'], 'the profile, the flags and the banner travel' . $why);
		$this->assertContains($manifest['actorId'], $imported['aliases'], 'the old actor is an alias, so a Move from it is accepted here' . $why);

		foreach ($expected['relationships'] as $handle => $relationship) {
			// followers are a record: a follower follows again, or is moved by
			// the Move of the old account — nothing an import can make
			$relationship['followed_by'] = false;
			$this->assertSame($relationship, $imported['relationships'][$handle], 'the relationship to ' . $handle . $why);
		}
		$this->assertNotSame([], $expected['followers']);
		$this->assertSame([], $imported['followers'], 'the followers do not travel' . $why);

		foreach (['mutes', 'blocks', 'domain_blocks', 'bookmarks', 'favourites'] as $view) {
			$this->assertNotSame([], $expected[$view], 'the exported account has ' . $view);
			$this->assertSame($expected[$view], $imported[$view], $view . $why);
		}

		// lists.csv is Mastodon's shape, a title and an account a row: what a
		// list does with replies, and whether it is exclusive, stays behind
		$this->assertSame(array_keys($expected['lists']), array_keys($imported['lists']), 'the lists' . $why);
		foreach ($expected['lists'] as $title => $list) {
			$this->assertSame($list['accounts'], $imported['lists'][$title]['accounts'], 'the members of ' . $title . $why);
			$this->assertSame(MastodonList::DEFAULT_REPLIES_POLICY, $imported['lists'][$title]['replies_policy']);
			$this->assertFalse($imported['lists'][$title]['exclusive']);
		}

		// filters are not in the archive
		$this->assertNotSame([], $expected['filters']);
		$this->assertSame([], $imported['filters'], 'the filters do not travel' . $why);

		// the account import does not write posts: their ids belong to the old server
		$this->assertSame([], $imported['statuses'], 'the account import re-creates no post' . $why);

		$tally = Server::get(PostImportService::class)->import(
			Server::get(AccountService::class)->getActorFromUserId(self::PREFIX . self::TARGET), $archive, false
		);
		$this->assertSame(count($expected['statuses']), $tally['imported'], (string)json_encode($tally));

		$posts = $this->statuses(self::TARGET);
		foreach ($expected['statuses'] as $index => $status) {
			// the posts import writes the text, not the poll
			if ($status['poll'] !== null) {
				$this->assertNull($posts[$index]['poll'], 'a poll comes back as its text');
				$status['poll'] = null;
			}
			$this->assertSame($status, $posts[$index], 'post ' . $index . ' as it was written');
		}
	}

	/**
	 * Writes the bundle the import half reads: the archive, and the views of
	 * the exported account with what the importing server needs cached.
	 *
	 * @param array<string, mixed> $manifest
	 */
	protected function writeBundle(string $dir, string $archive, array $manifest): void {
		if (!is_dir($dir)) {
			$this->assertTrue(mkdir($dir, 0700, true), 'cannot make ' . $dir);
		}
		$this->assertTrue(copy($archive, $dir . '/' . self::BUNDLE_ARCHIVE));
		file_put_contents($dir . '/' . self::BUNDLE_VIEWS, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	/** The body of a 200, or a failure naming what came back instead. */
	protected function ok(ApiResponse $response): mixed {
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return $response->body;
	}

	/** The address this server's accounts are under. */
	protected function socialUrl(): string {
		return Server::get(ConfigService::class)->getSocialUrl();
	}

	/**
	 * Takes away every account of another server this test cached, with what
	 * it wrote and every relation to it.
	 */
	private function forgetSeeded(): void {
		foreach (array_unique($this->seeded) as $id) {
			$this->forgetActor($id);
		}
		$this->seeded = [];
	}

	/** What an aborted run left of `roundtrip.example` here. */
	private function forgetHost(): void {
		foreach (Server::get(CacheActorsRequest::class)->getIdsFromDomain(self::HOST, 1000) as $id) {
			$this->forgetActor($id);
		}
	}

	private function forgetActor(string $id): void {
		try {
			$actor = Server::get(CacheActorsRequest::class)->getFromId($id);
			AP::instance()->getInterfaceFromType(Person::TYPE)->delete($actor);
		} catch (CacheActorDoesNotExistException) {
		}
		Server::get(CacheActorsRequest::class)->deleteCacheById($id);
	}
}
