<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\FilterController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\Server;

/**
 * `GET /api/v1/videos/{nid}/related` through the controller and against the
 * real database: what a video's page offers next, and that it leaves out
 * what the reader's own timelines leave out.
 *
 * Bob's channel holds the video being watched, two more videos, a post of
 * text and a video for his followers only. Carol has a video sharing the
 * watched one's hashtag, one sharing its category, one in another category
 * and a post of text with the hashtag.
 */
class RelatedVideosTest extends ApiTestCase {
	private const BASE = 'https://cloud.example.org/related-api/notes/';
	private const TAG = 'relatedapitag';
	private const CATEGORY = 'Sailing-related-api';

	/** @var string[] ids of the posts this test stored */
	private array $notes = [];

	/** @var array<string, string> name => nid */
	private array $nids = [];

	protected function tearDown(): void {
		foreach ($this->notes as $id) {
			Server::get(StreamRequest::class)->deleteById($id, Note::TYPE);
		}
		$this->notes = [];
		$this->nids = [];
		parent::tearDown();
	}

	/**
	 * @param array{video?: bool, tag?: bool, category?: string, followersOnly?: bool} $shape
	 */
	private function note(string $as, string $name, int $published, array $shape): void {
		$author = $this->account($as);
		$note = new Note();
		$note->setId(self::BASE . $name . '-' . bin2hex(random_bytes(3)));
		$note->setAttributedTo($author->getId());
		if ($shape['followersOnly'] ?? false) {
			$note->setTo($author->getFollowers());
		} else {
			$note->setTo(ACore::CONTEXT_PUBLIC);
			$note->setCcArray([$author->getFollowers()]);
		}
		$tag = ($shape['tag'] ?? false) ? ' #' . self::TAG : '';
		$note->setContent('<p>' . $name . $tag . '</p>');
		$note->setHashtags($tag === '' ? [] : [self::TAG]);
		$note->setLocal(true);
		if ($shape['video'] ?? true) {
			$media = new MediaAttachment();
			$media->setId((string)crc32($name));
			$media->setType('video');
			$media->setMediaType('video/mp4');
			$media->setUrl('https://cloud.example.org/related-api/media/' . $name);
			$note->setAttachments([$media]);
		}
		if (($shape['category'] ?? '') !== '') {
			$note->setVideoMeta(['title' => $name, 'category' => $shape['category']]);
		}
		$note->setPublishedTime($published);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $published));

		$streams = Server::get(StreamRequest::class);
		$streams->save($note);
		$this->notes[] = $note->getId();
		$this->nids[$name] = (string)$streams->getStreamById($note->getId(), false)->getNid();
	}

	private function seed(): void {
		$at = time() - 1000;
		$this->note('bob', 'watched', ++$at, ['tag' => true, 'category' => self::CATEGORY]);
		$this->note('bob', 'bob-older', ++$at, []);
		$this->note('bob', 'bob-text', ++$at, ['video' => false]);
		$this->note('bob', 'bob-followers', ++$at, ['followersOnly' => true]);
		$this->note('bob', 'bob-newer', ++$at, []);
		$this->note('carol', 'carol-tagged', ++$at, ['tag' => true]);
		$this->note('carol', 'carol-same-category', ++$at, ['category' => self::CATEGORY]);
		$this->note('carol', 'carol-other-category', ++$at, ['category' => 'Cooking-related-api']);
		$this->note('carol', 'carol-text', ++$at, ['video' => false, 'tag' => true]);
	}

	/** @return string[] the names of this test's posts on the list, in its order */
	private function related(?string $as = 'alice', string $of = 'watched', array $query = []): array {
		$response = $this->call(TimelineApiController::class, 'videosRelated', ['nid' => $this->nids[$of]], $query, $as);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertEntities('Status', $response->body);

		$names = array_flip($this->nids);

		return array_values(array_filter(array_map(
			static fn (\stdClass $status): string => $names[(string)$status->id] ?? '',
			$response->body
		)));
	}

	public function testTheChannelComesFirstThenTheHashtagThenTheCategory(): void {
		$this->seed();

		$this->assertSame(
			['bob-newer', 'bob-older', 'carol-tagged', 'carol-same-category'], $this->related(),
			'never the video itself, a post without a video, a followers-only video of somebody not followed, or another category'
		);
		$this->assertSame(['bob-newer', 'bob-older', 'carol-tagged', 'carol-same-category'], $this->related(null), 'the same for a reader who is not signed in');
		$this->assertSame(['bob-newer'], $this->related('alice', 'watched', ['limit' => 1]));
	}

	public function testAFollowerIsOfferedTheChannelsFollowersOnlyVideos(): void {
		$this->seed();
		$this->follow('alice', 'bob');

		$this->assertSame(['bob-newer', 'bob-followers', 'bob-older', 'carol-tagged', 'carol-same-category'], $this->related());
	}

	public function testAMutedOrBlockedAccountIsNotOffered(): void {
		$this->seed();
		$carol = (string)$this->account('carol')->getNid();

		$muted = $this->call(AccountApiController::class, 'accountMute', ['id' => $carol], [], 'alice', 'POST');
		$this->assertSame(200, $muted->status);
		$this->assertSame(['bob-newer', 'bob-older'], $this->related(), 'muted');
		$this->assertSame(['bob-newer', 'bob-older', 'carol-tagged', 'carol-same-category'], $this->related('carol'), 'only for the reader who muted');

		$this->call(AccountApiController::class, 'accountUnmute', ['id' => $carol], [], 'alice', 'POST');
		$blocked = $this->call(AccountApiController::class, 'accountBlock', ['id' => $carol], [], 'alice', 'POST');
		$this->assertSame(200, $blocked->status);
		$this->assertSame(['bob-newer', 'bob-older'], $this->related(), 'blocked');
	}

	public function testAHideFilterOfTheReaderLeavesAVideoOut(): void {
		$this->seed();

		$created = $this->call(FilterController::class, 'create', [], [
			'title' => 'no tagged',
			'context' => ['public', 'account'],
			'filter_action' => 'hide',
			'keywords_attributes' => [['keyword' => 'carol-tagged', 'whole_word' => 'false']],
		], 'alice', 'POST');
		$this->assertSame(200, $created->status, json_encode($created->body) ?: '');

		$this->assertSame(['bob-newer', 'bob-older', 'carol-same-category'], $this->related());
	}

	public function testAVideoTheReaderMayNotSeeIsNotFound(): void {
		$this->seed();

		$response = $this->call(TimelineApiController::class, 'videosRelated', ['nid' => $this->nids['bob-followers']], [], 'alice');
		$this->assertSame(404, $response->status);

		$this->follow('alice', 'bob');
		$this->assertSame(['bob-newer', 'bob-older', 'watched'], $this->related('alice', 'bob-followers'), 'once it can be seen, it has its channel to offer');
	}
}
