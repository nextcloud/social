<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DislikeService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\ReactionService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\ReportForwardService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * Every activity this instance sends about something other than a post of
 * its own, pinned whole as the delivery queue is handed it. See GoldenFile;
 * `UPDATE_GOLDEN=1` regenerates them.
 *
 * Each one is made by the service that makes it in production — LikeService,
 * BoostService, FollowService, FollowInterface, ReactionService,
 * RelationshipService, PinService, MigrationService, QuoteRequestInterface,
 * ReportForwardService — through the real ActivityService and SignatureService.
 * The tables and the remote actor lookups are doubles.
 *
 * `ApproveReply` is not here: this app only receives it (FEP-5624), it never
 * sends one.
 */
class OutboundActivityGoldenTest extends TestCase {
	use TActivityPubMocks;
	use TFederationWorld;

	private const BOB_POST = 'https://remote.example/users/bob/statuses/110000000000000001';
	private const BOB_FOLLOW = 'https://remote.example/0e6f2b84-6c1d-4c4e-9a8e-3f7c2d1b5a90';

	private Person $alice;
	private ?ActivityService $activities = null;
	private StreamRequest $streamRequest;
	private FollowsRequest $followsRequest;
	private CacheActorService $cacheActors;
	private ActorsRequest $actorsRequest;

	protected function setUp(): void {
		$this->installActivityPub(self::$cloud);
		\OC::$server->register(IURLGenerator::class, $this->urls());
		$this->alice = $this->localActor();

		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->streamRequest->method('getStreamById')->willReturnCallback(
			fn (string $id): Stream => match ($id) {
				self::BOB_POST => $this->bobsPost(),
				default => throw new StreamNotFoundException($id),
			}
		);
		$this->streamRequest->method('getAnnounceBy')->willThrowException(new StreamNotFoundException());

		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());

		$this->cacheActors = $this->createStub(CacheActorService::class);
		$this->cacheActors->method('getFromId')->willReturnCallback(
			fn (string $id): Person => ($id === self::$bob) ? $this->bob() : $this->alice
		);
		$this->cacheActors->method('getFromAccount')->willReturnCallback(
			fn (string $account): Person => ($account === 'bob@remote.example') ? $this->bob() : $this->alice
		);

		$this->actorsRequest = $this->createStub(ActorsRequest::class);
		$this->actorsRequest->method('getFromId')->willReturnCallback(fn (): Person => $this->alice);
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	private function activities(): ActivityService {
		return $this->activities ??= $this->activityService([ActorsRequest::class => $this->actorsRequest]);
	}

	/** The collaborators every service here shares; a test adds its own. */
	private function shared(array $overrides = []): array {
		return $overrides + [
			ActivityService::class => $this->activities(),
			SignatureService::class => $this->signatureService(),
			CacheActorService::class => $this->cacheActors,
			StreamRequest::class => $this->streamRequest,
			FollowsRequest::class => $this->followsRequest,
			ActorsRequest::class => $this->actorsRequest,
			ConfigService::class => $this->config(),
			IURLGenerator::class => $this->urls(),
			'logger' => new NullLogger(),
		];
	}

	private function streamService(): StreamService {
		return $this->realService(StreamService::class, $this->shared());
	}

	/** Bob's public post, as it is stored here once it arrived. */
	private function bobsPost(): Note {
		/** @var Note $note */
		$note = AP::instance()->getItemFromData([
			'id' => self::BOB_POST,
			'type' => 'Note',
			'attributedTo' => self::$bob,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [self::$bob . '/followers'],
			'content' => '<p>what are you growing this year?</p>',
			'published' => '2026-03-01T09:00:00Z',
		]);
		$note->setVisibility(Stream::TYPE_PUBLIC);

		return $note;
	}

	/** One of Alice's posts, as read back from the stream table. */
	private function alicesPost(string $visibility = Stream::TYPE_PUBLIC): Note {
		$note = new Note();
		$note->importFromDatabase([
			'nid' => '7', 'id' => self::$social . '@alice/17000000001234567890', 'type' => 'Note',
			'attributed_to' => $this->alice->getId(), 'local' => '1',
			'to' => ($visibility === Stream::TYPE_PUBLIC) ? ACore::CONTEXT_PUBLIC : $this->alice->getFollowers(),
			'cc' => json_encode(($visibility === Stream::TYPE_PUBLIC) ? [$this->alice->getFollowers()] : []),
			'content' => '<p>Tomatoes are up!</p>', 'published' => '2026-03-02T08:00:00Z',
			'visibility' => $visibility,
		]);

		return $note;
	}

	/** Bob's Follow of Alice, the way Mastodon sends it. */
	private function bobsFollow(): Follow {
		/** @var Follow $follow */
		$follow = AP::instance()->getItemFromData([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => self::BOB_FOLLOW,
			'type' => 'Follow',
			'actor' => self::$bob,
			'object' => $this->alice->getId(),
		]);

		return $follow;
	}

