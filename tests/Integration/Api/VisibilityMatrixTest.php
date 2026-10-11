<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OC\AppFramework\DependencyInjection\DIContainer;
use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Controller\ApiController;
use OCA\Social\Controller\ConversationController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\NavigationController;
use OCA\Social\Controller\NotificationController;
use OCA\Social\Controller\SocialPubController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Dashboard\SocialBookmarksWidget;
use OCA\Social\Dashboard\SocialDirectWidget;
use OCA\Social\Dashboard\SocialMentionsWidget;
use OCA\Social\Dashboard\SocialTimelineWidget;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Reference\PostReferenceProvider;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\Service\AuthorizedFetchService;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Search\ISearchQuery;
use OCP\Server;

/**
 * Every read path, asked about every kind of post by every kind of viewer.
 *
 * `VisibilityMatrix` holds the table: who may read which post, and what each
 * path lists of what may be read. This class seeds one scenario — an author
 * with posts of every visibility, a remote account quoting and boosting two
 * of the author's followers-only posts, and a viewer for each relation to the
 * author — and runs every read path over it. A probe answers with what went
 * onto the wire, and a post counts as shown when its own random text is in
 * it: a post leaks as soon as any part of it does, inside a quote, a boost, a
 * notification or a context.
 *
 * A cell that departs from the table fails, and the failure lists every cell
 * of every path that does. A departure that is known and not fixed yet is
 * named in `GAPS`: the test is then marked incomplete instead of failing, so
 * the gap stays visible in every run and the entry has to go once it is
 * closed.
 *
 * The signed ActivityPub fetches hand the controller the viewer's actor as
 * the account that signed the request; what is under test is what a route
 * serves a verified reader, not the signature check, which the unit suite
 * pins.
 */
class VisibilityMatrixTest extends ApiTestCase {
	private const RELAY = 'https://relay.example/vismatrix/users/relay';

	/** the account behind each viewer of the matrix */
	private const NAMES = [
		'author' => 'alice',
		'follower' => 'bob',
		'pending' => 'carol',
		'mentioned' => 'dave',
		'stranger' => 'erin',
		'blocked' => 'frank',
		'muter' => 'grace',
	];

	/**
	 * Departures from the table that are known and not fixed yet, by path and
	 * `post/viewer` cell.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const GAPS = [
	];

	/** @var array<string, array{nid: string, uri: string, owner: string, marker: string}> post => what is read */
	private array $posts = [];

	/** @var array<string, array{nid: string, uri: string, marker: string}> visibility => reply in the thread */
	private array $thread = [];

	private string $root = '';
	private string $rootUri = '';
	private string $tag = '';

	/** @var string[] remote stream ids to remove */
	private array $remoteStreams = [];

