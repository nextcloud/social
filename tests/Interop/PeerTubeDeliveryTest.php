<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Post;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamService;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A video published here, ingested by a real PeerTube.
 *
 * This is the one that had never been run and the one that mattered most.
 * PeerTube refuses a video it cannot make sense of **silently and on its own
 * side**: "Cannot find associated video channel" goes into *their* log, the
 * delivery from here answers 204, and nothing on this side is any the wiser.
 * Reading their validator told us what they want; only this tells us whether
 * we send it.
 *
 * Nothing is stubbed: the post is written through `PostService`, a channel is
 * made the way one is made for anybody, the delivery is the real queue signed
 * by the real signer, and the assertion is what PeerTube's own REST API
 * answers.
 *
 * Skipped, with a reason, when there is no PeerTube to talk to: see
 * `tests/Interop/README.md`.
 */
class PeerTubeDeliveryTest extends TestCase {
	private PeerTube $peertube;
	private Person $actor;
	private string $channelHandle = '';

	/** @var string[] the posts this test wrote */
	private array $written = [];

	protected function setUp(): void {
		$peertube = PeerTube::fromEnvironment();
		if ($peertube === null) {
			$this->markTestSkipped(
				'no PeerTube to talk to: set PEERTUBE_BASE_URL, PEERTUBE_USER and PEERTUBE_PASSWORD'
			);
		}

		$this->peertube = $peertube;

		// the shape has to be turned on: it is off by default because
		// Pixelfed's inbox drops anything that is not a `Note`
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_PUBLISH_VIDEO, '1');

		$this->actor = $this->localActor();
		$channel = Server::get(ChannelService::class)->defaultFor($this->actor);
		$this->channelHandle = $channel->getHandle() . '@' . $this->cloudHost();

		$this->peertube->resolveAccount($this->channelHandle);
		$this->peertube->follow($this->channelHandle);
		$this->drainQueue();
	}

	protected function tearDown(): void {
		$streamRequest = Server::get(StreamRequest::class);
		foreach ($this->written as $id) {
			try {
				$streamRequest->deleteById($id);
			} catch (\Throwable $e) {
				// the test that deleted it got there first
			}
		}
	}

	/**
	 * The one that proves the whole thing. Before channels existed this failed
	 * on PeerTube's side with "Cannot find associated video channel", and
	 * nothing here could tell.
	 */
	public function testAVideoPostArrivesAsAVideo(): void {
		$words = 'interop ' . bin2hex(random_bytes(4));
		$post = $this->publishVideo($words);

		$video = $this->peertube->awaitVideo($this->channelHandle, $post->getId());

		$this->assertNotNull($video, 'PeerTube never took the video in');
		$this->assertStringContainsString($words, (string)($video['name'] ?? ''));
	}

	/**
	 * The channel is not decoration: it is the field PeerTube resolves the
	 * video's owner from, and it has to come back naming the channel we
	 * published under.
	 */
	public function testTheVideoIsFiledUnderOurChannel(): void {
		$post = $this->publishVideo('interop ' . bin2hex(random_bytes(4)));

		$video = $this->peertube->awaitVideo($this->channelHandle, $post->getId());

		$this->assertNotNull($video, 'PeerTube never took the video in');
		$this->assertSame(
			explode('@', $this->channelHandle)[0],
			(string)($video['channel']['name'] ?? '')
		);
	}

	/**
	 * `isRemoteVideoUrlValid()` drops a file link with no `size` or `height`,
	 * and a video with no files left is one nobody can play. What comes back
	 * says whether the link survived.
	 */
	public function testTheVideoArrivesWithSomethingToPlay(): void {
		$post = $this->publishVideo('interop ' . bin2hex(random_bytes(4)));

		$video = $this->peertube->awaitVideo($this->channelHandle, $post->getId());

		$this->assertNotNull($video, 'PeerTube never took the video in');
		$this->assertGreaterThan(0, (int)($video['duration'] ?? 0), 'the duration was lost');
		$this->assertNotSame([], $video['files'] ?? [], 'the file link was dropped');
	}

	public function testADeleteTakesTheVideoAwayThere(): void {
		$post = $this->publishVideo('interop ' . bin2hex(random_bytes(4)));
		$this->assertNotNull(
			$this->peertube->awaitVideo($this->channelHandle, $post->getId()),
			'PeerTube never took the video in'
		);

		Server::get(StreamService::class)->deleteLocalItem($post);
		$this->drainQueue();

		$this->assertTrue(
			$this->peertube->awaitVideoGone($this->channelHandle, $post->getId()),
			'PeerTube is still showing a deleted video'
		);
	}

	// --- the harness ------------------------------------------------------

	/**
	 * Writes a post that is one video and delivers it.
	 *
	 * The attachment is a `MediaAttachment` built here rather than an upload,
	 * because what is being tested is the wire form and not the uploader — but
	 * every field PeerTube's validator reads is a real one: the duration, the
	 * poster and the byte size all have to be there or `asVideo()` refuses to
	 * make a `Video` at all.
	 */
	private function publishVideo(string $words): Stream {
		$post = new Post($this->actor);
		$post->setContent($words);
		$post->setType(Stream::TYPE_PUBLIC);
		$post->setMedias([$this->videoAttachment()]);

		$token = '';
		$note = Server::get(PostService::class)->createPost($post, $token);
		$this->assertInstanceOf(Stream::class, $note, 'the post was not written');

		$this->written[] = $note->getId();
		$this->drainQueue();

		return $note;
	}

	private function videoAttachment(): MediaAttachment {
		$meta = new \OCA\Social\Model\Client\AttachmentMeta();
		$meta->setDuration(11.0);
		$meta->setOriginal(new \OCA\Social\Model\Client\AttachmentMetaDim([640, 360]));
		$meta->setSmall(new \OCA\Social\Model\Client\AttachmentMetaDim([320, 180]));

		$media = new MediaAttachment();
		$media->setId('1')
			->setType('video')
			->setMediaType('video/mp4')
			->setUrl($this->cloudUrl() . '/apps/social/media/interop.mp4')
			->setPreviewUrl($this->cloudUrl() . '/apps/social/media/interop.jpeg')
			->setDescription('an interop test video')
			->setSizeBytes(1_048_576);

		return $media->setMeta($meta);
	}

	/** Sends whatever is waiting, here and now; cron is not a thing to wait for. */
	private function drainQueue(): void {
		$queueService = Server::get(RequestQueueService::class);
		$activityService = Server::get(ActivityService::class);
		$activityService->manageInit();

		$total = 0;
		foreach ($queueService->getRequestStandby($total) as $request) {
			$activityService->manageRequest($request);
		}
	}

	private function localActor(): Person {
		$users = Server::get(IUserManager::class)->search('', 1);
		if ($users === []) {
			$this->markTestSkipped('no account on this instance to post as');
		}

		$userId = (string)array_key_first($users);
		$actorsRequest = Server::get(ActorsRequest::class);

		try {
			return $actorsRequest->getFromUserId($userId);
		} catch (ActorDoesNotExistException $e) {
			$actor = new Person();
			$actor->setPreferredUsername($userId);
			$actor->setUserId($userId);
			Server::get(SignatureService::class)->generateKeys($actor);
			$actorsRequest->create($actor);

			return $actorsRequest->getFromUserId($userId);
		}
	}

	private function cloudHost(): string {
		return Server::get(ConfigService::class)->getCloudHost();
	}

	private function cloudUrl(): string {
		return rtrim(Server::get(ConfigService::class)->getCloudUrl(), '/');
	}
}