	public function testALike(): void {
		$this->realService(LikeService::class, $this->shared([StreamService::class => $this->streamService()]))
			->create($this->alice, self::BOB_POST);

		GoldenFile::assertMatches('like', $this->lastQueued());
	}

	public function testUndoingALikeEmbedsTheLike(): void {
		$service = $this->realService(LikeService::class, $this->shared([StreamService::class => $this->streamService()]));
		$like = $service->create($this->alice, self::BOB_POST);
		$this->assertInstanceOf(Like::class, $like);
		$this->apInterface(LikeInterface::class)->method('getItem')->willReturn($like);
		$this->queued = [];

		$service->delete($this->alice, self::BOB_POST);

		GoldenFile::assertMatches('undo-like', $this->lastQueued());
	}

	/** Only a video can be disliked: Bob's post carries one here. */
	public function testADislike(): void {
		$video = $this->bobsPost();
		$video->setAttachments([(new \OCA\Social\Model\Client\MediaAttachment())->import([
			'id' => '5', 'type' => 'video', 'media_type' => 'video/mp4', 'url' => 'https://remote.example/media/1.mp4',
		])]);
		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('getStreamById')->willReturn($video);

		$this->realService(DislikeService::class, $this->shared([
			StreamService::class => $this->realService(StreamService::class, $this->shared([StreamRequest::class => $streamRequest])),
		]))->create($this->alice, self::BOB_POST);

		GoldenFile::assertMatches('dislike', $this->lastQueued());
	}

	public function testABoostIsAnAnnounce(): void {
		$this->realService(BoostService::class, $this->shared([StreamService::class => $this->streamService()]))
			->create($this->alice, self::BOB_POST);

		GoldenFile::assertMatches('announce', $this->lastQueued());
	}

	public function testUndoingABoostEmbedsTheAnnounce(): void {
		$announce = new Announce();
		$announce->importFromDatabase([
			'id' => self::$social . '@alice/17000000009876543210', 'type' => 'Announce',
			'attributed_to' => $this->alice->getId(), 'object_id' => self::BOB_POST, 'local' => '1',
			'to' => ACore::CONTEXT_PUBLIC, 'cc' => json_encode([$this->alice->getFollowers()]),
			'published' => '2026-03-02T09:00:00Z',
		]);
		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('getStreamById')->willReturnCallback(fn (): Stream => $this->bobsPost());
		$streamRequest->method('getAnnounceBy')->willReturn($announce);

		$this->realService(BoostService::class, $this->shared([
			StreamRequest::class => $streamRequest,
			StreamService::class => $this->realService(StreamService::class, $this->shared([StreamRequest::class => $streamRequest])),
		]))->delete($this->alice, self::BOB_POST);

		GoldenFile::assertMatches('undo-announce', $this->lastQueued());
	}

	public function testAFollow(): void {
		$this->realService(FollowService::class, $this->shared())->followActor($this->alice, $this->bob());

		GoldenFile::assertMatches('follow', $this->lastQueued());
	}

	public function testUnfollowingEmbedsTheFollow(): void {
		$follow = new Follow();
		$follow->setId(self::$cloud . '/b1c2d3e4-0000-4000-8000-00000000000a');
		$follow->setActorId($this->alice->getId());
		$follow->setObjectId(self::$bob);
		$follow->setAccepted(true);
		$follows = $this->createStub(FollowsRequest::class);
		$follows->method('getByPersons')->willReturn($follow);

		$this->realService(FollowService::class, $this->shared([FollowsRequest::class => $follows]))
			->unfollowAccount($this->alice, 'bob@remote.example');

		GoldenFile::assertMatches('undo-follow', $this->lastQueued());
	}

	public function testAcceptingAFollowEmbedsIt(): void {
		$this->realService(FollowInterface::class, $this->shared())->confirmFollowRequest($this->bobsFollow());

		GoldenFile::assertMatches('accept-follow', $this->lastQueued());
	}

	public function testRejectingAFollowEmbedsIt(): void {
		$this->realService(FollowInterface::class, $this->shared())->rejectFollowRequest($this->bobsFollow());

		GoldenFile::assertMatches('reject-follow', $this->lastQueued());
	}

	public function testAnEmojiReaction(): void {
		$this->realService(ReactionService::class, $this->shared())->create($this->alice, self::BOB_POST, '🍅');

		GoldenFile::assertMatches('emoji-react', $this->lastQueued());
	}

	public function testTakingAReactionBackEmbedsIt(): void {
		$reactions = $this->createStub(ReactionsRequest::class);
		$service = $this->realService(ReactionService::class, $this->shared([ReactionsRequest::class => $reactions]));
		$reaction = $service->create($this->alice, self::BOB_POST, '🍅');
		$this->assertInstanceOf(EmojiReact::class, $reaction);
		$reactions->method('getReaction')->willReturn($reaction);
		$this->queued = [];

		$service->delete($this->alice, self::BOB_POST, '🍅');

		GoldenFile::assertMatches('undo-emoji-react', $this->lastQueued());
	}

