<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Object\AnnounceInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

class ActivityServiceTest extends TestCase {
	private const CLOUD_URL = 'https://cloud.example';
	private const CLOUD_HOST = 'cloud.example';
	private const ALICE_ID = 'https://social.example/@alice';
	private const NOTE_ID = 'https://social.example/@alice/42';
	private const BOB_INBOX = 'https://remote.example/users/bob/inbox';
	private const TOKEN = 'a0b1c2d3-e4f5-4678-9abc-def012345678';

	private FollowsRequest|MockObject $followsRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private SignatureService|MockObject $signatureService;
	private RequestQueueService|MockObject $requestQueueService;
	private CurlService|MockObject $curlService;
	private ConfigService|MockObject $configService;
	private ActorsRequest|MockObject $actorsRequest;
	private NoteInterface|MockObject $noteInterface;
	private AnnounceInterface|MockObject $announceInterface;
	private ActivityService $service;

	protected function setUp(): void {
		$this->noteInterface = $this->createMock(NoteInterface::class);
		$this->announceInterface = $this->createMock(AnnounceInterface::class);
		$this->bootActivityPub([
			NoteInterface::class => $this->noteInterface,
			AnnounceInterface::class => $this->announceInterface,
		]);

		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->signatureService = $this->createMock(SignatureService::class);
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->curlService = $this->createMock(CurlService::class);

		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getCloudHost')->willReturn(self::CLOUD_HOST);

		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->service = new ActivityService(
			$this->createMock(StreamRequest::class),
			$this->followsRequest,
			$this->cacheActorsRequest,
			$this->signatureService,
			$this->requestQueueService,
			$this->curlService,
			$this->configService,
			$this->actorsRequest,
			new NullLogger()
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	/**
	 * @param array<class-string, object> $interfaces
	 */
	private function bootActivityPub(array $interfaces): void {
		$args = [];
		foreach ((new ReflectionClass(AP::class))->getConstructor()->getParameters() as $parameter) {
			$class = $parameter->getType()->getName();
			if (isset($interfaces[$class])) {
				$args[] = $interfaces[$class];
				continue;
			}
			$mock = $this->createMock($class);
			if ($class === ConfigService::class) {
				$mock->method('getCloudUrl')->willReturn(self::CLOUD_URL);
			}
			$args[] = $mock;
		}
		AP::set(new AP(...$args));
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE_ID);
		$alice->setPreferredUsername('alice');
		$alice->setFollowers(self::ALICE_ID . '/followers');
		$alice->setLocal(true);

		return $alice;
	}

	private function note(): Note {
		$note = new Note();
		$note->setId(self::NOTE_ID);
		$note->setAttributedTo(self::ALICE_ID);
		$note->setContent('hello');
		$note->addInstancePath(new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM));

		return $note;
	}

	private function queue(string $inbox = self::BOB_INBOX, int $type = InstancePath::TYPE_INBOX): RequestQueue {
		$queue = new RequestQueue(
			'{"type":"Like","id":"https://social.example/@alice#like/1"}',
			new InstancePath($inbox, $type, InstancePath::PRIORITY_MEDIUM),
			self::ALICE_ID
		);
		$queue->setToken(self::TOKEN);
		$queue->setTimeout(10);

		return $queue;
	}

