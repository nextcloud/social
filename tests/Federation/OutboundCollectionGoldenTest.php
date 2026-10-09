<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\QuoteGrantRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * The collections this instance serves to a peer that asks for ActivityPub
 * — outbox, followers, following, featured, a post's replies and likes, and
 * the QuoteAuthorization of an approved quote — each as the collection and
 * as a page, pinned whole as golden files (see GoldenFile).
 *
 * Served by the real ActivityPubController over the real FollowService and
 * StreamService; what the tables would return is handed in.
 */
class OutboundCollectionGoldenTest extends TestCase {
	use TActivityPubMocks;
	use TFederationWorld;

	private Person $alice;
	private StreamRequest $streamRequest;
	private FollowsRequest $followsRequest;

	protected function setUp(): void {
		$this->installActivityPub(self::$cloud);
		\OC::$server->register(IURLGenerator::class, $this->urls());
		$this->alice = $this->localActor();
		$this->alice->setDetailArray(Details::COUNT, ['followers' => 2, 'following' => 1, 'post' => 2]);

		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->followsRequest = $this->createStub(FollowsRequest::class);
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	/** One of Alice's posts as the stream table hands it back, author joined in. */
	private function alicesPost(string $nid, string $content, string $visibility = Stream::TYPE_PUBLIC): Note {
		$note = new Note();
		$note->importFromDatabase([
			'nid' => $nid, 'id' => self::$social . '@alice/' . $nid, 'type' => 'Note',
			'attributed_to' => $this->alice->getId(), 'local' => '1',
			'to' => ($visibility === Stream::TYPE_PUBLIC) ? ACore::CONTEXT_PUBLIC : $this->alice->getFollowers(),
			'cc' => json_encode(($visibility === Stream::TYPE_PUBLIC) ? [$this->alice->getFollowers()] : []),
			'content' => $content, 'published' => '2026-03-02T08:00:00Z', 'language' => 'en',
			'visibility' => $visibility, 'details' => json_encode(['likes' => 3]),
			'source' => json_encode(['type' => 'Note']),
		]);
		$note->setActor($this->alice);
		$note->setCompleteDetails(true);

		return $note;
	}

	private function follow(string $id, string $actor, string $object, int $creation): Follow {
		$follow = new Follow();
		$follow->setId($id);
		$follow->setActorId($actor);
		$follow->setObjectId($object);
		$follow->setAccepted(true);
		$follow->setCreation($creation);

		return $follow;
	}

	private function controller(): ActivityPubController {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => (strtolower($name) === 'accept') ? 'application/activity+json' : ''
		);

		$cacheActors = $this->createStub(CacheActorService::class);
		$cacheActors->method('getFromLocalAccount')->willReturn($this->alice);

		$pins = $this->createStub(PinService::class);
		$pins->method('getPinnedPosts')->willReturnCallback(fn (): array => [$this->alicesPost('17000000001111111111', '<p>Pinned: my seed list</p>')]);

		$shared = [
			IRequest::class => $request,
			IURLGenerator::class => $this->urls(),
			ConfigService::class => $this->config(),
			StreamRequest::class => $this->streamRequest,
			FollowsRequest::class => $this->followsRequest,
			CacheActorService::class => $cacheActors,
			'logger' => new NullLogger(),
		];

		return $this->realService(ActivityPubController::class, $shared + [
			StreamService::class => $this->realService(StreamService::class, $shared),
			FollowService::class => $this->realService(FollowService::class, $shared),
			PinService::class => $pins,
			InstanceActorService::class => $this->realService(InstanceActorService::class, $shared),
			QuoteGrantRequest::class => $this->createStub(QuoteGrantRequest::class),
		]);
	}

	private function served(DataResponse $response): array {
		$this->assertSame(200, $response->getStatus());

		return GoldenFile::wire($response->getData());
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function collections(): iterable {
		yield 'outbox' => ['outbox', '', 'outbox'];
		yield 'outbox page' => ['outbox', '1', 'outbox-page'];
		yield 'followers' => ['followers', '', 'followers'];
		yield 'followers page' => ['followers', '1', 'followers-page'];
		yield 'following' => ['following', '', 'following'];
		yield 'following page' => ['following', '1', 'following-page'];
	}

	#[DataProvider('collections')]
	public function testAnActorCollectionAndItsFirstPage(string $method, string $page, string $golden): void {
		$this->streamRequest->method('getPublicByAuthor')->willReturnCallback(fn (): array => [
			$this->alicesPost('17000000002222222222', '<p>Second post</p>'),
			$this->alicesPost('17000000001111111111', '<p>First post</p>'),
		]);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([
			$this->follow('https://remote.example/follows/1', self::$bob, $this->alice->getId(), 1767225600),
			$this->follow('https://other.example/follows/2', 'https://other.example/users/carol', $this->alice->getId(), 1767312000),
		]);
		$this->followsRequest->method('getFollowingByActorId')->willReturn([
			$this->follow(self::$cloud . '/follows/3', $this->alice->getId(), self::$bob, 1767398400),
		]);

		GoldenFile::assertMatches($golden, $this->served($this->controller()->$method('alice', $page)));
	}

	public function testTheFeaturedCollectionListsThePinnedPostsWhole(): void {
		GoldenFile::assertMatches('featured', $this->served($this->controller()->featured('alice')));
	}

	public function testAPostsRepliesAndTheirFirstPage(): void {
		$post = $this->alicesPost('17000000001111111111', '<p>Tomatoes are up!</p>');
		$this->streamRequest->method('getStreamById')->willReturn($post);
		$this->streamRequest->method('countPublicRepliesTo')->willReturn(2);
		$reply = new Note();
		$reply->setId('https://remote.example/users/bob/statuses/110000000000000002');
		$reply->setNid('31');
		$this->streamRequest->method('getPublicRepliesTo')->willReturn([$reply]);

		GoldenFile::assertMatches('replies', $this->served($this->controller()->replies('alice', '17000000001111111111')));
		GoldenFile::assertMatches('replies-page', $this->served($this->controller()->replies('alice', '17000000001111111111', '1')));
	}

	public function testAPostsLikesAreACountOnly(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->alicesPost('17000000001111111111', '<p>Tomatoes are up!</p>'));

		GoldenFile::assertMatches('likes', $this->served($this->controller()->likes('alice', '17000000001111111111')));
	}

	/**
	 * The approval a peer dereferences before it shows a quote of one of
	 * Alice's posts inline: naming both posts and attributed to her.
	 */
	public function testAQuoteAuthorization(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->alicesPost('17000000001111111111', '<p>Tomatoes are up!</p>'));
		$stamp = rtrim(strtr(base64_encode('https://remote.example/users/bob/statuses/110000000000000003'), '+/', '-_'), '=');

		GoldenFile::assertMatches(
			'quote-authorization',
			$this->served($this->controller()->displayQuoteAuthorization('alice', '17000000001111111111', $stamp))
		);
	}
}
