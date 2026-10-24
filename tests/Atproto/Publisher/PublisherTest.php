<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PresenceService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Publisher\VideoUploadService;
use OCA\Social\Atproto\Repository\CommitResult;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\VerificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Which posts go, that a post goes once, what a delete and an edit do, and
 * what the reconcile pass picks up.
 */
#[AllowMockObjectsWithoutExpectations]
class PublisherTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const POST = 'https://social.test/@alice/1';

	/** @var VideoUploadService&MockObject */
	private VideoUploadService $videos;
	private AtprotoConfig $config;
	private bool $atprotoEnabled = true;
	/** what the post's video is, as the upload service says */
	private array $video = ['state' => 'none'];
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var RecordMapper&MockObject */
	private RecordMapper $mapper;
	/** @var array<string, mixed> the value returned by the post mapper */
	private array $mappedPost = [];
	/** @var StreamRequest&MockObject */
	private StreamRequest $streamRequest;
	/** @var ITimeFactory&MockObject */
	private ITimeFactory $time;
	private Publisher $publisher;
	private Identity $identity;
	/** @var StoredRecord[] */
	private array $records = [];
	/** the profile record as stored */
	private array $profile = ['$type' => RecordMapper::PROFILE];
	/** @var string[] the posts that were brought over rather than written here */
	private array $importedPosts = [];
	/** @var ImportedPostsRequest&MockObject */
	private ImportedPostsRequest $imported;
	/** when the author last switched back on for Bluesky; 0 for never */
	private int $onSince = 0;
	/** the account's latest move away, as the moves table has it */
	private ?Move $moveAway = null;
	/** what the durable cache's table holds, by namespace and key */
	private array $shared = [];
	private DurableCache $durableCache;
	/** the stream number of the last post made */
	private int $nid = 0;

	protected function setUp(): void {
		$this->config = $this->createMock(AtprotoConfig::class);
		$this->config->method('isEnabled')->willReturnCallback(fn (): bool => $this->atprotoEnabled);
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (): Identity => $this->identity);
		$identities->method('getByDid')->willReturnCallback(fn (): Identity => $this->identity);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('getRecordsByLocalId')->willReturnCallback(fn (string $id): array => array_values(array_filter($this->records, static fn (StoredRecord $r): bool => $r->localId === $id)));
		$this->repositories->method('getRecord')->willReturnCallback(fn (): StoredRecord => new StoredRecord(self::DID, RecordMapper::PROFILE, 'self', Cid::forRaw('p'), DagCbor::encode($this->profile), '', 0));
		$this->mapper = $this->createMock(RecordMapper::class);
		$this->mappedPost = ['record' => ['$type' => RecordMapper::POST, 'text' => 'hi', 'createdAt' => '2026-10-08T10:00:00.000Z'], 'truncated' => false];
		$this->mapper->method('post')->willReturnCallback(fn (): array => $this->mappedPost);
		$this->mapper->method('sourceText')->willReturn('hi');
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$actors = $this->createMock(CacheActorService::class);
		$actor = new Person();
		$actor->setId('https://social.test/@alice');
		$actor->setLocal(true);
		$actors->method('getFromId')->willReturn($actor);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1760000000);
		$this->videos = $this->createMock(VideoUploadService::class);
		$this->videos->method('forPost')->willReturnCallback(fn (): array => $this->video);
		$this->imported = $this->createMock(ImportedPostsRequest::class);
		$this->imported->method('isImported')->willReturnCallback(fn (string $actor, string $post): bool => in_array($post, $this->importedPosts, true));
		$presence = $this->createMock(PresenceService::class);
		$presence->method('writtenWhileOff')->willReturnCallback(fn (Person $author, Stream $post): bool => $post->getPublishedTime() < $this->onSince);
		$moves = $this->createMock(AtprotoMoveRequest::class);
		$moves->method('latestOfDid')->willReturnCallback(fn (string $did, string $direction): ?Move => $direction === Move::AWAY ? $this->moveAway : null);
		$this->durableCache = $this->createStub(DurableCache::class);
		$this->durableCache->method('getShared')->willReturnCallback(fn (string $namespace, string $key): mixed => $this->shared[$namespace . '/' . $key] ?? null);
		$this->durableCache->method('setShared')->willReturnCallback(function (string $namespace, string $key, mixed $value): void {
			$this->shared[$namespace . '/' . $key] = $value;
		});
		$this->durableCache->method('removeShared')->willReturnCallback(function (string $namespace, string $key): void {
			unset($this->shared[$namespace . '/' . $key]);
		});
		$this->publisher = new Publisher($this->config, $identities, $this->repositories, $this->mapper, $this->videos, $this->streamRequest, $actors, $this->time, new NullLogger(), $this->imported, $this->durableCache, $presence, null, $moves);
	}

	public function testAPostWaitsWhileTheAccountMovesAwayAndGoesBeforeTheDidMoves(): void {
		foreach ([[Move::RUNNING, Move::STEP_BLOBS], [Move::NEEDS_DECISION, Move::STEP_CHECK], [Move::FAILED, Move::STEP_ACTIVATE]] as [$state, $step]) {
			$this->moveAway = new Move(1, self::DID, 'alice', Move::AWAY, 'https://pds.example.com', '', 'alice.pds.example.com', $step, $state);
			$this->repositories->expects($this->never())->method('write');
			$this->assertNull($this->publisher->publishPost($this->post()), $state . ' at ' . $step);
		}
	}

	public function testTheBacklogOfAMoveIsPublishedOldestFirstDespiteThePause(): void {
		$this->moveAway = new Move(1, self::DID, 'alice', Move::AWAY, 'https://pds.example.com', '', 'alice.pds.example.com', Move::STEP_CHECK, Move::RUNNING);
		$older = $this->post(1760000000 - 100);
		$older->setId(self::POST . '0');
		$before = $this->post(1760000000 - 9000);
		$before->setId(self::POST . 'old');
		$this->streamRequest->method('getByAuthor')->willReturn([$this->post(), $older, $before]);
		$written = [];
		$this->repositories->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $writes) use (&$written): CommitResult {
			$written[] = $writes[0]->localId;

			return $this->written();
		});

		$this->assertSame(2, $this->publisher->publishBacklog('https://social.test/@alice', 1760000000 - 1000));

		$this->assertSame([self::POST . '0', self::POST], $written, 'only what was written since the move began, oldest first');
		$this->assertNull($this->publisher->publishPost($this->post()), 'the pause holds again afterwards');
	}

	public function testACalledOffMoveNoLongerHoldsPostsBack(): void {
		$this->moveAway = new Move(1, self::DID, 'alice', Move::AWAY, 'https://pds.example.com', '', 'alice.pds.example.com', Move::STEP_BLOBS, Move::CANCELLED);
		$this->repositories->expects($this->once())->method('write')->willReturn($this->written());

		$this->assertNotNull($this->publisher->publishPost($this->post()));
	}

	public function testAPublicPostIsWrittenOnce(): void {
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static function (array $writes): bool {
			return count($writes) === 1 && $writes[0]->action === RepoWrite::CREATE && $writes[0]->once && $writes[0]->collection === RecordMapper::POST && $writes[0]->localId === self::POST;
		}))->willReturnCallback(function (string $did, PrivateKey $key, array $writes): CommitResult {
			$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $writes[0]->rkey, Cid::forRaw('r'), $this->mappedBytes(), self::POST, 0);

			return $this->written();
		});

		$this->assertNotNull($this->publisher->publishPost($this->post()));
		$this->assertNull($this->publisher->publishPost($this->post()), 'already there');
	}

	public function testAThreadLargerThanOneRepositoryCommitIsWrittenInBoundedChunks(): void {
		$this->mappedPost['continuations'] = array_fill(0, RepositoryService::MAX_WRITES, ['text' => 'continued', 'facets' => []]);
		$chunks = [];
		$this->repositories->expects($this->exactly(2))->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $writes) use (&$chunks): CommitResult {
			$chunks[] = $writes;

			return $this->written();
		});

		$this->assertNotNull($this->publisher->publishPost($this->post()));
		$this->assertSame([RepositoryService::MAX_WRITES, 1], array_map('count', $chunks));
		$this->assertSame(RepoWrite::CREATE, $chunks[0][0]->action);
		$this->assertSame(RecordMapper::POST, $chunks[0][0]->collection);
	}

	public function testRetryCompletesAnExistingPartialThread(): void {
		$this->mappedPost['continuations'] = [
			['text' => 'second part', 'facets' => []],
			['text' => 'third part', 'facets' => []],
		];
		$root = $this->mappedPost['record'];
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forDagCbor(DagCbor::encode($root)), DagCbor::encode($root), self::POST, 0);
		$writes = [];
		$this->repositories->expects($this->once())->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $batch) use (&$writes): CommitResult {
			$writes = $batch;

			return $this->written();
		});

		$this->assertNull($this->publisher->publishPost($this->post()));
		$this->assertCount(2, $writes);
		$this->assertSame([RepoWrite::CREATE, RepoWrite::CREATE], array_column($writes, 'action'));
		$this->assertSame(['second part', 'third part'], array_column(array_map(static fn (RepoWrite $write): array => $write->record, $writes), 'text'));
	}

	public function testAPostAnotherWorkerPublishedMeanwhileIsNotPublishedAgain(): void {
		// the repository found the post's record under its lock and wrote nothing
		$this->repositories->expects($this->once())->method('write')->willReturn(null);

		$this->assertNull($this->publisher->publishPost($this->post()));
	}

	public function testARecordAnotherWorkerWroteMeanwhileIsNotWrittenAgain(): void {
		$actor = new Person();
		$actor->setId('https://social.test/@alice');
		$actor->setLocal(true);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(
			static fn (array $writes): bool => $writes[0]->once && $writes[0]->localId === 'https://social.test/like/1',
		))->willReturn(null);

		$this->assertFalse($this->publisher->writeRecord($actor, RecordMapper::LIKE, ['$type' => RecordMapper::LIKE], 'https://social.test/like/1'));
	}

	public function testAPostWaitsForItsVideoAndGoesWithIt(): void {
		$this->video = ['state' => 'waiting'];
		$this->repositories->expects($this->once())->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $writes): CommitResult {
			$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $writes[0]->rkey, Cid::forRaw('r'), '', self::POST, 0);

			return $this->written();
		});
		$this->assertNull($this->publisher->publishPost($this->post()), 'the video service is making the video');

		$blob = new BlobRef(self::DID, Cid::forRaw('v'), 'https://social.test/documents/local/8', 'video/mp4', 10);
		$this->video = ['state' => 'ready', 'blob' => $blob, 'alt' => '', 'width' => 0, 'height' => 0];
		$this->mapper->expects($this->once())->method('post')->with($this->anything(), $this->anything(), $this->anything(), $this->video)
			->willReturn(['record' => ['$type' => RecordMapper::POST, 'text' => 'x', 'createdAt' => '2026-10-08T10:00:00.000Z'], 'truncated' => false]);
		$this->assertNotNull($this->publisher->publishPost($this->post()));
	}

	public function testDeletingAPostForgetsItsVideo(): void {
		$this->videos->expects($this->once())->method('forget')->with(self::POST);

		$this->assertFalse($this->publisher->deletePost(self::POST));
	}

	public function testDeletingALargeThreadUsesBoundedCommitsAndRemovesTheRootLast(): void {
		$rootRkey = '3kznmn7xqxl00';
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $rootRkey, Cid::forRaw('root'), '', self::POST, 0);
		for ($index = 1; $index <= RepositoryService::MAX_WRITES; $index++) {
			$rkey = '3kznmn7xqxl' . str_pad((string)$index, 3, '0', STR_PAD_LEFT);
			$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $rkey, Cid::forRaw('part' . $index), '', self::POST, 0);
		}
		$chunks = [];
		$this->repositories->expects($this->exactly(2))->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $writes) use (&$chunks): CommitResult {
			$chunks[] = $writes;

			return $this->written();
		});

		$this->assertTrue($this->publisher->deletePost(self::POST));
		$this->assertSame([RepositoryService::MAX_WRITES, 1], array_map('count', $chunks));
		$this->assertSame(RepoWrite::DELETE, $chunks[0][0]->action);
		$this->assertNotSame($rootRkey, $chunks[0][0]->rkey);
		$this->assertSame($rootRkey, $chunks[1][0]->rkey);
	}

	public function testOnlyPublicLocalPostsGo(): void {
		$this->repositories->expects($this->never())->method('write');

		$followers = $this->post();
		$followers->setVisibility(Stream::TYPE_FOLLOWERS);
		$this->assertNull($this->publisher->publishPost($followers));

		$remote = $this->post();
		$remote->setLocal(false);
		$this->assertNull($this->publisher->publishPost($remote));

		$unlisted = $this->post();
		$unlisted->setVisibility(Stream::TYPE_UNLISTED);
		$this->assertNull($this->publisher->publishPost($unlisted));

		$this->assertFalse($this->publisher->goesToBluesky($followers));
		$this->assertTrue($this->publisher->goesToBluesky($this->post()));
	}

	public function testARestrictedQuotePolicyWritesAPostgateUnderThePostsRkey(): void {
		$this->mapper->method('postgate')->willReturnCallback(static fn (Stream $post, string $uri): array => ['$type' => RecordMapper::POSTGATE, 'post' => $uri, 'embeddingRules' => [['$type' => RecordMapper::POSTGATE . '#disableRule']], 'createdAt' => '2026-10-08T10:00:00.000Z']);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static function (array $writes): bool {
			[$post, $gate] = $writes;

			return count($writes) === 2
				&& $post->collection === RecordMapper::POST && $gate->collection === RecordMapper::POSTGATE
				&& $post->rkey === $gate->rkey
				&& $gate->record['post'] === 'at://' . self::DID . '/app.bsky.feed.post/' . $post->rkey;
		}))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertNotNull($this->publisher->publishPost($this->post()));
	}

	public function testADeleteTakesThePostgateWithIt(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POSTGATE, '3kznmn7xqxl22', Cid::forRaw('g'), '', self::POST, 0);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => array_map(static fn (RepoWrite $w): string => $w->action . ' ' . $w->collection, $writes) === [RepoWrite::DELETE . ' ' . RecordMapper::POSTGATE, RepoWrite::DELETE . ' ' . RecordMapper::POST]))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertTrue($this->publisher->deletePost(self::POST));
	}

	public function testANewReplyRuleRewritesTheGatesInOneCommit(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POSTGATE, '3kznmn7xqxl22', Cid::forRaw('g'), '', self::POST, 0);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::THREADGATE, '3kznmn7xqxl22', Cid::forRaw('t'), '', self::POST, 0);
		$this->mapper->method('postgate')->willReturn(null);
		$this->mapper->method('threadgate')->willReturnCallback(static fn (Stream $post, string $uri): array => ['$type' => RecordMapper::THREADGATE, 'post' => $uri, 'allow' => [], 'createdAt' => '2026-10-09T10:00:00.000Z']);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => array_map(static fn (RepoWrite $w): string => $w->action . ' ' . $w->collection . ' ' . $w->rkey, $writes) === [
			RepoWrite::DELETE . ' ' . RecordMapper::POSTGATE . ' 3kznmn7xqxl22',
			RepoWrite::UPDATE . ' ' . RecordMapper::THREADGATE . ' 3kznmn7xqxl22',
		]))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->publisher->updateGates($this->post());
	}

	public function testAFirstReplyRuleMakesTheThreadgateAndAnUnpublishedPostNone(): void {
		$this->mapper->method('postgate')->willReturn(null);
		$this->mapper->method('threadgate')->willReturnCallback(static fn (Stream $post, string $uri): array => ['$type' => RecordMapper::THREADGATE, 'post' => $uri, 'allow' => [], 'createdAt' => '2026-10-09T10:00:00.000Z']);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 1
			&& $writes[0]->action === RepoWrite::CREATE && $writes[0]->collection === RecordMapper::THREADGATE && $writes[0]->rkey === '3kznmn7xqxl22'
			&& $writes[0]->record['post'] === 'at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->publisher->updateGates($this->post());
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->publisher->updateGates($this->post());
	}

	public function testADeleteRemovesTheRecord(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => $writes[0]->action === RepoWrite::DELETE && $writes[0]->rkey === '3kznmn7xqxl22'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertTrue($this->publisher->deletePost(self::POST));
		$this->records = [];
		$this->assertFalse($this->publisher->deletePost(self::POST));
	}

	public function testAnEditUpdatesTheSameRecordRegardlessOfAge(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->repositories->expects($this->exactly(3))->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 1
			&& in_array($writes[0]->action, [RepoWrite::UPDATE, RepoWrite::CREATE], true)
			&& ($writes[0]->action !== RepoWrite::UPDATE || $writes[0]->rkey === '3kznmn7xqxl22')))->willReturnCallback(fn (): CommitResult => $this->written());

		$fresh = $this->post(1760000000 - 60);
		$this->assertSame('updated', $this->publisher->editPost($fresh));

		$old = $this->post(1760000000 - 3600);
		$this->assertSame('updated', $this->publisher->editPost($old));

		$this->records = [];
		$this->assertSame('updated', $this->publisher->editPost($old));
	}

	public function testAnEditPublishesTheCurrentPostWhenItsFirstPublicationWasMissed(): void {
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 1
			&& $writes[0]->action === RepoWrite::CREATE && $writes[0]->localId === self::POST
			&& $writes[0]->record['text'] === 'hi'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertSame('updated', $this->publisher->editPost($this->post(1760000000 - 60 * 60 * 24 * 30)));
	}

	public function testRetryCompletesAnExistingRootWithMissingContinuationRecords(): void {
		$rootRkey = '3kznmn7xqxl22';
		$root = ['$type' => RecordMapper::POST, 'text' => 'first part…', 'createdAt' => '2026-10-08T10:00:00.000Z'];
		$rootBytes = DagCbor::encode($root);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $rootRkey, Cid::forDagCbor($rootBytes), $rootBytes, self::POST, 0);
		$this->mappedPost = [
			'record' => $root,
			'truncated' => true,
			'continuations' => [['text' => 'the missing second part', 'facets' => []]],
		];
		$this->mapper->method('sourceText')->willReturn('first part… the missing second part');
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 1
			&& $writes[0]->action === RepoWrite::CREATE
			&& $writes[0]->collection === RecordMapper::POST
			&& $writes[0]->record['text'] === 'the missing second part'
			&& $writes[0]->record['reply']['root']['uri'] === 'at://' . self::DID . '/' . RecordMapper::POST . '/' . $rootRkey))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertNull($this->publisher->publishPost($this->post()));
	}

	public function testEditingALongPostUpdatesItsExistingThreadRecordsInPlace(): void {
		$rootRkey = '3kznmn7xqxl22';
		$continuationRkey = '3kznmn7xqxl23';
		$createdAt = '2026-10-08T10:00:00.000Z';
		$oldRoot = ['$type' => RecordMapper::POST, 'text' => 'before', 'createdAt' => $createdAt];
		$oldRootCid = Cid::forDagCbor(DagCbor::encode($oldRoot))->toString();
		$oldContinuation = [
			'$type' => RecordMapper::POST,
			'text' => 'old second part',
			'createdAt' => $createdAt,
			'reply' => [
				'root' => ['uri' => 'at://' . self::DID . '/' . RecordMapper::POST . '/' . $rootRkey, 'cid' => $oldRootCid],
				'parent' => ['uri' => 'at://' . self::DID . '/' . RecordMapper::POST . '/' . $rootRkey, 'cid' => $oldRootCid],
			],
		];
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $rootRkey, Cid::forDagCbor(DagCbor::encode($oldRoot)), DagCbor::encode($oldRoot), self::POST, 0);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $continuationRkey, Cid::forDagCbor(DagCbor::encode($oldContinuation)), DagCbor::encode($oldContinuation), self::POST, 0);

		$newRoot = ['$type' => RecordMapper::POST, 'text' => 'after', 'createdAt' => $createdAt];
		$this->mappedPost = [
			'record' => $newRoot,
			'truncated' => true,
			'continuations' => [['text' => 'new second part', 'facets' => []]],
		];
		$writes = [];
		$this->repositories->expects($this->once())->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $batch) use (&$writes): CommitResult {
			$writes = $batch;

			return $this->written();
		});

		$this->assertSame('updated', $this->publisher->editPost($this->post()));
		$this->assertCount(2, $writes);
		$this->assertSame([RepoWrite::UPDATE, RepoWrite::UPDATE], array_column($writes, 'action'));
		$this->assertSame([$rootRkey, $continuationRkey], array_column($writes, 'rkey'));
		$this->assertSame('after', $writes[0]->record['text']);
		$this->assertSame('new second part', $writes[1]->record['text']);
		$this->assertSame('at://' . self::DID . '/' . RecordMapper::POST . '/' . $rootRkey, $writes[1]->record['reply']['root']['uri']);
		$this->assertSame($writes[1]->record['reply']['root'], $writes[1]->record['reply']['parent']);
		$this->assertSame(Cid::forDagCbor(DagCbor::encode($newRoot))->toString(), $writes[1]->record['reply']['parent']['cid']);
	}

	public function testEditingALongPostDeletesContinuationsThatAreNoLongerNeeded(): void {
		$rootRkey = '3kznmn7xqxl22';
		$continuationRkey = '3kznmn7xqxl23';
		$createdAt = '2026-10-08T10:00:00.000Z';
		$oldRoot = ['$type' => RecordMapper::POST, 'text' => 'before', 'createdAt' => $createdAt];
		$oldRootCid = Cid::forDagCbor(DagCbor::encode($oldRoot))->toString();
		$oldContinuation = [
			'$type' => RecordMapper::POST,
			'text' => 'old second part',
			'createdAt' => $createdAt,
			'reply' => [
				'root' => ['uri' => 'at://' . self::DID . '/' . RecordMapper::POST . '/' . $rootRkey, 'cid' => $oldRootCid],
				'parent' => ['uri' => 'at://' . self::DID . '/' . RecordMapper::POST . '/' . $rootRkey, 'cid' => $oldRootCid],
			],
		];
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $rootRkey, Cid::forDagCbor(DagCbor::encode($oldRoot)), DagCbor::encode($oldRoot), self::POST, 0);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, $continuationRkey, Cid::forDagCbor(DagCbor::encode($oldContinuation)), DagCbor::encode($oldContinuation), self::POST, 0);
		$this->mappedPost = [
			'record' => ['$type' => RecordMapper::POST, 'text' => 'shortened', 'createdAt' => $createdAt],
			'truncated' => false,
		];
		$writes = [];
		$this->repositories->expects($this->once())->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $batch) use (&$writes): CommitResult {
			$writes = $batch;

			return $this->written();
		});

		$this->assertSame('updated', $this->publisher->editPost($this->post()));
		$this->assertCount(2, $writes);
		$this->assertSame(RepoWrite::UPDATE, $writes[0]->action);
		$this->assertSame($rootRkey, $writes[0]->rkey);
		$this->assertSame(RepoWrite::DELETE, $writes[1]->action);
		$this->assertSame($continuationRkey, $writes[1]->rkey);
	}

	public function testAnAlreadyQueuedEditDoesNotWriteAfterBlueskyIsDisabled(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->atprotoEnabled = false;
		$this->repositories->expects($this->never())->method('write');

		$this->assertSame('kept', $this->publisher->editPost($this->post()));
	}

	public function testMakingAPostPrivateStillRemovesItAfterBlueskySyncIsDisabled(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->atprotoEnabled = false;
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 1
			&& $writes[0]->action === RepoWrite::DELETE
			&& $writes[0]->rkey === '3kznmn7xqxl22'))->willReturnCallback(fn (): CommitResult => $this->written());
		$post = $this->post();
		$post->setVisibility(Stream::TYPE_FOLLOWERS);

		$this->assertSame('deleted', $this->publisher->editPost($post));
	}

	public function testAnEditThatMakesAPublishedPostPrivateRemovesItsPublicRecord(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), '', self::POST, 0);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 1
			&& $writes[0]->action === RepoWrite::DELETE
			&& $writes[0]->rkey === '3kznmn7xqxl22'))->willReturnCallback(fn (): CommitResult => $this->written());
		$post = $this->post();
		$post->setVisibility(Stream::TYPE_FOLLOWERS);

		$this->assertSame('deleted', $this->publisher->editPost($post));
	}

	public function testTheStatusForTheDeliveryDialog(): void {
		$this->assertSame(['state' => 'waiting', 'uri' => '', 'url' => ''], $this->publisher->statusOf($this->post()));
		$private = $this->post();
		$private->setVisibility(Stream::TYPE_DIRECT);
		$this->assertSame('not_applicable', $this->publisher->statusOf($private)['state']);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'h']), self::POST, 0);
		$this->assertSame('waiting', $this->publisher->statusOf($this->post())['state'], 'a root record with missing source text is not reported as delivered');
		$this->records = [];
		$warned = $this->post()->setSpoilerText('warning');
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'Content warning: warning\n\nhi']), self::POST, 0);
		$this->assertSame('published', $this->publisher->statusOf($warned)['state'], 'a warning prefix does not hide a complete source body from delivery status');
		$this->records = [];
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'hi old']), self::POST, 0);
		$this->assertSame('waiting', $this->publisher->statusOf($this->post())['state'], 'a stale longer version is not reported as the current edit');
		$this->importedPosts[] = self::POST;
		$this->assertSame('published', $this->publisher->statusOf($this->post())['state'], 'an imported record is not reclassified as an incomplete local publication');
		$this->importedPosts = [];
		$pageUrl = $this->post()->pageUrl();
		$tail = "\n\n" . $pageUrl;
		$rootRkey = '3kznmn7xqxl22';
		$continuationRkey = '3kznmn7xqxl23';
		$rootBytes = DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'h']);
		$rootCid = Cid::forDagCbor($rootBytes);
		$continuation = [
			'$type' => RecordMapper::POST,
			'text' => 'i' . $tail,
			'facets' => [['index' => ['byteStart' => 3, 'byteEnd' => 3 + strlen($pageUrl)], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $pageUrl]]]],
		];
		$this->records = [
			new StoredRecord(self::DID, RecordMapper::POST, $rootRkey, $rootCid, $rootBytes, self::POST, 0),
			new StoredRecord(self::DID, RecordMapper::POST, $continuationRkey, Cid::forRaw('c'), DagCbor::encode($continuation), self::POST, 0),
		];
		$this->assertSame('waiting', $this->publisher->statusOf($this->post())['state'], 'a continuation without a reply chain is not reported as delivered');
		$continuation['reply'] = [
			'root' => ['uri' => 'at://' . self::DID . '/' . RecordMapper::POST . '/' . $rootRkey, 'cid' => $rootCid->toString()],
			'parent' => ['uri' => 'at://' . self::DID . '/' . RecordMapper::POST . '/' . $rootRkey, 'cid' => $rootCid->toString()],
		];
		$continuationBytes = DagCbor::encode($continuation);
		$this->records[1] = new StoredRecord(self::DID, RecordMapper::POST, $continuationRkey, Cid::forDagCbor($continuationBytes), $continuationBytes, self::POST, 0);
		$this->assertSame(['state' => 'published', 'uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', 'url' => 'https://bsky.app/profile/alice.social.test/post/3kznmn7xqxl22'], $this->publisher->statusOf($this->post()));
	}

	public function testPollDeliveryWaitsForEveryOption(): void {
		$poll = new Question();
		$poll->setNid(++$this->nid);
		$poll->setId(self::POST);
		$poll->setLocal(true);
		$poll->setAttributedTo('https://social.test/@alice');
		$poll->setVisibility(Stream::TYPE_PUBLIC);
		$poll->setContent('<p>hi</p>');
		$poll->setPollData(['First choice', 'Second choice'], false, 3600);
		$this->mapper->method('sourcePollOptions')->willReturn(['First choice', 'Second choice']);
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode([
			'$type' => RecordMapper::POST,
			'text' => 'hi\n\nPoll: First choice',
		]), self::POST, 0);

		$this->assertSame('waiting', $this->publisher->statusOf($poll)['state']);

		$this->records[0] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode([
			'$type' => RecordMapper::POST,
			'text' => 'hi\n\nPoll: First choice / Second choice',
		]), self::POST, 0);
		$this->assertSame('published', $this->publisher->statusOf($poll)['state']);
	}

	public function testReconcilePublishesWhatHasNoRecord(): void {
		$published = $this->post();
		$missing = $this->post();
		$missing->setId('https://social.test/@alice/2');
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), $this->mappedBytes(), self::POST, 0);
		$this->streamRequest->method('getLocalPublicSince')->with(1760000000 - Publisher::RECONCILE_WINDOW, 100)->willReturn([$published, $missing]);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => $writes[0]->localId === 'https://social.test/@alice/2'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertSame(1, $this->publisher->reconcile());
	}

	/**
	 * A day of more posts than a pass takes is looked at whole over a few
	 * passes: the newest page every time, for what was missed a moment ago,
	 * and the older ones a page per pass, from the top again at the end.
	 */
	public function testReconcileWalksEveryPostOfTheWindowOverAFewPasses(): void {
		$posts = [];
		for ($i = 1; $i <= 7; $i++) {
			$post = $this->post();
			$post->setId(self::POST . '/' . $i);
			$posts[$i] = $post;
		}
		$this->streamRequest->method('getLocalPublicSince')->willReturnCallback(static function (int $since, int $limit, int $before) use ($posts): array {
			$below = array_filter($posts, static fn (Note $post): bool => $before === 0 || (int)$post->getNid() < $before);

			return array_slice(array_reverse($below), 0, $limit);
		});
		$written = [];
		$this->repositories->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $writes) use (&$written): CommitResult {
			$written[] = (int)substr($writes[0]->localId, strlen(self::POST . '/'));

			return $this->written();
		});

		$passes = [];
		for ($pass = 0; $pass < 4; $pass++) {
			$written = [];
			$this->publisher->reconcile(2);
			$passes[] = $written;
		}

		$this->assertSame([[7, 6, 5, 4], [7, 6, 3, 2], [7, 6, 1], [7, 6, 5, 4]], $passes);
	}

	/**
	 * The records are walked the same way, newest first; a record a moving
	 * repository brought is not one of them (the query leaves it out).
	 */
	public function testReconcileWalksEveryRecordOfTheWindowNewestFirst(): void {
		$this->streamRequest->method('getLocalPublicSince')->willReturn([]);
		$records = [];
		for ($id = 1; $id <= 5; $id++) {
			$records[$id] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl2' . $id, Cid::forRaw('r' . $id), $this->mappedBytes(), self::POST . '/' . $id, 0);
		}
		$this->repositories->method('getRecordsSince')->willReturnCallback(static function (string $collection, int $since, int $limit, int $before) use ($records): array {
			$below = array_filter($records, static fn (int $id): bool => $before === 0 || $id < $before, ARRAY_FILTER_USE_KEY);

			return array_slice(array_reverse($below, true), 0, $limit, true);
		});
		$checked = [];
		$this->streamRequest->method('getStreamById')->willReturnCallback(function (string $id) use (&$checked): Note {
			$checked[] = (int)substr($id, strlen(self::POST . '/'));

			return $this->post();
		});

		$passes = [];
		for ($pass = 0; $pass < 3; $pass++) {
			$checked = [];
			$this->publisher->reconcile(2);
			$passes[] = $checked;
		}

		$this->assertSame([[5, 4, 3, 2], [5, 4, 1], [5, 4, 3, 2]], $passes);
	}

	public function testReconcileUpdatesAMissedEditInPlaceAndRemovesADeletedPost(): void {
		$edited = $this->post(1760000000 - 60 * 60 * 24 * 30);
		$stale = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'before']), self::POST, 0);
		$orphan = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl33', Cid::forRaw('o'), $this->mappedBytes(), 'https://social.test/@alice/3', 0);
		$this->records = [$stale, $orphan];
		$this->streamRequest->method('getLocalPublicSince')->willReturn([$edited]);
		$this->repositories->method('getRecordsSince')->with(RecordMapper::POST, 0, 100)->willReturn([$stale, $orphan]);
		$this->streamRequest->method('getStreamById')->willReturnCallback(static function (string $id) use ($edited): Note {
			if ($id === self::POST) {
				return $edited;
			}
			throw new StreamNotFoundException();
		});
		$writes = [];
		$this->repositories->expects($this->exactly(2))->method('write')->willReturnCallback(function (string $did, $key, array $batch) use (&$writes): CommitResult {
			$writes[] = array_map(static fn (RepoWrite $w): string => $w->action . ' ' . $w->rkey, $batch);

			return $this->written();
		});

		$this->assertSame(2, $this->publisher->reconcile());
		$this->assertSame([RepoWrite::UPDATE . ' 3kznmn7xqxl22'], $writes[0], 'the existing URI receives the edit');
		$this->assertSame([RepoWrite::DELETE . ' 3kznmn7xqxl33'], $writes[1], 'the orphan record is removed');
	}

	public function testReconcileRemovesAnOlderPostThatBecamePrivate(): void {
		$record = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), $this->mappedBytes(), self::POST, 0);
		$this->records[] = $record;
		$post = $this->post(1760000000 - 60 * 60 * 24 * 30);
		$post->setVisibility(Stream::TYPE_FOLLOWERS);
		$this->streamRequest->method('getStreamById')->with(self::POST)->willReturn($post);
		$this->repositories->method('getRecordsSince')->with(RecordMapper::POST, 0, 100)->willReturn([$record]);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 1
			&& $writes[0]->action === RepoWrite::DELETE
			&& $writes[0]->rkey === '3kznmn7xqxl22'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertSame(1, $this->publisher->reconcile());
	}

	public function testEditingAPinnedPostRefreshesItsStrongReferenceCid(): void {
		$record = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'before']), self::POST, 0);
		$this->records[] = $record;
		$this->profile['pinnedPost'] = ['uri' => $record->uri(), 'cid' => $record->cid->toString()];
		$editedRecord = ['$type' => RecordMapper::POST, 'text' => 'hi', 'createdAt' => '2026-10-08T10:00:00.000Z'];
		$editedCid = Cid::forDagCbor(DagCbor::encode($editedRecord))->toString();
		$this->mapper->method('profile')->willReturn(['$type' => RecordMapper::PROFILE, 'pinnedPost' => ['uri' => $record->uri(), 'cid' => $editedCid]]);
		$writes = [];
		$this->repositories->expects($this->exactly(2))->method('write')->willReturnCallback(function (string $did, $key, array $batch) use (&$writes): CommitResult {
			$writes[] = $batch;

			return $this->written();
		});

		$this->assertSame('updated', $this->publisher->editPost($this->post()));
		$this->assertSame(RepoWrite::UPDATE, $writes[0][0]->action);
		$this->assertSame('3kznmn7xqxl22', $writes[0][0]->rkey);
		$this->assertSame(RecordMapper::PROFILE, $writes[1][0]->collection);
		$this->assertSame(RepoWrite::UPDATE, $writes[1][0]->action);
		$this->assertSame($editedCid, $writes[1][0]->record['pinnedPost']['cid']);
	}

	public function testAnEditUpdatesChangedReplyRulesWithoutChangingThePostRecord(): void {
		$postRecord = ['$type' => RecordMapper::POST, 'text' => 'hi', 'createdAt' => '2026-10-08T10:00:00.000Z'];
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forDagCbor(DagCbor::encode($postRecord)), DagCbor::encode($postRecord), self::POST, 0);
		$oldGate = ['$type' => RecordMapper::THREADGATE, 'post' => 'at://' . self::DID . '/' . RecordMapper::POST . '/3kznmn7xqxl22', 'allow' => [], 'createdAt' => '2026-10-08T10:00:00.000Z'];
		$this->records[] = new StoredRecord(self::DID, RecordMapper::THREADGATE, '3kznmn7xqxl22', Cid::forDagCbor(DagCbor::encode($oldGate)), DagCbor::encode($oldGate), self::POST, 0);
		$newGate = $oldGate;
		$newGate['allow'] = [['$type' => RecordMapper::THREADGATE . '#mentionRule']];
		$this->mapper->method('threadgate')->willReturn($newGate);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => count($writes) === 1
			&& $writes[0]->action === RepoWrite::UPDATE
			&& $writes[0]->collection === RecordMapper::THREADGATE
			&& $writes[0]->record === $newGate))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertSame('updated', $this->publisher->editPost($this->post()));
	}

	public function testDeletingThePinnedPostWritesTheProfileAgain(): void {
		$record = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), $this->mappedBytes(), self::POST, 0);
		$this->records[] = $record;
		$this->profile['pinnedPost'] = ['uri' => $record->uri(), 'cid' => $record->cid->toString()];
		$this->mapper->method('profile')->willReturn(['$type' => RecordMapper::PROFILE]);
		$writes = [];
		$this->repositories->expects($this->exactly(2))->method('write')->willReturnCallback(function (string $did, $key, array $batch) use (&$writes): CommitResult {
			$writes[] = $batch[0]->collection . ' ' . $batch[0]->action;

			return $this->written();
		});

		$this->publisher->deletePost(self::POST);

		$this->assertSame([RecordMapper::POST . ' ' . RepoWrite::DELETE, RecordMapper::PROFILE . ' ' . RepoWrite::UPDATE], $writes, 'the profile no longer names it');
	}

	/** A changed name is a changed profile: the instance's verification of the account follows it. */
	public function testPublishingTheProfileWritesTheVerificationOfTheAccountAgain(): void {
		$this->mapper->method('profile')->willReturn(['$type' => RecordMapper::PROFILE, 'displayName' => 'Alice A.']);
		$this->repositories->method('write')->willReturn($this->written());
		$alice = (new Person())->setId('https://social.test/@alice');
		$alice->setLocal(true);
		$verifications = $this->createMock(VerificationService::class);
		$verifications->expects($this->once())->method('refresh')->with($this->identicalTo($alice));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(VerificationService::class)->willReturn($verifications);
		(new \ReflectionProperty(Publisher::class, 'container'))->setValue($this->publisher, $container);

		$this->assertTrue($this->publisher->publishProfile($alice));
	}

	public function testAFailingVerificationDoesNotFailTheProfile(): void {
		$this->mapper->method('profile')->willReturn(['$type' => RecordMapper::PROFILE, 'displayName' => 'Alice A.']);
		$this->repositories->method('write')->willReturn($this->written());
		$alice = (new Person())->setId('https://social.test/@alice');
		$alice->setLocal(true);
		$verifications = $this->createMock(VerificationService::class);
		$verifications->method('refresh')->willThrowException(new \RuntimeException('the AppView is down'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($verifications);
		(new \ReflectionProperty(Publisher::class, 'container'))->setValue($this->publisher, $container);

		$this->assertTrue($this->publisher->publishProfile($alice));
	}

	public function testReconcileLeavesAnImportedPostAsItCame(): void {
		$moved = $this->post(1760000000 - 60);
		$archived = $this->post(1760000000 - 60);
		$archived->setId('https://social.test/@alice/2');
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'as Bluesky had it']), self::POST, 0);
		$this->importedPosts = [self::POST, 'https://social.test/@alice/2'];
		$this->streamRequest->method('getLocalPublicSince')->willReturn([$moved, $archived]);
		$this->repositories->expects($this->never())->method('write');

		$this->assertSame(0, $this->publisher->reconcile(), 'neither the moved record is rewritten nor the archived post published');
	}

	public function testReconcileLeavesAnUnchangedRecordAlone(): void {
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), $this->mappedBytes(), self::POST, 0);
		$this->streamRequest->method('getLocalPublicSince')->willReturn([$this->post(1760000000 - 60)]);
		$this->repositories->expects($this->never())->method('write');

		$this->assertSame(0, $this->publisher->reconcile());
		$this->assertSame('kept', $this->publisher->editPost($this->post(1760000000 - 60)), 'an edit that maps to the same record writes nothing');
	}

	public function testAFollowRecordIsWrittenOncePerObjectAndRemovedByIt(): void {
		$actor = new Person();
		$actor->setId('https://social.test/@alice');
		$actor->setLocal(true);
		$record = ['$type' => RecordMapper::FOLLOW, 'subject' => 'did:plc:other', 'createdAt' => '2026-10-08T10:00:00.000Z'];
		$this->repositories->expects($this->exactly(2))->method('write')->willReturnCallback(function (string $did, $key, array $writes): CommitResult {
			static $n = 0;
			$n++;
			if ($n === 1) {
				$this->assertSame(RepoWrite::CREATE, $writes[0]->action);
				$this->assertSame(RecordMapper::FOLLOW, $writes[0]->collection);
				$this->assertSame('https://social.test/follow/1', $writes[0]->localId);
				$this->records[] = new StoredRecord(self::DID, RecordMapper::FOLLOW, $writes[0]->rkey, Cid::forRaw('f'), '', 'https://social.test/follow/1', 0);
			} else {
				$this->assertSame(RepoWrite::DELETE, $writes[0]->action);
				$this->assertSame($this->records[0]->rkey, $writes[0]->rkey);
			}

			return $this->written();
		});

		$this->assertTrue($this->publisher->writeRecord($actor, RecordMapper::FOLLOW, $record, 'https://social.test/follow/1'));
		$this->assertFalse($this->publisher->writeRecord($actor, RecordMapper::FOLLOW, $record, 'https://social.test/follow/1'), 'written once');
		$this->assertFalse($this->publisher->removeRecord(RecordMapper::LIKE, 'https://social.test/follow/1'), 'another collection is not it');
		$this->assertTrue($this->publisher->removeRecord(RecordMapper::FOLLOW, 'https://social.test/follow/1'));
		$this->records = [];
		$this->assertFalse($this->publisher->removeRecord(RecordMapper::FOLLOW, 'https://social.test/follow/1'));
	}

	public function testNoRecordForARemoteActor(): void {
		$remote = new Person();
		$remote->setId('https://mastodon.test/users/bob');
		$this->repositories->expects($this->never())->method('write');
		$this->assertFalse($this->publisher->writeRecord($remote, RecordMapper::LIKE, ['$type' => RecordMapper::LIKE], 'https://social.test/like/1'));
	}

	/** A setting kept as the one record of its collection: written once, rewritten when it changed. */
	public function testASelfRecordIsWrittenUnderSelfAndOnlyWhenItChanged(): void {
		$collection = 'chat.bsky.actor.declaration';
		$stored = null;
		$repositories = $this->createMock(RepositoryService::class);
		$repositories->method('getRecord')->willReturnCallback(function (string $did, string $c, string $rkey) use (&$stored, $collection): ?StoredRecord {
			return ($c === $collection && $rkey === 'self') ? $stored : null;
		});
		$writes = [];
		$repositories->method('write')->willReturnCallback(function (string $did, PrivateKey $key, array $w) use (&$writes, &$stored, $collection): CommitResult {
			$writes[] = $w[0];
			$stored = new StoredRecord(self::DID, $collection, 'self', Cid::forRaw('d'), DagCbor::encode($w[0]->record), '', 0);

			return $this->written();
		});
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn($this->identity);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$publisher = new Publisher($config, $identities, $repositories, $this->mapper, $this->videos, $this->streamRequest, $this->createMock(CacheActorService::class), $this->time, new NullLogger(), $this->imported, $this->durableCache);
		$alice = new Person();
		$alice->setId('https://social.test/@alice');
		$alice->setLocal(true);

		$this->assertFalse($publisher->hasSelfRecord($alice, $collection));
		$this->assertTrue($publisher->writeSelfRecord($alice, $collection, ['$type' => $collection, 'allowIncoming' => 'following']));
		$this->assertTrue($publisher->hasSelfRecord($alice, $collection));
		$this->assertFalse($publisher->writeSelfRecord($alice, $collection, ['$type' => $collection, 'allowIncoming' => 'following']), 'unchanged');
		$this->assertTrue($publisher->writeSelfRecord($alice, $collection, ['$type' => $collection, 'allowIncoming' => 'none']));

		$this->assertSame([RepoWrite::CREATE, RepoWrite::UPDATE], [$writes[0]->action, $writes[1]->action]);
		$this->assertSame(['self', 'self'], [$writes[0]->rkey, $writes[1]->rkey]);
		$this->assertSame('', $writes[0]->localId, 'a setting, which no object here stands for');

		$remote = new Person();
		$remote->setId('https://mastodon.test/users/bob');
		$this->assertFalse($publisher->writeSelfRecord($remote, $collection, ['$type' => $collection, 'allowIncoming' => 'all']));
	}

	private function mappedBytes(): string {
		return DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'hi', 'createdAt' => '2026-10-08T10:00:00.000Z']);
	}

	/**
	 * Switched off for Bluesky, nothing is written for the person: no post,
	 * no edit, no gate, no like. A delete still goes, so a post taken back
	 * while off does not come back with the account.
	 */
	public function testNothingIsWrittenWhileThePersonIsOffBlueskyButADeleteGoes(): void {
		$this->identity = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', 'did:key:z', '', Identity::STATE_DEACTIVATED, '', 0);
		$actor = new Person();
		$actor->setId('https://social.test/@alice');
		$actor->setLocal(true);
		$this->mapper->method('threadgate')->willReturn(['$type' => RecordMapper::THREADGATE]);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => $writes[0]->action === RepoWrite::DELETE))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertNull($this->publisher->publishPost($this->post()));
		$this->assertFalse($this->publisher->writeRecord($actor, 'app.bsky.feed.like', ['$type' => 'app.bsky.feed.like'], 'https://social.test/like/1'));
		$this->assertFalse($this->publisher->publishProfile($actor));
		$this->records[] = new StoredRecord(self::DID, RecordMapper::POST, '3kznmn7xqxl22', Cid::forRaw('r'), DagCbor::encode(['$type' => RecordMapper::POST, 'text' => 'before']), self::POST, 0);
		$this->assertSame('kept', $this->publisher->editPost($this->post(1760000000 - 60)), 'an edit is not published while the identity is off');
		$this->publisher->updateGates($this->post());
		$this->assertTrue($this->publisher->deletePost(self::POST));
	}

	/**
	 * On again, the pass that publishes what is missing leaves alone what
	 * was written while the person was off, and publishes what came after.
	 */
	public function testReconcileLeavesWhatWasWrittenWhileOffAlone(): void {
		$this->onSince = 1760000000 - 30;
		$whileOff = $this->post(1760000000 - 60);
		$after = $this->post(1760000000 - 10);
		$after->setId('https://social.test/@alice/2');
		$this->streamRequest->method('getLocalPublicSince')->willReturn([$whileOff, $after]);
		$this->repositories->expects($this->once())->method('write')->with(self::DID, $this->anything(), $this->callback(static fn (array $writes): bool => $writes[0]->localId === 'https://social.test/@alice/2'))->willReturnCallback(fn (): CommitResult => $this->written());

		$this->assertSame(1, $this->publisher->reconcile());
	}

	private function post(int $published = 1760000000 - 10): Note {
		$note = new Note();
		$note->setNid(++$this->nid);
		$note->setId(self::POST);
		$note->setLocal(true);
		$note->setAttributedTo('https://social.test/@alice');
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setContent('<p>hi</p>');
		$note->setPublishedTime($published);

		return $note;
	}

	private function written(): CommitResult {
		return new CommitResult(self::DID, Cid::forRaw('c'), '3kznmn7xqxl22', 1, []);
	}
}
