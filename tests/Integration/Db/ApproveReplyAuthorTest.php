<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Activity\ApproveReplyInterface;
use OCA\Social\Model\ActivityPub\Activity\ApproveReply;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\SignatureService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * An `ApproveReply` (FEP-5624) against posts read back from the database: a
 * pending reply of ours is approved by the author of the video it answers —
 * PeerTube approves as the account the video's channel belongs to, which the
 * video's own stored document names — and by nobody else on that server.
 *
 * The video is shaped after PeerTube's documented `Video` object: `attributedTo`
 * lists the channel (`Group`) and its account (`Person`). All rows carry
 * '-approvereply-' ids and are removed in tearDown.
 */
class ApproveReplyAuthorTest extends TestCase {
	private const PEERTUBE = 'https://peertube.example/approvereply';
	private const VIDEO = self::PEERTUBE . '/videos/watch/6f4c1e1a';
	private const CHANNEL = self::PEERTUBE . '/video-channels/bob_channel';
	private const OWNER = self::PEERTUBE . '/accounts/bob';
	private const REPLY = 'https://cloud.example.org/approvereply/@alice/9';
	private const ALICE = 'https://cloud.example.org/approvereply/users/alice';

	private StreamRequest $streamRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::VIDEO, self::REPLY] as $id) {
			$this->streamRequest->deleteById($id);
		}
		foreach ([self::ALICE, self::CHANNEL] as $id) {
			Server::get(CacheActorsRequest::class)->deleteCacheById($id);
		}
	}

	private function cached(string $id, bool $local): void {
		$actor = new Person();
		$actor->setId($id);
		$actor->setPreferredUsername(basename($id));
		$actor->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST));
		$actor->setInbox($id . '/inbox');
		$actor->setFollowers($id . '/followers');
		$actor->setFollowing($id . '/following');
		$actor->setLocal($local);
		Server::get(CacheActorsRequest::class)->save($actor);
	}

	private function seed(): void {
		$this->cached(self::ALICE, true);
		$this->cached(self::CHANNEL, false);

		$video = AP::instance()->getSimpleItemFromData([
			'id' => self::VIDEO,
			'type' => 'Video',
			'name' => 'A moderated video',
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'attributedTo' => [
				['type' => 'Person', 'id' => self::OWNER],
				['type' => 'Group', 'id' => self::CHANNEL],
			],
			'commentsPolicy' => 3,
			'published' => gmdate('Y-m-d\TH:i:s\Z'),
		]);
		$this->assertInstanceOf(Note::class, $video);
		$video->setPublishedTime(time());
		$this->streamRequest->save($video);

		$reply = new Note();
		$reply->setId(self::REPLY);
		$reply->setAttributedTo(self::ALICE);
		$reply->setTo('https://www.w3.org/ns/activitystreams#Public');
		$reply->setInReplyTo(self::VIDEO);
		$reply->setContent('<p>a reply</p>');
		$reply->setLocal(true);
		$reply->setReplyState(Stream::REPLY_PENDING);
		$reply->setPublishedTime(time());
		$reply->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($reply);
	}

	private function approve(string $actor): void {
		$approval = new ApproveReply();
		$approval->setId(self::PEERTUBE . '/approvals/' . md5($actor));
		$approval->setActorId($actor);
		$approval->setObjectId(self::REPLY);
		$approval->setOrigin('peertube.example', SignatureService::ORIGIN_HEADER, time());

		Server::get(ApproveReplyInterface::class)->processIncomingRequest($approval);
	}

	private function state(): string {
		return $this->streamRequest->getStreamById(self::REPLY)->getReplyState();
	}

	public function testTheAccountBehindTheVideosChannelApprovesTheReply(): void {
		$this->seed();
		$this->assertSame(self::CHANNEL, $this->streamRequest->getStreamById(self::VIDEO)->getAttributedTo(), 'the video is stored under its channel');

		$this->approve(self::OWNER);

		$this->assertSame(Stream::REPLY_APPROVED, $this->state());
	}

	public function testAnotherAccountOnTheSameServerDoesNotApproveTheReply(): void {
		$this->seed();

		try {
			$this->approve(self::PEERTUBE . '/accounts/carol');
			$this->fail('an approval from somebody other than the author was accepted');
		} catch (InvalidOriginException) {
		}

		$this->assertSame(Stream::REPLY_PENDING, $this->state());
	}
}
