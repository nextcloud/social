<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\PostRepublisher;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Repository\CommitResult;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The posts a move here takes off Bluesky: counted before the identity
 * here is retired, and published again afterwards when the person asks,
 * a page at a time.
 */
#[AllowMockObjectsWithoutExpectations]
class PostRepublisherTest extends TestCase {
	private const ACTOR = 'https://social.test/@alice';
	private const NOW = 1760000000;

	/** @var list<Stream> the account's posts, newest first */
	private array $posts = [];
	private array $queued = [];
	private ?Move $stored = null;
	/** @var list<string> the posts brought over from elsewhere */
	private array $imported = [];
	/** @var Publisher&MockObject */
	private Publisher $publisher;
	private PostRepublisher $republisher;

	protected function setUp(): void {
		$repoRequest = $this->createMock(AtprotoRepoRequest::class);
		$repoRequest->method('countRecords')->willReturnCallback(static fn (string $did, string $collection = ''): int => $collection === 'app.bsky.feed.post' && $did === 'did:plc:here' ? 38 : 0);
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getByAuthor')->willReturnCallback(function (string $actorId, int $limit, int|string $before): array {
			$older = array_values(array_filter($this->posts, static fn (Stream $post): bool => (int)$before === 0 || (int)$post->getNid() < (int)$before));

			return array_slice($older, 0, $limit);
		});
		$imported = $this->createMock(ImportedPostsRequest::class);
		$imported->method('isImported')->willReturnCallback(fn (string $actorId, string $id): bool => in_array($id, $this->imported, true));
		$this->publisher = $this->createMock(Publisher::class);
		$actor = new Person();
		$actor->setId(self::ACTOR);
		$actors = $this->createMock(ActorsRequest::class);
		$actors->method('getFromUserId')->willReturn($actor);
		$moves = $this->createMock(AtprotoMoveRequest::class);
		$moves->method('update')->willReturnCallback(function (Move $move): void {
			$this->stored = clone $move;
		});
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
			$this->queued[] = [$job, $argument];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->republisher = new PostRepublisher($repoRequest, $streams, $imported, $this->publisher, $actors, $moves, $jobs, $time, new NullLogger());
	}

	private static function identity(string $did, int $creation = 1000): Identity {
		return new Identity(1, self::ACTOR, $did, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', $creation);
	}

	private static function move(string $state = Move::DONE): Move {
		return new Move(4, 'did:plc:moved', 'alice', Move::IN, 'https://pds.example.com', '', 'alice.bsky.social', Move::STEP_DONE, $state);
	}

	private function post(int $nid, int $written): Stream {
		$post = new Stream();
		$post->setId(self::ACTOR . '/' . $nid);
		$post->setNid($nid);
		$post->setPublishedTime($written);

		return $post;
	}

	public function testThePostsOnBlueskyAreCountedAndRememberedBeforeTheIdentityIsRetired(): void {
		$this->assertSame(38, $this->republisher->onBluesky(self::identity('did:plc:here')));
		$this->assertSame(0, $this->republisher->onBluesky(null));

		$move = self::move(Move::RUNNING);
		$this->republisher->remember($move, self::identity('did:plc:here'));
		$this->assertSame(['count' => 38, 'from' => 1000, 'until' => self::NOW, 'state' => ''], $move->progress['republish']);

		$same = self::move(Move::RUNNING);
		$this->republisher->remember($same, self::identity('did:plc:moved'));
		$this->assertArrayNotHasKey('republish', $same->progress, 'nothing is retired when the DID stays');
	}

	public function testOnlyAFinishedMoveThatTookPostsOffIsRepublishedOnce(): void {
		$move = self::move();
		$move->progress['republish'] = ['count' => 2, 'from' => 1000, 'until' => 3000, 'state' => ''];

		$this->republisher->queue($move);

		$this->assertSame('queued', $this->stored?->progress['republish']['state']);
		$this->assertSame([[AtprotoMove::class, ['move' => 4, 'republish' => true]]], $this->queued);
		foreach ([[$move, 'republish-queued'], [self::move(), 'nothing-to-republish'], [null, 'nothing-to-republish']] as [$again, $code]) {
			try {
				$this->republisher->queue($again);
				$this->fail('queued');
			} catch (MoveErrorException $e) {
				$this->assertSame($code, $e->errorCode);
			}
		}
	}

	public function testThePostsOfTheRetiredIdentitysTimeArePublishedAgainAPageAtATime(): void {
		$this->posts = [$this->post(PostRepublisher::PAGE + 3, 5000)];
		for ($nid = PostRepublisher::PAGE + 2; $nid >= 1; $nid--) {
			$this->posts[] = $this->post($nid, 2000);
		}
		$this->posts[] = $this->post(0, 500);
		$this->imported = [self::ACTOR . '/7'];
		$published = [];
		$this->publisher->method('publishPost')->willReturnCallback(function (Stream $post) use (&$published): ?CommitResult {
			$published[] = $post->getId();

			return null;
		});
		$move = self::move();
		$move->progress['republish'] = ['count' => 3, 'from' => 1000, 'until' => 3000, 'state' => 'queued', 'published' => 0];

		$this->republisher->run($move);
		$this->assertCount(PostRepublisher::PAGE - 2, $published, 'neither a post written after the move nor one brought over from elsewhere');
		$this->assertNotContains(self::ACTOR . '/7', $published);
		$this->assertSame('queued', $move->progress['republish']['state']);
		$this->assertCount(1, $this->queued, 'the next page is queued');

		$this->republisher->run($move);
		$this->assertNotContains(self::ACTOR . '/0', $published, 'nor one older than the identity');
		$this->assertCount(PostRepublisher::PAGE + 1, $published);
		$this->assertSame('done', $this->stored?->progress['republish']['state']);
	}
}