	#[\Override]
	protected function accountNames(): array {
		return array_values(self::NAMES);
	}

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->removeRelay();
	}

	#[\Override]
	protected function tearDown(): void {
		$streams = Server::get(StreamRequest::class);
		foreach ($this->remoteStreams as $id) {
			$streams->deleteById($id);
		}
		$this->removeRelay();
		Server::get(IUserSession::class)->setUser(null);
		parent::tearDown();
	}

	/**
	 * One scenario for all the paths, read in the order the table lists them:
	 * seeding the accounts is most of the cost, and a path that marks or
	 * favourites leaves rows a later path may meet, which the table allows
	 * for. Every departure of every path is reported together.
	 */
	public function testEveryReadPathShowsEachPostToExactlyTheViewersTheMatrixNames(): void {
		$this->scenario();

		$departures = [];
		$known = [];
		$closed = [];
		foreach (array_keys(VisibilityMatrix::PATHS) as $path) {
			try {
				$this->readPath($path, $departures, $known, $closed);
			} catch (\Throwable $e) {
				$departures[] = $path . ': the probe failed: ' . get_class($e) . ': ' . $e->getMessage();
			}
		}

		$this->assertSame([], $departures, 'read paths that depart from the visibility matrix');
		$this->assertSame([], $closed, 'these gaps are closed, remove them from GAPS');
		if ($known !== []) {
			$this->markTestIncomplete("known gaps in the visibility matrix:\n" . implode("\n", $known));
		}
	}

	/**
	 * Runs one path over its posts and viewers.
	 *
	 * @param list<string> $departures cells that depart from the table, unexplained
	 * @param list<string> $known cells that depart and are named in GAPS
	 * @param list<string> $closed cells named in GAPS that no longer depart
	 */
	private function readPath(string $path, array &$departures, array &$known, array &$closed): void {
		$probe = $this->probe($path);
		$gaps = self::GAPS[$path] ?? [];
		$shownSomewhere = false;
		foreach (VisibilityMatrix::PATHS[$path]['posts'] as $post) {
			foreach (VisibilityMatrix::PATHS[$path]['viewers'] as $viewer) {
				$expected = VisibilityMatrix::expected($path, $post, $viewer);
				[$anywhere, $shown] = $this->seen($probe($post, $viewer), $post);
				$shownSomewhere = $shownSomewhere || $shown;
				$cell = $path . ' ' . $post . '/' . $viewer;

				$departure = match (true) {
					$expected === false && $anywhere => 'shown, expected hidden',
					$expected === true && !$shown => 'hidden, expected shown',
					default => '',
				};
				if ($departure !== '' && $anywhere && !VisibilityMatrix::mayRead($post, $viewer)) {
					$departure = 'shown to a viewer who may not read it';
				}

				$gap = $gaps[$post . '/' . $viewer] ?? null;
				if ($gap !== null) {
					if ($departure === '') {
						$closed[] = $cell;
					} else {
						$known[] = $cell . ': ' . $gap;
					}
				} elseif ($departure !== '') {
					$departures[] = $cell . ': ' . $departure;
				}
			}
		}

		if (!$shownSomewhere) {
			$departures[] = $path . ': showed nothing to anybody, so the probe does not see what the path serves';
		}
	}

	/**
	 * Whether a probe's answer has the post in it anywhere, and whether it
	 * shows it where it belongs.
	 *
	 * A probe answers with the body that went onto the wire, or with a bool
	 * for a path whose answer is a status code. The two differ for the remote
	 * rows only: the followers-only text has to be inside the quote or the
	 * boost to count as shown by it, since the post itself may be on the same
	 * page for a reader who follows its author; to count as a leak it may be
	 * anywhere.
	 *
	 * @return array{bool, bool} anywhere, shown
	 */
	private function seen(string|bool $answer, string $post): array {
		if (is_bool($answer)) {
			return [$answer, $answer];
		}

		$marker = $this->posts[$post]['marker'];
		$anywhere = str_contains($answer, $marker);
		if (!$anywhere || !in_array($post, VisibilityMatrix::REMOTE_POSTS, true)) {
			return [$anywhere, $anywhere];
		}

		$entity = self::entity(json_decode($answer, true), $this->posts[$post]['nid'], $this->posts[$post]['uri']);

		return [$anywhere, $entity === null || str_contains((string)json_encode($entity), $marker)];
	}

	/**
	 * The entity of an answer that is the post with this id or address, or
	 * null when the answer is not JSON or does not name it.
	 */
	private static function entity(mixed $data, string $nid, string $uri): ?array {
		if (!is_array($data)) {
			return null;
		}
		if (($data['id'] ?? null) === $nid || ($data['uri'] ?? null) === $uri) {
			return $data;
		}
		foreach ($data as $value) {
			$found = self::entity($value, $nid, $uri);
			if ($found !== null) {
				return $found;
			}
		}

		return null;
	}

	/** @return callable(string, string): (string|bool) post, viewer => the body, or whether it was served */
	private function probe(string $path): callable {
		return match ($path) {
			'status' => fn (string $post, string $viewer): string => $this->call(
				StatusApiController::class, 'statusGet', ['nid' => $this->posts[$post]['nid']], [], $this->as($viewer)
			)->raw,
			'context' => $this->contextProbe(),
			'thread' => $this->threadProbe(),
			'favourited_by' => $this->favouritedByProbe(),
			'reblogged_by' => fn (string $post, string $viewer): bool => $this->call(
				StatusApiController::class, 'statusRebloggedBy', ['nid' => $this->posts[$post]['nid']], [], $this->as($viewer)
			)->status === 200,
			'quotes' => fn (string $post, string $viewer): bool => $this->call(
				StatusApiController::class, 'statusQuotes', ['nid' => $this->posts[$post]['nid']], [], $this->as($viewer)
			)->status === 200,
			'account_statuses' => fn (string $post, string $viewer): string => $this->call(
				AccountApiController::class, 'accountStatuses', ['account' => $this->ownerNid($post)], ['limit' => 40], $this->as($viewer)
			)->raw,
			'home' => $this->listing(TimelineApiController::class, 'timelines', ['timeline' => 'home']),
			'app_first_page' => $this->firstPageProbe(),
			'public' => $this->listing(TimelineApiController::class, 'timelines', ['timeline' => 'public']),
			'tag' => $this->listing(TimelineApiController::class, 'tag', ['hashtag' => $this->tag]),
			'list' => $this->listProbe(),
			'search_statuses' => fn (string $post, string $viewer): string => $this->call(
				ApiController::class, 'searchV2', [], ['q' => $this->posts[$post]['marker'], 'type' => 'statuses'], $this->as($viewer)
			)->raw,
			'search_resolve' => fn (string $post, string $viewer): string => $this->call(
				ApiController::class, 'searchV2', [], ['q' => $this->posts[$post]['uri'], 'type' => 'statuses', 'resolve' => true], $this->as($viewer)
			)->raw,
			'notifications' => $this->listing(TimelineApiController::class, 'notifications', []),
			'notifications_v2' => $this->listing(NotificationController::class, 'indexV2', []),
			'bookmark' => $this->actionProbe('bookmark'),
			'bookmarks' => $this->markedProbe('bookmark', 'bookmarks'),
			'favourite' => $this->actionProbe('favourite'),
			'favourites' => $this->markedProbe('favourite', 'favourites'),
			'conversations' => $this->listing(ConversationController::class, 'index', []),
			'activitypub_object' => fn (string $post, string $viewer): string => $this->activityPub(
				'displayPost', ['username' => self::PREFIX . 'alice', 'token' => $this->postToken($this->posts[$post]['uri'])], null
			)->raw,
			'activitypub_object_signed' => fn (string $post, string $viewer): string => $this->activityPub(
				'displayPost', ['username' => self::PREFIX . 'alice', 'token' => $this->postToken($this->posts[$post]['uri'])], $viewer
			)->raw,
			'activitypub_replies' => $this->repliesProbe(),
			'activitypub_likes' => fn (string $post, string $viewer): bool => $this->activityPub(
				'likes', ['username' => self::PREFIX . 'alice', 'token' => $this->postToken($this->posts[$post]['uri'])], $this->signer($viewer)
			)->status === 200,
			'rss' => fn (string $post, string $viewer): string => $this->call(
				ActivityPubController::class, 'feed', ['username' => self::PREFIX . 'alice'], [], null
			)->raw,
			'oembed' => fn (string $post, string $viewer): bool => $this->call(
				InstanceApiController::class, 'oembed', [], ['url' => $this->posts[$post]['uri']], null
			)->status === 200,
			'post_page' => $this->postPageProbe(),
			'unified_search' => $this->unifiedSearchProbe(),
			'reference' => $this->referenceProbe(),
			'widget_timeline' => $this->widgetProbe(SocialTimelineWidget::class),
			'widget_mentions' => $this->widgetProbe(SocialMentionsWidget::class),
			'widget_direct' => $this->widgetProbe(SocialDirectWidget::class),
			'widget_bookmarks' => $this->widgetProbe(SocialBookmarksWidget::class, 'bookmark'),
		};
	}

	/**
	 * The author, with a post of every visibility, every one of them naming
	 * `mentioned` and carrying the same hashtag; a viewer for each relation;
	 * and a remote account every signed-in viewer follows, which quotes one
	 * followers-only post of the author and boosts another.
	 */
	private function scenario(): void {
		foreach (self::NAMES as $name) {
			$this->acceptEveryNotification($name);
		}
		$this->tag = 'vismatrix' . bin2hex(random_bytes(4));

		// a locked account, so that one follow can stay a request
		$locked = $this->call(AccountApiController::class, 'updateCredentials', [], ['locked' => '1'], 'alice', 'PATCH');
		$this->assertSame(200, $locked->status, json_encode($locked->body) ?: '');
		foreach (['bob', 'carol', 'grace'] as $name) {
			$this->follow($name, 'alice');
		}
		foreach (['bob', 'grace'] as $name) {
			$this->relate('alice', 'followRequestAuthorize', $name);
		}
		$this->relate('alice', 'accountBlock', 'frank');
		$this->relate('grace', 'accountMute', 'alice');

		$relay = $this->relay();
		foreach (self::NAMES as $name) {
			$this->followRelay($this->account($name), $relay);
		}

		foreach (['public', 'unlisted', 'followers', 'direct'] as $visibility) {
			$this->posts[$visibility] = $this->authorPost($visibility, $visibility);
		}
		$quoted = $this->authorPost('followers', 'quoted');
		$boosted = $this->authorPost('followers', 'boosted');
		$this->posts['quote'] = $this->relayQuote($relay, $quoted);
		$this->posts['boost'] = $this->relayBoost($relay, $boosted);
	}

	/** @return array{nid: string, uri: string, owner: string, marker: string} */
	private function authorPost(string $visibility, string $label): array {
		$marker = 'vm' . bin2hex(random_bytes(6)) . $label;
		$status = $this->publish('alice', [
			'status' => $marker . ' for @' . self::PREFIX . 'dave #' . $this->tag,
			'visibility' => ($visibility === 'followers') ? 'private' : $visibility,
		]);

		return ['nid' => $status->id, 'uri' => $status->uri, 'owner' => 'alice', 'marker' => $marker];
	}

	private function relay(): Person {
		$person = new Person();
		$person->setId(self::RELAY)->setPreferredUsername('relay');
		$person->setAccount('relay@relay.example')
			->setName('Relay')
			->setFollowers(self::RELAY . '/followers')
			->setFollowing(self::RELAY . '/following')
			->setInbox(self::RELAY . '/inbox')
			->setOutbox(self::RELAY . '/outbox')
			->setLocal(false);
		$person->setCreation(time() - 365 * 86400);
		Server::get(CacheActorsRequest::class)->save($person);

		return Server::get(CacheActorsRequest::class)->getFromId(self::RELAY);
	}

	private function followRelay(Person $viewer, Person $relay): void {
		$follow = new Follow();
		$follow->setId(self::RELAY . '/follows/' . md5($viewer->getId()));
		$follow->setActorId($viewer->getId());
		$follow->setObjectId($relay->getId());
		$follow->setFollowId($relay->getFollowers());
		$follow->setAccepted(true);
		Server::get(FollowsRequest::class)->save($follow);
	}

	/**
	 * A public post of the remote account quoting `$quoted`, stored as an
	 * inbound one is, with an approval stamp: a quote shown at its most.
	 *
	 * @param array{nid: string, uri: string, owner: string, marker: string} $quoted
	 * @return array{nid: string, uri: string, owner: string, marker: string}
	 */
	private function relayQuote(Person $relay, array $quoted): array {
		$note = new Note();
		$note->setId('https://relay.example/vismatrix/notes/quote-' . bin2hex(random_bytes(6)));
		$note->setAttributedTo($relay->getId());
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$relay->getFollowers()]);
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setContent('<p>look at this #' . $this->tag . '</p>');
		$note->addTag(['type' => 'Hashtag', 'href' => 'https://relay.example/tags/' . $this->tag, 'name' => '#' . $this->tag]);
		$note->setQuote($quoted['uri']);
		$note->setQuoteAuthorization($quoted['uri'] . '/quote_authorizations/' . bin2hex(random_bytes(6)));
		$note->setPublishedTime(time() + 1);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() + 1));

		return $this->storeRemote($note, $quoted['marker']);
	}

	/**
	 * @param array{nid: string, uri: string, owner: string, marker: string} $boosted
	 * @return array{nid: string, uri: string, owner: string, marker: string}
	 */
	private function relayBoost(Person $relay, array $boosted): array {
		$announce = new Announce();
		$announce->setId('https://relay.example/vismatrix/announces/boost-' . bin2hex(random_bytes(6)));
		$announce->setAttributedTo($relay->getId());
		$announce->setObjectId($boosted['uri']);
		$announce->setTo(ACore::CONTEXT_PUBLIC);
		$announce->setCcArray([$relay->getFollowers()]);
		$announce->setPublishedTime(time() + 2);
		$announce->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() + 2));

		return $this->storeRemote($announce, $boosted['marker']);
	}

	/** @return array{nid: string, uri: string, owner: string, marker: string} */
	private function storeRemote(Stream $stream, string $marker): array {
		$streams = Server::get(StreamRequest::class);
		$streams->save($stream);
		$this->remoteStreams[] = $stream->getId();
		$stored = $streams->getStreamById($stream->getId(), false, ACore::FORMAT_LOCAL);

		return ['nid' => (string)$stored->getNid(), 'uri' => $stream->getId(), 'owner' => 'relay', 'marker' => $marker];
	}

	private function removeRelay(): void {
		Server::get(FollowsRequest::class)->deleteRelatedId(self::RELAY);
		Server::get(CacheActorsRequest::class)->deleteCacheById(self::RELAY);
	}

	/** `POST /api/v1/…/{id}/…` of one account about another. */
	private function relate(string $as, string $method, string $whom): void {
		$response = $this->call(
			AccountApiController::class, $method, ['id' => (string)$this->account($whom)->getNid()], [], $as, 'POST'
		);
		$this->assertSame(200, $response->status, $method . ': ' . (json_encode($response->body) ?: ''));
	}

	/** The account a viewer calls as, or null for nobody. */
	private function as(string $viewer): ?string {
		return self::NAMES[$viewer] ?? null;
	}

	private function ownerNid(string $post): string {
		if ($this->posts[$post]['owner'] === 'relay') {
			return (string)Server::get(CacheActorsRequest::class)->getFromId(self::RELAY)->getNid();
		}

		return (string)$this->account('alice')->getNid();
	}

	/** The token of a local post's address, the last segment of it. */
	private function postToken(string $uri): string {
		return substr($uri, strrpos($uri, '/') + 1);
	}

	/**
	 * A page of a timeline-like route, as a client asks for one.
	 *
	 * @param class-string $controller
	 * @param array<string, string> $route
	 */
	private function listing(string $controller, string $method, array $route): callable {
		return fn (string $post, string $viewer): string => $this->call(
			$controller, $method, $route, ['limit' => 40], $this->as($viewer)
		)->raw;
	}

	/**
	 * A public post of the author with a reply of every visibility under it,
	 * each naming `mentioned`.
	 */
	private function seedThread(): void {
		$root = $this->publish('alice', ['status' => 'the start of a thread', 'visibility' => 'public']);
		$this->root = $root->id;
		$this->rootUri = $root->uri;
		foreach (VisibilityMatrix::LOCAL_POSTS as $visibility) {
			$marker = 'vm' . bin2hex(random_bytes(6)) . 'reply' . $visibility;
			$reply = $this->publish('alice', [
				'status' => $marker . ' for @' . self::PREFIX . 'dave',
				'visibility' => ($visibility === 'followers') ? 'private' : $visibility,
				'in_reply_to_id' => $this->root,
			]);
			$this->thread[$visibility] = ['nid' => $reply->id, 'uri' => $reply->uri, 'marker' => $marker];
		}
	}

	/**
	 * The context of a public reply of the remote account to the post: the
	 * post is among the reply's ancestors when the viewer may read it.
	 */
	private function contextProbe(): callable {
		$relay = Server::get(CacheActorsRequest::class)->getFromId(self::RELAY);
		$replies = [];
		foreach (VisibilityMatrix::PATHS['context']['posts'] as $post) {
			$note = new Note();
			$note->setId('https://relay.example/vismatrix/notes/reply-' . bin2hex(random_bytes(6)));
			$note->setAttributedTo($relay->getId());
			$note->setTo(ACore::CONTEXT_PUBLIC);
			$note->setCcArray([$relay->getFollowers()]);
			$note->setVisibility(Stream::TYPE_PUBLIC);
			$note->setInReplyTo($this->posts[$post]['uri']);
			$note->setContent('<p>a public answer</p>');
			$note->setPublishedTime(time() + 3);
			$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() + 3));
			$replies[$post] = $this->storeRemote($note, '')['nid'];
		}

		return fn (string $post, string $viewer): string => $this->call(
			StatusApiController::class, 'statusContext', ['nid' => $replies[$post]], [], $this->as($viewer)
		)->raw;
	}

	/** The context of the thread's public start, looked through for each reply. */
	private function threadProbe(): callable {
		$this->seedThread();

		return fn (string $post, string $viewer): bool => str_contains(
			$this->call(StatusApiController::class, 'statusContext', ['nid' => $this->root], [], $this->as($viewer))->raw,
			$this->thread[$post]['marker']
		);
	}

	/** The replies collection of the thread's public start, unsigned or signed as the viewer. */
	private function repliesProbe(): callable {
		$this->seedThread();

		return fn (string $post, string $viewer): bool => str_contains(
			$this->activityPub(
				'replies', ['username' => self::PREFIX . 'alice', 'token' => $this->postToken($this->rootUri), 'page' => 'true'], $this->signer($viewer)
			)->raw,
			$this->postToken($this->thread[$post]['uri'])
		);
	}

	/** Who favourited a post the author favourited: the author is in the list when it is served. */
	private function favouritedByProbe(): callable {
		foreach (VisibilityMatrix::LOCAL_POSTS as $post) {
			$this->act('alice', $this->posts[$post]['nid'], 'favourite');
		}
		$author = '"' . $this->account('alice')->getNid() . '"';

		return fn (string $post, string $viewer): bool => str_contains($this->call(
			StatusApiController::class, 'statusFavouritedBy', ['nid' => $this->posts[$post]['nid']], [], $this->as($viewer)
		)->raw, $author);
	}

	/** Each viewer's list of the author, where the author may be added, and of the remote account. */
	private function listProbe(): callable {
		$lists = [];
		$relay = (string)Server::get(CacheActorsRequest::class)->getFromId(self::RELAY)->getNid();
		foreach (VisibilityMatrix::PATHS['list']['viewers'] as $viewer) {
			$name = self::NAMES[$viewer];
			$created = $this->call(ListController::class, 'create', [], ['title' => 'matrix'], $name, 'POST');
			$this->assertSame(200, $created->status, json_encode($created->body) ?: '');
			$members = [$relay];
			if (in_array($viewer, ['follower', 'muter'], true)) {
				$members[] = (string)$this->account('alice')->getNid();
			}
			$added = $this->call(ListController::class, 'addAccounts', ['id' => (int)$created->body->id], ['account_ids' => $members], $name, 'POST');
			$this->assertSame(200, $added->status, json_encode($added->body) ?: '');
			$lists[$viewer] = (int)$created->body->id;
		}

		return fn (string $post, string $viewer): string => $this->call(
			ListController::class, 'timeline', ['id' => $lists[$viewer]], ['limit' => 40], $this->as($viewer)
		)->raw;
	}

	/**
	 * `POST /api/v1/statuses/{id}/{act}` answers with the status it acted on;
	 * undone first, so that a mark an earlier path left is made afresh.
	 */
	private function actionProbe(string $act): callable {
		return function (string $post, string $viewer) use ($act): string {
			$route = ['nid' => $this->posts[$post]['nid']];
			$this->call(StatusApiController::class, 'statusAction', $route + ['act' => 'un' . $act], [], $this->as($viewer), 'POST');

			return $this->call(StatusApiController::class, 'statusAction', $route + ['act' => $act], [], $this->as($viewer), 'POST')->raw;
		};
	}

	/** Every viewer tries to mark every post, and the list of what they marked is read. */
	private function markedProbe(string $act, string $list): callable {
		$this->markEverything($act);

		return $this->listing(TimelineApiController::class, $list, []);
	}

	private function markEverything(string $act): void {
		foreach (VisibilityMatrix::SIGNED_IN as $viewer) {
			foreach (VisibilityMatrix::POSTS as $post) {
				$this->call(
					StatusApiController::class, 'statusAction', ['nid' => $this->posts[$post]['nid'], 'act' => $act], [], self::NAMES[$viewer], 'POST'
				);
			}
		}
	}

	/** The verified signer of a fetch made as the viewer, or null for an unsigned one. */
	private function signer(string $viewer): ?string {
		return ($viewer === 'anonymous') ? null : $viewer;
	}

	/**
	 * An ActivityPub GET, unsigned or signed by the viewer's actor.
	 *
	 * @param array<string, string> $route
	 */
	private function activityPub(string $method, array $route, ?string $signer): ApiResponse {
		$reader = ($signer === null) ? null : $this->account(self::NAMES[$signer]);
		$fetch = $this->createStub(AuthorizedFetchService::class);
		$fetch->method('reader')->willReturn($reader);

		return $this->call(
			ActivityPubController::class, $method, $route, [], null,
			headers: ['Accept' => 'application/activity+json'],
			services: [AuthorizedFetchService::class => $fetch],
		);
	}

	/**
	 * The browser page of a post, which hands the post to the page as its
	 * initial state: what is looked through is what the page was given.
	 */
	private function postPageProbe(): callable {
		return function (string $post, string $viewer): string {
			$given = [];
			$state = $this->createStub(IInitialState::class);
			$state->method('provideInitialState')->willReturnCallback(
				static function (string $key, mixed $data) use (&$given): void {
					$given[$key] = json_encode($data);
				}
			);
			$this->call(
				SocialPubController::class, 'displayPost',
				['username' => self::PREFIX . 'alice', 'token' => $this->postToken($this->posts[$post]['uri'])], [],
				$this->as($viewer), webSession: $viewer !== 'anonymous',
				services: [IInitialState::class => $state],
			);

			return (string)($given['item'] ?? '');
		};
	}

	/**
	 * The web app's page, which hands a signed-in reader the first page of
	 * their home timeline with it.
	 */
	private function firstPageProbe(): callable {
		return function (string $post, string $viewer): string {
			$given = [];
			$state = $this->createStub(IInitialState::class);
			$state->method('provideInitialState')->willReturnCallback(
				static function (string $key, mixed $data) use (&$given): void {
					$given[$key] = json_encode($data);
				}
			);
			$this->call(
				NavigationController::class, 'navigate', [], [], $this->as($viewer), webSession: true,
				services: [IInitialState::class => $state],
			);

			return (string)($given['firstPage'] ?? '');
		};
	}

	private function unifiedSearchProbe(): callable {
		return function (string $post, string $viewer): string {
			$query = $this->createStub(ISearchQuery::class);
			$query->method('getTerm')->willReturn($this->posts[$post]['marker']);
			$query->method('getLimit')->willReturn(20);
			$query->method('getCursor')->willReturn(null);
			$user = Server::get(IUserManager::class)->get(self::PREFIX . self::NAMES[$viewer]);
			$this->assertNotNull($user);

			$result = $this->fresh(UnifiedSearchProvider::class)->search($user, $query);

			return (string)json_encode($result);
		};
	}

	/** A link to the post's page in a text, as the viewer's session resolves it. */
	private function referenceProbe(): callable {
		return function (string $post, string $viewer): string {
			$session = Server::get(IUserSession::class);
			$session->setUser(($viewer === 'anonymous') ? null : Server::get(IUserManager::class)->get(self::PREFIX . self::NAMES[$viewer]));
			try {
				$link = Server::get(IURLGenerator::class)->linkToRouteAbsolute(
					'social.ActivityPub.displayPost', ['username' => self::PREFIX . 'alice', 'token' => $this->posts[$post]['nid']]
				);
				$reference = $this->fresh(PostReferenceProvider::class)->resolveReference($link);
			} finally {
				$session->setUser(null);
			}

			return (string)json_encode($reference?->jsonSerialize());
		};
	}

	/** @param class-string $widget */
	private function widgetProbe(string $widget, string $markFirst = ''): callable {
		if ($markFirst !== '') {
			$this->markEverything($markFirst);
		}

		return fn (string $post, string $viewer): string => (string)json_encode(
			$this->fresh($widget)->getItemsV2(self::PREFIX . self::NAMES[$viewer], null, 20)
		);
	}

	/**
	 * A service of the app as a fresh request gets it, holding no viewer an
	 * earlier call left in it.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function fresh(string $class): object {
		$service = (new DIContainer('social'))->get($class);
		$this->assertInstanceOf($class, $service);

		return $service;
	}
}