	public function testABlock(): void {
		$this->realService(RelationshipService::class, $this->shared(['container' => null]))
			->block($this->alice, $this->bob());

		GoldenFile::assertMatches('block', $this->lastQueued());
	}

	public function testUndoingABlockEmbedsIt(): void {
		$this->realService(RelationshipService::class, $this->shared(['container' => null]))
			->unblock($this->alice, $this->bob());

		GoldenFile::assertMatches('undo-block', $this->lastQueued());
	}

	public function testAMoveNamesTheNewAccount(): void {
		$target = $this->bob();
		$target->setId('https://new.example/users/alice');
		$target->setInbox('https://new.example/users/alice/inbox');
		$target->setAlsoKnownAs([$this->alice->getId()]);
		$cacheActors = $this->createStub(CacheActorService::class);
		$cacheActors->method('getFromId')->willReturn($target);
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn($this->alice);

		$this->realService(MigrationService::class, $this->shared([
			CacheActorService::class => $cacheActors,
			AccountService::class => $accounts,
		]))->move('alice', 'https://new.example/users/alice', false);

		GoldenFile::assertMatches('move', $this->lastQueued());
	}

	public function testPinningAndUnpinningAreAddAndRemoveOnTheFeaturedCollection(): void {
		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('getStreamByNid')->willReturnCallback(fn (): Stream => $this->alicesPost());
		$actions = $this->createStub(ActionsRequest::class);
		$actions->method('getAction')->willThrowException(new ActionDoesNotExistException());
		$service = $this->realService(PinService::class, $this->shared([
			StreamRequest::class => $streamRequest,
			ActionsRequest::class => $actions,
		]));

		$service->pin($this->alice, '7');
		GoldenFile::assertMatches('add-featured', $this->lastQueued());

		$service->unpin($this->alice, '7');
		GoldenFile::assertMatches('remove-featured', $this->lastQueued());
	}

	/** @return iterable<string, array{string, string}> */
	public static function quoteAnswers(): iterable {
		yield 'a public post is quotable by anyone' => [Stream::TYPE_PUBLIC, 'accept-quote-request'];
		yield 'a followers-only post is not quotable by a stranger' => [Stream::TYPE_FOLLOWERS, 'reject-quote-request'];
	}

	/**
	 * The answer to Bob's `QuoteRequest` for one of Alice's posts: an Accept
	 * whose `result` is the QuoteAuthorization, or a Reject.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('quoteAnswers')]
	public function testTheAnswerToAQuoteRequest(string $visibility, string $golden): void {
		$quoted = $this->alicesPost($visibility);
		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('getStreamById')->willReturn($quoted);

		/** @var QuoteRequest $request */
		$request = AP::instance()->getItemFromData([
			'@context' => ['https://www.w3.org/ns/activitystreams', ['QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest']],
			'id' => self::BOB_POST . '/quote-request',
			'type' => 'QuoteRequest',
			'actor' => self::$bob,
			'object' => $quoted->getId(),
			'instrument' => self::BOB_POST,
		]);
		$request->setOrigin('remote.example', SignatureService::ORIGIN_HEADER, time());

		$this->realService(QuoteRequestInterface::class, $this->shared([StreamRequest::class => $streamRequest]))
			->processIncomingRequest($request);

		GoldenFile::assertMatches($golden, $this->lastQueued());
	}

	/**
	 * A report forwarded to the reported account's server: the instance actor
	 * flags the account and its posts, with the comment and without the
	 * reporter. Sent directly rather than queued, so it is read off the
	 * request body.
	 */
	public function testAForwardedReportIsAFlagFromTheInstance(): void {
		$sent = [];
		$curl = $this->createStub(CurlService::class);
		$curl->method('retrieveJson')->willReturnCallback(
			function (string $method, string $url, array $options) use (&$sent): array {
				$sent[] = $options['body'];

				return [];
			}
		);
		$streamRequest = $this->createStub(StreamRequest::class);
		$streamRequest->method('getStreamByNid')->willReturnCallback(fn (): Stream => $this->bobsPost());
		$instanceActor = $this->realService(InstanceActorService::class, $this->shared());

		$report = new Report();
		$report->setId(42)->setLocal(true)->setStatusIds(['31'])->setComment('spam, and rude about tomatoes');

		$forwarded = $this->realService(ReportForwardService::class, $this->shared([
			InstanceActorService::class => $instanceActor,
			CurlService::class => $curl,
			StreamRequest::class => $streamRequest,
		]))->forward($report, $this->bob());

		$this->assertTrue($forwarded);
		$this->assertCount(1, $sent);
		GoldenFile::assertMatches('flag', (array)json_decode($sent[0], true));
	}

	/** A changed profile goes to the followers as an Update of the whole actor. */
	public function testAProfileChangeIsAnUpdateOfThePerson(): void {
		$this->activities()->updateActivity($this->alice, clone $this->alice);

		GoldenFile::assertMatches('update-person', $this->lastQueued());
	}
}
