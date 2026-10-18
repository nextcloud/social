<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\VideoViewsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\VideoViewService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ISession;
use OCP\Session\Exceptions\SessionNotAvailableException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * How many times a video posted here was watched: once per viewer, video and
 * day, and nothing kept about the viewer but a hash that cannot be read back.
 */
#[AllowMockObjectsWithoutExpectations]
class VideoViewServiceTest extends TestCase {
	private const VIDEO = 'https://cloud.example/@alice/1';
	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://cloud.example/users/bob';
	/** 2024-05-01 12:00 UTC: day 19844 */
	private const NOW = 1714564800;

	private VideoViewsRequest&MockObject $request;
	private ISession&MockObject $session;
	private int $now = self::NOW;
	/** @var list<array{string, string, int}> what `seen()` was asked */
	private array $seen = [];
	private VideoViewService $service;

	protected function setUp(): void {
		$this->request = $this->createMock(VideoViewsRequest::class);
		$this->request->method('seen')->willReturnCallback(function (string $post, string $hash, int $day): bool {
			$first = !in_array([$post, $hash, $day], $this->seen, true);
			$this->seen[] = [$post, $hash, $day];

			return $first;
		});
		$this->session = $this->createMock(ISession::class);
		$this->session->method('getId')->willReturn('session-one');

		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->willReturnMap([['secret', '', 'instance-secret']]);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->service = new VideoViewService($this->request, $config, $this->session, $time, new NullLogger());
	}

	private function video(bool $local = true, string $type = 'video'): Note {
		$note = new Note();
		$note->setId(self::VIDEO);
		$note->setAttributedTo(self::ALICE);
		$note->setLocal($local);
		$note->setAttachments([(new MediaAttachment())->import([
			'id' => '1', 'type' => $type, 'media_type' => $type . '/mp4', 'url' => 'https://cloud.example/media/1',
		])]);

		return $note;
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	public function testAViewIsCountedOncePerViewerAndDay(): void {
		$this->request->expects($this->exactly(2))->method('increment')->with(self::VIDEO);

		$this->assertTrue($this->service->count($this->video(), $this->person(self::BOB)));
		$this->assertFalse($this->service->count($this->video(), $this->person(self::BOB)), 'the same day: not a second view');

		$this->now += 86400;
		$this->assertTrue($this->service->count($this->video(), $this->person(self::BOB)), 'the next day it counts again');
	}

	public function testSomebodyNotSignedInIsCountedByTheirSession(): void {
		$this->request->expects($this->once())->method('increment');

		$this->assertTrue($this->service->count($this->video(), null));
		$this->assertFalse($this->service->count($this->video(), null));
	}

	/** without a session there is nothing to tell a second view from a first */
	public function testNobodyWithoutASessionIsCounted(): void {
		$session = $this->createStub(ISession::class);
		$session->method('getId')->willThrowException(new SessionNotAvailableException());
		$config = $this->createStub(IConfig::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->request->expects($this->never())->method('seen');

		$service = new VideoViewService($this->request, $config, $session, $time, new NullLogger());

		$this->assertFalse($service->count($this->video(), null));
	}

	public function testTheAuthorWatchingIsNotCounted(): void {
		$this->request->expects($this->never())->method('seen');

		$this->assertFalse($this->service->count($this->video(), $this->person(self::ALICE)));
	}

	/** another server counts its own videos, and a post that is no video has no views */
	public function testOnlyVideosPostedHereAreCounted(): void {
		$this->request->expects($this->never())->method('seen');

		$this->assertFalse($this->service->count($this->video(false), $this->person(self::BOB)));
		$this->assertFalse($this->service->count($this->video(true, 'image'), $this->person(self::BOB)));
	}

	/**
	 * The viewer is kept only as a keyed hash that names the day and the
	 * video too: it says nothing about who it was, and two rows of one
	 * person cannot be matched to each other.
	 */
	public function testWhatIsKeptCannotBeTracedToTheViewer(): void {
		$this->service->count($this->video(), $this->person(self::BOB));
		$this->now += 86400;
		$this->service->count($this->video(), $this->person(self::BOB));
		$other = $this->video();
		$other->setId(self::VIDEO . '0');
		$this->service->count($other, $this->person(self::BOB));

		$hashes = array_column($this->seen, 1);
		$this->assertCount(3, array_unique($hashes), 'another day or another video is another hash');
		foreach ($hashes as $hash) {
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
			$this->assertNotSame(hash('sha256', $this->seen[0][2] . "\n" . self::VIDEO . "\naccount:" . self::BOB), $hash, 'keyed, not a plain digest anybody can recompute');
		}
		$this->assertSame([19844, 19845, 19845], array_column($this->seen, 2));
	}

	public function testAFailureIsNotAnErrorForSomebodyWatching(): void {
		$this->request->method('increment')->willThrowException(new \RuntimeException('database is gone'));

		$this->assertFalse($this->service->count($this->video(), $this->person(self::BOB)));
	}

	public function testThePageIsGivenItsCountsInOneQuery(): void {
		$watched = $this->video();
		$unwatched = $this->video();
		$unwatched->setId(self::VIDEO . '-new');
		$remote = $this->video(false);
		$remote->setId('https://tube.example/videos/1');
		$boost = new Announce();
		$boost->setId('https://cloud.example/@bob/boost');
		$boostedVideo = $this->video();
		$boostedVideo->setId(self::VIDEO . '-boosted');
		$boost->setObject($boostedVideo);

		$this->request->expects($this->once())->method('countsFor')
			->with([self::VIDEO, self::VIDEO . '-new', self::VIDEO . '-boosted'])
			->willReturn([md5(self::VIDEO) => 12, md5(self::VIDEO . '-boosted') => 3]);

		$this->service->attach([$watched, $unwatched, $remote, $boost]);

		$this->assertSame(12, $watched->getLocalVideoViews());
		$this->assertSame(0, $unwatched->getLocalVideoViews(), 'nobody yet is a count of nothing');
		$this->assertNull($remote->getLocalVideoViews(), 'not ours to count');
		$this->assertSame(3, $boostedVideo->getLocalVideoViews());
	}

	public function testAPageWithoutLocalVideosAsksNothing(): void {
		$this->request->expects($this->never())->method('countsFor');

		$this->service->attach([$this->video(false), $this->video(true, 'image')]);
	}

	public function testOnlyTheDaysBeforeTodayAreForgotten(): void {
		$this->request->expects($this->once())->method('forgetBefore')->with(19844)->willReturn(7);

		$this->assertSame(7, $this->service->forgetEarlierDays());
	}
}
