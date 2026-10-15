<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyCountSource;
use OCA\Social\Atproto\Reader\BlueskyPublishedCountSource;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Service\Counts\CountWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The counts of a local post that is also a record on Bluesky, read from the
 * AppView: only what it counts beyond what this server holds is stored.
 */
#[AllowMockObjectsWithoutExpectations]
class BlueskyPublishedCountSourceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const ACTOR = 'https://cloud.example/users/alice';
	private const CID = 'bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm';

	private Publisher&MockObject $publisher;
	private AtprotoRepoRequest&MockObject $records;
	private IdentityService&MockObject $identities;
	private CacheActorsRequest&MockObject $actors;
	private AppViewClient&MockObject $appView;
	private CountWriter&MockObject $writer;
	private bool $active = true;

	protected function setUp(): void {
		$this->publisher = $this->createMock(Publisher::class);
		$this->records = $this->createMock(AtprotoRepoRequest::class);
		$this->identities = $this->createMock(IdentityService::class);
		$this->actors = $this->createMock(CacheActorsRequest::class);
		$this->appView = $this->createMock(AppViewClient::class);
		$this->writer = $this->createMock(CountWriter::class);

		$this->records->method('getRecordsByLocalId')->willReturnCallback(
			fn (string $id): array => str_contains($id, '/unpublished') ? [] : [$this->record($id)]
		);
		$this->identities->method('activeForActor')->willReturnCallback(
			fn (Person $actor): ?Identity => $this->active && $actor->getId() === self::ACTOR ? $this->identity(self::DID) : null
		);
	}

	private function source(?CountWriter $writer = null): BlueskyPublishedCountSource {
		return new BlueskyPublishedCountSource(
			$this->publisher, $this->records, $this->identities, $this->actors,
			$this->appView, $writer ?? $this->writer, new NullLogger(),
		);
	}

	private function record(string $postId, string $did = self::DID): StoredRecord {
		return new StoredRecord($did, RecordMapper::POST, self::rkey($postId), Cid::parse(self::CID), '', $postId, 0);
	}

	private function identity(string $did): Identity {
		return new Identity(1, self::ACTOR, $did, 'alice.cloud.example', '', '', '', Identity::STATE_ACTIVE, '', 0);
	}

	private static function rkey(string $postId): string {
		return '3k' . substr(md5($postId), 0, 11);
	}

	private static function uri(string $postId): string {
		return 'at://' . self::DID . '/' . RecordMapper::POST . '/' . self::rkey($postId);
	}

	private static function id(string $n): string {
		return self::ACTOR . '/statuses/' . $n;
	}

	private function note(string $id, bool $withAuthor = true): Note {
		$note = new Note();
		$note->setId($id);
		$note->setLocal(true);
		$note->setAttributedTo(self::ACTOR);
		if ($withAuthor) {
			$author = new Person();
			$author->setId(self::ACTOR);
			$author->setLocal(true);
			$note->setActor($author);
		}

		return $note;
	}

	private function answer(array $views): void {
		$this->appView->method('query')->willReturnCallback(static function (string $method, array $params) use ($views): array {
			$posts = [];
			foreach ($params['uris'] as $uri) {
				if (isset($views[$uri])) {
					$posts[] = ['uri' => $uri] + $views[$uri];
				}
			}

			return ['posts' => $posts];
		});
	}

	public function testOnlyALocalPostThatGoesToBlueskyIsAskedAbout(): void {
		$this->publisher->method('goesToBluesky')->willReturnCallback(static fn (Note $post): bool => $post->getId() !== self::id('private'));

		$this->assertTrue($this->source()->supports($this->note(self::id('1'))));
		$this->assertFalse($this->source()->supports($this->note(self::id('private'))), 'not a post that goes to Bluesky');
		$remote = new Note();
		$remote->setId('https://bsky.app/profile/' . self::DID . '/post/3k1');
		$this->assertFalse($this->source()->supports($remote), 'a post read from Bluesky is BlueskyCountSource\'s');
	}

	public function testWhatTheAppViewCountsBeyondWhatIsHeldHereIsWritten(): void {
		$this->answer([self::uri(self::id('1')) => ['likeCount' => 12, 'repostCount' => 3, 'replyCount' => 2, 'quoteCount' => 1]]);
		$held = [Details::LIKES => 10, Details::BOOSTS => 3, Details::REPLIES => 2, Details::QUOTES => 0];
		$this->records->expects($this->once())->method('countHeldOnBluesky')->with(self::id('1'))->willReturn($held);
		$this->writer->expects($this->once())->method('writeBeyond')
			->with(self::id('1'), [Details::LIKES => 12, Details::BOOSTS => 3, Details::REPLIES => 2, Details::QUOTES => 1], $held)
			->willReturn(true);
		$this->writer->expects($this->never())->method('stamp');

		$this->assertSame(1, $this->source()->refresh([$this->note(self::id('1'))]));
	}

	/**
	 * Every like and repost the AppView counts came in by notification or is a
	 * local account's own record: the post's counts are what they were.
	 */
	public function testAPostWhoseNotificationsAllArrivedKeepsItsNumbers(): void {
		$post = $this->note(self::id('1'));
		$this->answer([self::uri(self::id('1')) => ['likeCount' => 3, 'repostCount' => 1, 'replyCount' => 1, 'quoteCount' => 0]]);
		$this->records->method('countHeldOnBluesky')
			->willReturn([Details::LIKES => 3, Details::BOOSTS => 1, Details::REPLIES => 1, Details::QUOTES => 0]);

		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($post);
		$streams->expects($this->once())->method('changeDetails')
			->willReturnCallback(static fn (string $id, callable $change): array => $change($post->getDetailsAll()));
		$streams->expects($this->once())->method('recount')
			->with($this->identicalTo($post), Details::LIKES, Details::BOOSTS, Details::REPLIES, Details::QUOTES)
			->willReturnCallback(function () use ($post): void {
				$this->assertSame(0, $post->getDetailInt(Details::REMOTE_LIKES));
				$this->assertSame(0, $post->getDetailInt(Details::REMOTE_BOOSTS));
				$this->assertSame(0, $post->getDetailInt(Details::REMOTE_REPLIES));
				$this->assertSame(0, $post->getDetailInt(Details::REMOTE_QUOTES));
			});
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$writer = new CountWriter($streams, $this->createMock(ActionsRequest::class), $time);

		$this->assertSame(1, $this->source($writer)->refresh([$post]));
	}

	public function testAnAuthorWhoseBlueskyPresenceIsOffIsNotAskedAbout(): void {
		$this->active = false;
		$this->appView->expects($this->never())->method('query');
		$this->records->expects($this->never())->method('countHeldOnBluesky');
		$this->writer->expects($this->never())->method('writeBeyond');
		$this->writer->expects($this->once())->method('stamp')->with(self::id('1'));

		$this->assertSame(0, $this->source()->refresh([$this->note(self::id('1'))]));
	}

	public function testAPostWithoutARecordOrInAnotherRepositoryIsStampedAndNotAsked(): void {
		$this->records = $this->createMock(AtprotoRepoRequest::class);
		$this->records->method('getRecordsByLocalId')->willReturnCallback(
			fn (string $id): array => match ($id) {
				self::id('elsewhere') => [$this->record($id, 'did:plc:someoneelse0000000000000')],
				default => [],
			}
		);
		$this->appView->expects($this->never())->method('query');
		$stamped = [];
		$this->writer->method('stamp')->willReturnCallback(static function (string $id) use (&$stamped): void {
			$stamped[] = $id;
		});

		$this->source()->refresh([$this->note(self::id('unpublished')), $this->note(self::id('elsewhere'))]);

		$this->assertSame([self::id('unpublished'), self::id('elsewhere')], $stamped);
	}

	public function testTheAuthorIsLookedUpWhenThePostCameWithoutIt(): void {
		$author = new Person();
		$author->setId(self::ACTOR);
		$author->setLocal(true);
		$this->actors->expects($this->once())->method('getFromId')->with(self::ACTOR)->willReturn($author);
		$this->answer([self::uri(self::id('1')) => ['likeCount' => 1]]);
		$this->records->method('countHeldOnBluesky')->willReturn([]);
		$this->writer->expects($this->once())->method('writeBeyond')->willReturn(true);

		$this->assertSame(1, $this->source()->refresh([$this->note(self::id('1'), false)]));
	}

	public function testAPostTheAppViewDoesNotReturnIsStampedForLater(): void {
		$this->answer([]);
		$this->writer->expects($this->never())->method('writeBeyond');
		$this->writer->expects($this->once())->method('stamp')->with(self::id('1'));

		$this->assertSame(0, $this->source()->refresh([$this->note(self::id('1'))]));
	}

	public function testACallTheAppViewDidNotAnswerStampsItsPostsForLater(): void {
		$this->appView->method('query')->willThrowException(new AtprotoException('down'));
		$this->writer->expects($this->never())->method('writeBeyond');
		$this->writer->expects($this->exactly(2))->method('stamp');

		$this->assertSame(0, $this->source()->refresh([$this->note(self::id('1')), $this->note(self::id('2'))]));
	}

	public function testACountThatIsNoNumberIsLeftUnstated(): void {
		$this->answer([self::uri(self::id('1')) => ['likeCount' => -1, 'repostCount' => '3', 'replyCount' => 2]]);
		$this->records->method('countHeldOnBluesky')->willReturn([]);
		$this->writer->expects($this->once())->method('writeBeyond')
			->with(self::id('1'), [Details::LIKES => null, Details::BOOSTS => null, Details::REPLIES => 2, Details::QUOTES => null], [])
			->willReturn(true);

		$this->source()->refresh([$this->note(self::id('1'))]);
	}

	public function testTwentyFiveRecordsAreOneCall(): void {
		$posts = [];
		for ($i = 0; $i < BlueskyCountSource::BATCH + 1; $i++) {
			$posts[] = $this->note(self::id((string)$i));
		}
		$sizes = [];
		$this->appView->expects($this->exactly(2))->method('query')
			->willReturnCallback(static function (string $method, array $params) use (&$sizes): array {
				$sizes[] = count($params['uris']);

				return ['posts' => []];
			});

		$this->source()->refresh($posts);

		$this->assertSame([BlueskyCountSource::BATCH, 1], $sizes);
	}
}
