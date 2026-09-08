<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamActionDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamActionService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

class PollServiceTest extends TestCase {
	private const VIEWER = 'https://cloud.example/@viewer';
	private const AUTHOR = 'https://mastodon.social/users/alice';
	private const POLL_ID = self::AUTHOR . '/statuses/1';

	private StreamRequest|MockObject $streamRequest;
	private CacheActorService|MockObject $cacheActorService;
	private ActivityService|MockObject $activityService;
	private StreamActionService|MockObject $streamActionService;
	private StreamActionsRequest|MockObject $streamActionsRequest;
	private PollService $service;

	protected function setUp(): void {
		$this->bootActivityPub();
		\OC::$server->register(IURLGenerator::class, $this->createMock(IURLGenerator::class));

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->streamActionService = $this->createMock(StreamActionService::class);
		$this->streamActionsRequest = $this->createMock(StreamActionsRequest::class);
		$this->streamActionsRequest->method('getAction')
			->willThrowException(new StreamActionDoesNotExistException());

		$this->service = new PollService(
			$this->streamRequest,
			$this->cacheActorService,
			$this->activityService,
			$this->createMock(SignatureService::class),
			$this->streamActionService,
			$this->streamActionsRequest,
			new NullLogger()
		);
	}

	protected function tearDown(): void {
		AP::$activityPub = null;
		\OC::$server->reset();
	}

	/** A real AP registry over mocked interfaces, so vote notes get built for real. */
	private function bootActivityPub(): void {
		$args = [];
		foreach ((new ReflectionClass(AP::class))->getConstructor()->getParameters() as $parameter) {
			$class = $parameter->getType()->getName();
			$mock = $this->createMock($class);
			if ($class === ConfigService::class) {
				$mock->method('getCloudUrl')->willReturn('https://cloud.example');
			}
			$args[] = $mock;
		}
		AP::$activityPub = new AP(...$args);
	}

	private function viewer(): Person {
		$viewer = new Person();
		$viewer->setId(self::VIEWER);
		$viewer->setLocal(true);

		return $viewer;
	}

	private function poll(array $extra = [], bool $local = false): Question {
		$question = new Question();
		$question->importFromDatabase([
			'id' => self::POLL_ID,
			'nid' => 42,
			'attributed_to' => self::AUTHOR,
			'local' => $local ? 1 : 0,
			'source' => json_encode(array_merge([
				'type' => 'Question',
				'endTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
				'oneOf' => [
					['name' => 'Cats', 'replies' => ['totalItems' => 1]],
					['name' => 'Dogs', 'replies' => ['totalItems' => 2]],
				],
			], $extra)),
		]);
		$this->streamRequest->method('getStreamByNid')->with(42)->willReturn($question);

		return $question;
	}

	private function author(): Person {
		$author = new Person();
		$author->setId(self::AUTHOR);
		$author->setInbox(self::AUTHOR . '/inbox');
		$this->cacheActorService->method('getFromId')->with(self::AUTHOR)->willReturn($author);

		return $author;
	}

	public function testVoteFederatesTheChoiceAndRemembersIt(): void {
		$this->poll();
		$this->author();

		$sent = [];
		$this->activityService->method('request')
			->willReturnCallback(function (ACore $activity) use (&$sent): string {
				$sent[] = $activity;

				return 'token';
			});
		$this->streamActionService->expects($this->once())
			->method('setAction')
			->with(self::VIEWER, self::POLL_ID, StreamAction::POLL_VOTES, json_encode([1]));

		$result = $this->service->vote($this->viewer(), 42, [1]);

		$this->assertCount(1, $sent);
		$this->assertInstanceOf(Create::class, $sent[0]);
		/** @var Note $vote */
		$vote = $sent[0]->getObject();
		$this->assertSame('Dogs', $vote->getName());
		$this->assertSame(self::POLL_ID, $vote->getInReplyTo());
		$this->assertSame(self::AUTHOR, $vote->getTo());
		$this->assertSame(self::AUTHOR . '/inbox', $sent[0]->getInstancePaths()[0]->getUri());

		$poll = $result->exportAsLocal()['poll'];
		$this->assertTrue($poll['voted']);
		$this->assertSame([1], $poll['own_votes']);
	}

	public function testASingleChoicePollRefusesMultipleChoices(): void {
		$this->poll();
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidActionException::class);

		$this->service->vote($this->viewer(), 42, [0, 1]);
	}

	public function testAMultipleChoicePollAcceptsSeveralChoices(): void {
		$this->poll(['anyOf' => [
			['name' => 'Cats', 'replies' => ['totalItems' => 1]],
			['name' => 'Dogs', 'replies' => ['totalItems' => 2]],
		], 'oneOf' => null]);
		$this->author();
		$sent = 0;
		$this->activityService->method('request')->willReturnCallback(function () use (&$sent): string {
			$sent++;

			return 'token';
		});

		$this->service->vote($this->viewer(), 42, [0, 1]);

		$this->assertSame(2, $sent, 'one vote note per choice');
	}

	public function testAnInvalidOptionIndexIsRefused(): void {
		$this->poll();

		$this->expectException(InvalidActionException::class);

		$this->service->vote($this->viewer(), 42, [7]);
	}

	public function testAnExpiredPollRefusesVotes(): void {
		$this->poll(['endTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]);

		$this->expectException(InvalidActionException::class);

		$this->service->vote($this->viewer(), 42, [0]);
	}

	public function testALocalPollRefusesVotes(): void {
		$this->poll([], true);

		$this->expectException(InvalidActionException::class);

		$this->service->vote($this->viewer(), 42, [0]);
	}

	public function testVotingTwiceIsRefused(): void {
		$question = $this->poll();
		$action = new StreamAction(self::VIEWER, self::POLL_ID);
		$action->updateValue(StreamAction::POLL_VOTES, json_encode([0]));
		$question->setAction($action);
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidActionException::class);

		$this->service->vote($this->viewer(), 42, [1]);
	}

	public function testANonPollStatusIsNotFound(): void {
		$note = new Note();
		$this->streamRequest->method('getStreamByNid')->willReturn($note);

		$this->expectException(StreamNotFoundException::class);

		$this->service->getPoll(42);
	}
}