	/** Queue a token and let the request flow run to the async hand-off without any inline delivery. */
	private function expectQueuedWithoutInlineDelivery(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);
	}

	/**
	 * Records the resolved InstancePath[] handed to the request queue.
	 *
	 * @param InstancePath[] $paths
	 */
	private function capturePaths(array &$paths): void {
		$paths = [];
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with($this->callback(function (array $instancePaths) use (&$paths): bool {
				$paths = $instancePaths;

				return true;
			}), $this->isInstanceOf(ACore::class), $this->isType('string'))
			// the real queue hands back no token when it was given nothing to
			// send, and callers key off that
			->willReturnCallback(fn (array $instancePaths): string => $instancePaths === [] ? '' : self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);
	}

	// createActivity()

	public function testCreateActivityWrapsItemSignsSavesAndQueuesIt(): void {
		$alice = $this->alice();
		$note = $this->note();

		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Create::class));
		$this->noteInterface->expects($this->once())->method('save')->with($this->identicalTo($note));

		$queued = null;
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with(
				$this->identicalTo($note->getInstancePaths()),
				$this->callback(function (ACore $item) use (&$queued): bool {
					$queued = $item;

					return true;
				}),
				self::ALICE_ID
			)
			->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->with(self::TOKEN, RequestQueue::STATUS_STANDBY)->willReturn([]);

		$activity = null;
		$token = $this->service->createActivity($alice, $note, $activity);

		$this->assertSame(self::TOKEN, $token);
		$this->assertInstanceOf(Create::class, $activity);
		$this->assertSame($activity, $queued);
		$this->assertSame(self::NOTE_ID . '/activity', $activity->getId());
		$this->assertSame($note, $activity->getObject());
		$this->assertSame($activity, $note->getParent());
		$this->assertSame($alice, $activity->getActor());
		$this->assertSame(self::ALICE_ID, $activity->getActorId());
		$this->assertSame($note->getInstancePaths(), $activity->getInstancePaths());
		$this->assertTrue($activity->isRoot());
	}

	public function testCreateActivitySavesNestedObjectsInnermostFirst(): void {
		$note = $this->note();
		$announce = new Announce();
		$announce->setId(self::ALICE_ID . '/boost/1');
		$announce->setObject($note);
		$this->expectQueuedWithoutInlineDelivery();

		$order = [];
		$this->noteInterface->expects($this->once())->method('save')->willReturnCallback(function () use (&$order): void {
			$order[] = 'note';
		});
		$this->announceInterface->expects($this->once())->method('save')->willReturnCallback(function () use (&$order): void {
			$order[] = 'announce';
		});

		$this->service->createActivity($this->alice(), $announce);

		$this->assertSame(['note', 'announce'], $order);
	}

	public function testCreateActivityToleratesAnAlreadyStoredObject(): void {
		$this->noteInterface->method('save')->willThrowException(new ItemAlreadyExistsException());
		$this->expectQueuedWithoutInlineDelivery();

		$this->assertSame(self::TOKEN, $this->service->createActivity($this->alice(), $this->note()));
	}

	public function testCreateActivityToleratesObjectsWithoutStorage(): void {
		$stream = new Stream();
		$stream->setId(self::NOTE_ID);
		$stream->setType('Unknown');
		$this->expectQueuedWithoutInlineDelivery();

		$this->assertSame(self::TOKEN, $this->service->createActivity($this->alice(), $stream, $activity));
		$this->assertSame($stream, $activity->getObject());
	}

	// updateActivity()

	public function testUpdateActivityWrapsItemInSignedUpdateWithoutSavingIt(): void {
		$alice = $this->alice();
		$note = $this->note();
		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Update::class));
		$this->noteInterface->expects($this->never())->method('save');

		$queued = null;
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with($this->identicalTo($note->getInstancePaths()), $this->callback(function (ACore $item) use (&$queued): bool {
				$queued = $item;

				return true;
			}), self::ALICE_ID)
			->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);

		$this->assertSame(self::TOKEN, $this->service->updateActivity($alice, $note));

		$this->assertInstanceOf(Update::class, $queued);
		$this->assertSame(self::NOTE_ID . '/activity#update', $queued->getId());
		$this->assertSame($note, $queued->getObject());
		$this->assertSame($queued, $note->getParent());
		$this->assertSame($alice, $queued->getActor());
	}

	// deleteActivity()

	public function testDeleteActivitySendsTombstoneOnBehalfOfItemAuthor(): void {
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$this->actorsRequest->method('getFromId')->with(self::ALICE_ID)->willReturn($this->alice());
		$this->noteInterface->expects($this->never())->method('save');

		$queued = null;
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with($this->identicalTo($note->getInstancePaths()), $this->callback(function (ACore $item) use (&$queued): bool {
				$queued = $item;

				return true;
			}), self::ALICE_ID)
			->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);

		$this->assertSame(self::TOKEN, $this->service->deleteActivity($note));

		$this->assertInstanceOf(Delete::class, $queued);
		$this->assertSame(self::NOTE_ID . '#delete', $queued->getId());
		$this->assertSame(self::ALICE_ID, $queued->getActorId());
		$this->assertFalse($queued->hasActor());
		$this->assertInstanceOf(Tombstone::class, $queued->getObject());
		$this->assertSame(self::NOTE_ID, $queued->getObject()->getId());
		$this->assertSame(self::NOTE_ID, $queued->getObjectId());
		$this->assertSame($queued, $queued->getObject()->getParent());
	}

	/**
	 * A recipient may only pass an activity on (AP §7.1.2) if it carries the
	 * author's own signature. Unsigned, a Delete of a reply cannot travel the
	 * way the reply itself did, so the post stays visible on every instance
	 * that only ever received it forwarded.
	 */
	public function testADeleteIsSignedByItsAuthor(): void {
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$alice = $this->alice();
		$this->actorsRequest->expects($this->once())
			->method('getFromId')->with(self::ALICE_ID)->willReturn($alice);
		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Delete::class));
		$this->expectQueuedWithoutInlineDelivery();

		$this->service->deleteActivity($note);
	}

	/** An author we cannot load is a Delete that still has to go out. */
	public function testADeleteWithoutASignableAuthorIsStillSent(): void {
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$this->actorsRequest->method('getFromId')
			->willThrowException(new ActorDoesNotExistException());
		$this->signatureService->expects($this->never())->method('signObject');
		$this->expectQueuedWithoutInlineDelivery();

		$this->assertSame(self::TOKEN, $this->service->deleteActivity($note));
	}

	// request(): recipient resolution

	public function testRequestKeepsDirectInboxTargets(): void {
		$paths = [];
		$this->capturePaths($paths);
		$like = new Like();
		$like->setActorId(self::ALICE_ID);
		$direct = new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH);
		$like->addInstancePath($direct);

		$this->assertSame(self::TOKEN, $this->service->request($like));
		$this->assertSame([$direct], $paths);
	}

	/**
	 * The fan-out asks the database for the distinct inboxes instead of
	 * hydrating every follower into a Follow with a Person and its details just
	 * to read one string off each: the number of inboxes involved is the number
	 * of instances, not of followers. Deduplication and the shared-inbox
	 * fallback happen there — see FollowsRequest::getFollowerInboxes() and its
	 * integration test.
	 */
	public function testRequestExpandsFollowersToTheInboxesTheDatabaseNames(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->followsRequest->expects($this->once())
			->method('getFollowerInboxes')
			->with(self::ALICE_ID)
			->willReturn(['https://remote.example/inbox', 'https://other.example/inbox']);
		$this->followsRequest->expects($this->never())->method('getFollowersByActorId');

		$note = new Note();
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW));

		$this->service->request($note);

		$this->assertCount(2, $paths);
		$this->assertSame('https://remote.example/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_GLOBAL, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
		$this->assertSame('https://other.example/inbox', $paths[1]->getUri());
		$this->assertSame(InstancePath::TYPE_GLOBAL, $paths[1]->getType());
	}

	public function testAFollowersFanOutWithNoInboxesSendsNothing(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->followsRequest->method('getFollowerInboxes')->willReturn([]);

		$note = new Note();
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(
			new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW)
		);

		$this->service->request($note);

		$this->assertSame([], $paths);
	}

	public function testRequestNeverPostsToThisInstance(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->followsRequest->method('getFollowerInboxes')->willReturn([
			// a follower on this very instance
			self::CLOUD_URL . '/inbox',
			'https://remote.example/inbox',
		]);

		$note = new Note();
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW));

		$this->service->request($note);

		// carol already has it: recipients are written into stream_dest when the
		// item is saved, and the round trip would hand us back our own writing
		$this->assertSame(['https://remote.example/inbox'], array_map(
			fn (InstancePath $path): string => $path->getUri(), $paths
		));
	}

	public function testADirectTargetOnThisInstanceIsDroppedToo(): void {
		$paths = [];
		$this->capturePaths($paths);
		$like = new Like();
		$like->setActorId(self::ALICE_ID);
		$like->addInstancePath(
			new InstancePath(self::CLOUD_URL . '/users/carol/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
		);

		// nothing left to send, so no token is needed
		$this->assertSame('<request token not needed>', $this->service->request($like));
		$this->assertSame([], $paths);
	}

	public function testRequestExpandsAllToEveryKnownSharedInbox(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->cacheActorsRequest->expects($this->once())
			->method('getSharedInboxes')
			->willReturn(['https://remote.example/inbox', 'https://other.example/inbox']);

		$item = new Update();
		$item->setActorId(self::ALICE_ID);
		$item->addInstancePath(new InstancePath('https://social.example/', InstancePath::TYPE_ALL, InstancePath::PRIORITY_HIGH));

		$this->service->request($item);

		$this->assertCount(2, $paths);
		$this->assertSame('https://remote.example/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_GLOBAL, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
	}

	public function testRequestCombinesFollowersAndDirectTargets(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->followsRequest->method('getFollowerInboxes')
			->willReturn(['https://remote.example/inbox']);

		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW));

		$this->service->request($note);

		$this->assertCount(2, $paths);
		$this->assertSame(self::BOB_INBOX, $paths[0]->getUri());
		$this->assertSame('https://remote.example/inbox', $paths[1]->getUri());
	}

	public function testRequestAuthorIsTheActorObjectWhenPresent(): void {
		$item = new Like();
		$item->setActorId('https://social.example/@someone-else');
		$item->setActor($this->alice());
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with([], $this->identicalTo($item), self::ALICE_ID)
			->willReturn('');

		$this->service->request($item);
	}

	// request(): queue lifecycle

	public function testRequestWithoutAnyTargetNeedsNoToken(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn('');
		$this->requestQueueService->expects($this->never())->method('getPriorityRequest');
		$this->requestQueueService->expects($this->never())->method('getRequestFromToken');
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$like = new Like();
		$like->setActorId(self::ALICE_ID);

		$this->assertSame('<request token not needed>', $this->service->request($like));
	}

	public function testRequestDeliversPriorityTargetInlineAndHandsTheRestToAsync(): void {
		$direct = $this->queue();
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->expects($this->once())
			->method('getPriorityRequest')
			->with(self::TOKEN)
			->willReturn($direct);
		$this->requestQueueService->expects($this->once())->method('initRequest')->with($this->identicalTo($direct));
		$this->signatureService->expects($this->once())->method('signRequest')
			->with($this->isType('string'), $this->isType('string'), $this->identicalTo($direct))
			->willReturn([]);
		$this->curlService->expects($this->once())->method('retrieveJson')->willReturn([]);
		$this->requestQueueService->expects($this->once())->method('endRequest')->with($this->identicalTo($direct), true);
		$this->requestQueueService->expects($this->once())
			->method('getRequestFromToken')
			->with(self::TOKEN, RequestQueue::STATUS_STANDBY)
			->willReturn([$this->queue('https://other.example/inbox')]);
		$this->curlService->expects($this->once())->method('asyncWithToken')->with(self::TOKEN);

		$like = new Like();
		$like->setActorId(self::ALICE_ID);
		$like->addInstancePath(new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH));

		$this->assertSame(self::TOKEN, $this->service->request($like));
		$this->assertSame(ActivityService::TIMEOUT_LIVE, $direct->getTimeout());
	}

	public function testRequestSkipsAsyncWhenNothingIsLeftInStandby(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willReturn($this->queue());
		$this->curlService->method('retrieveJson')->willReturn([]);
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$like = new Like();
		$like->setActorId(self::ALICE_ID);
		$like->addInstancePath(new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP));

		$this->assertSame(self::TOKEN, $this->service->request($like));
	}

	public function testRequestWithoutPriorityTargetGoesStraightToAsync(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->expects($this->never())->method('initRequest');
		$this->curlService->expects($this->never())->method('retrieveJson');
		$this->requestQueueService->method('getRequestFromToken')->willReturn([$this->queue()]);
		$this->curlService->expects($this->once())->method('asyncWithToken')->with(self::TOKEN);

		$note = $this->note();
		$note->setActorId(self::ALICE_ID);

		$this->assertSame(self::TOKEN, $this->service->request($note));
	}

	public function testRequestReturnsTokenWhenQueueTurnsOutEmpty(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new EmptyQueueException());
		$this->requestQueueService->expects($this->never())->method('getRequestFromToken');
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$note = $this->note();
		$note->setActorId(self::ALICE_ID);

		$this->assertSame(self::TOKEN, $this->service->request($note));
	}

	// manageRequest()

	public function testManageRequestPostsSignedActivityToInboxAndEndsRequestOnSuccess(): void {
		$queue = $this->queue();
		$this->requestQueueService->expects($this->once())->method('initRequest')->with($this->identicalTo($queue));

		$signed = null;
		$this->signatureService->expects($this->once())
			->method('signRequest')
			->with(
				$this->callback(function (string $url) use (&$signed): bool {
					$signed = $url;

					return true;
				}),
				$this->isType('string'),
				$this->identicalTo($queue)
			)
			->willReturn(['Signature' => 'keyId="k"']);
		$sent = null;
		$this->curlService->expects($this->once())
			->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url, array $options) use (&$sent): array {
				$sent = ['method' => $method, 'url' => $url, 'options' => $options];

				return ['ok' => true];
			});
		$this->requestQueueService->expects($this->once())->method('endRequest')->with($this->identicalTo($queue), true);
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);

		// the URL that is signed is the URL that is sent to — anything else
		// verifies here and on no peer anywhere
		$this->assertSame($signed, $sent['url']);
		$this->assertSame('post', $sent['method']);
		$this->assertSame(self::BOB_INBOX, $sent['url']);
		$this->assertSame(10, $sent['options']['timeout']);
		$this->assertSame(['Signature' => 'keyId="k"'], $sent['options']['headers']);
		$this->assertSame(
			json_decode($queue->getActivity(), true),
			json_decode($sent['options']['body'], true)
		);
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function requestTypeProvider(): array {
		return [
			'inbox is posted to' => [InstancePath::TYPE_INBOX, 'post'],
			'shared inbox is posted to' => [InstancePath::TYPE_GLOBAL, 'post'],
			'followers is posted to' => [InstancePath::TYPE_FOLLOWERS, 'post'],
			'public path is fetched' => [InstancePath::TYPE_PUBLIC, 'get'],
		];
	}

	#[DataProvider('requestTypeProvider')]
	public function testManageRequestPicksHttpMethodFromTargetType(int $pathType, string $expectedMethod): void {
		$sent = null;
		$this->curlService->method('retrieveJson')->willReturnCallback(
			function (string $method, string $url, array $options) use (&$sent): array {
				$sent = $method;

				return [];
			}
		);

		$this->service->manageInit();
		$this->service->manageRequest($this->queue(self::BOB_INBOX, $pathType));

		$this->assertSame($expectedMethod, $sent);
	}

	/**
	 * @return array<string, array{\Exception}>
	 */
	public static function deliveredButNoJsonProvider(): array {
		return [
			'non-json answer' => [new RequestResultNotJsonException()],
			'instance not authorized' => [new UnauthorizedFediverseException()],
		];
	}

	#[DataProvider('deliveredButNoJsonProvider')]
	public function testManageRequestTreatsNonJsonAnswersAsDelivered(\Exception $e): void {
		$queue = $this->queue();
		$this->curlService->method('retrieveJson')->willThrowException($e);
		$this->requestQueueService->expects($this->once())->method('endRequest')->with($this->identicalTo($queue), true);
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);
	}

	/**
	 * @return array<string, array{\Exception}>
	 */
	public static function hardErrorProvider(): array {
		return [
			'bad content' => [new RequestContentException()],
			'answer too large' => [new RequestResultSizeException()],
			'actor is gone' => [new ActorDoesNotExistException()],
		];
	}

	#[DataProvider('hardErrorProvider')]
	public function testManageRequestDropsRequestOnHardErrors(\Exception $e): void {
		$queue = $this->queue();
		$this->curlService->method('retrieveJson')->willThrowException($e);
		$this->requestQueueService->expects($this->once())->method('deleteRequest')->with($this->identicalTo($queue));
		$this->requestQueueService->expects($this->never())->method('endRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function transientHttpStatusProvider(): array {
		return [
			'request timeout' => [408],
			'rate limited' => [429],
			'internal server error' => [500],
			'bad gateway' => [502],
			'service unavailable, e.g. the peer is upgrading' => [503],
			'gateway timeout' => [504],
		];
	}

	/**
	 * A peer that is briefly unwell must not cost us the activity: these used
	 * to be indistinguishable from a permanent rejection, so every post queued
	 * for an instance having a bad minute was deleted outright.
	 */
	#[DataProvider('transientHttpStatusProvider')]
	public function testManageRequestRetriesWhenThePeerAnswersWithATransientStatus(int $status): void {
		$queue = $this->queue();
		$this->curlService->method('retrieveJson')
			->willThrowException(new RequestContentException('', $status));

		$this->requestQueueService->expects($this->once())
			->method('endRequest')->with($this->identicalTo($queue), false);
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function permanentHttpStatusProvider(): array {
		return [
			'bad request' => [400],
			'unauthorized' => [401],
			'forbidden' => [403],
			'gone' => [410],
			'unprocessable' => [422],
		];
	}

	#[DataProvider('permanentHttpStatusProvider')]
	public function testManageRequestDropsWhenThePeerRejectsTheActivityForGood(int $status): void {
		$queue = $this->queue();
		$this->curlService->method('retrieveJson')
			->willThrowException(new RequestContentException('', $status));

		$this->requestQueueService->expects($this->once())
			->method('deleteRequest')->with($this->identicalTo($queue));
		$this->requestQueueService->expects($this->never())->method('endRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);
	}

	/**
	 * A host that just answered 503 is skipped for the rest of the run rather
	 * than being asked once per queued activity.
	 */
	public function testManageRequestStopsAskingAHostThatAnsweredATransientStatus(): void {
		$this->curlService->method('retrieveJson')
			->willThrowException(new RequestContentException('', 503));
		$this->requestQueueService->expects($this->once())->method('endRequest');

		$this->service->manageInit();
		$this->service->manageRequest($this->queue());
		// same host, second activity: not attempted again
		$this->service->manageRequest($this->queue());
	}

	/**
	 * @return array<string, array{\Exception}>
	 */
	public static function temporaryErrorProvider(): array {
		return [
			'network error' => [new RequestNetworkException()],
			'server error' => [new RequestServerException()],
		];
	}

	#[DataProvider('temporaryErrorProvider')]
	public function testManageRequestMarksFailureAndSkipsSameInstanceForTheRestOfTheRun(\Exception $e): void {
		$first = $this->queue(self::BOB_INBOX);
		$second = $this->queue('https://remote.example/users/carol/inbox');
		$elsewhere = $this->queue('https://other.example/inbox', InstancePath::TYPE_GLOBAL);

		$this->curlService->expects($this->exactly(2))
			->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url) use ($e): array {
				if (parse_url($url, PHP_URL_HOST) === 'remote.example') {
					throw $e;
				}

				return [];
			});
		$inits = [];
		$this->requestQueueService->expects($this->exactly(2))
			->method('initRequest')
			->willReturnCallback(function (RequestQueue $queue) use (&$inits): void {
				$inits[] = $queue->getInstance()->getUri();
			});
		$ended = [];
		$this->requestQueueService->expects($this->exactly(2))
			->method('endRequest')
			->willReturnCallback(function (RequestQueue $queue, bool $success) use (&$ended): void {
				$ended[] = [$queue->getInstance()->getUri(), $success];
			});
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($first);
		$this->service->manageRequest($second);
		$this->service->manageRequest($elsewhere);

		$this->assertSame([self::BOB_INBOX, 'https://other.example/inbox'], $inits);
		$this->assertSame([[self::BOB_INBOX, false], ['https://other.example/inbox', true]], $ended);
	}

	public function testManageInitForgetsFailedInstances(): void {
		$this->curlService->method('retrieveJson')->willThrowException(new RequestNetworkException());
		$this->requestQueueService->expects($this->exactly(2))->method('initRequest');
		$this->requestQueueService->expects($this->exactly(2))->method('endRequest')->with($this->anything(), false);

		$this->service->manageInit();
		$this->service->manageRequest($this->queue());
		$this->service->manageInit();
		$this->service->manageRequest($this->queue());
	}

	public function testManageRequestGivesUpWhenQueueEntryCannotBeClaimed(): void {
		$this->requestQueueService->method('initRequest')->willThrowException(new QueueStatusException());
		$this->signatureService->expects($this->never())->method('signRequest');
		$this->curlService->expects($this->never())->method('retrieveJson');
		$this->requestQueueService->expects($this->never())->method('endRequest');
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($this->queue());
	}
}
