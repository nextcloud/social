<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\InterestsController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InterestService;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `only_short` on the five timelines that take it, through the controllers and
 * against the real database.
 *
 * Seeded the way a real timeline has them: three shorts, the oldest posts of
 * all, under four videos that are not shorts and sixty newer posts of text. A
 * narrowing applied to a page after the page was chosen answers this with
 * nothing, so a page of twenty finding the three is what proves the narrowing
 * is in the query that picks the page. Paged two at a time, the `Link` header
 * has to carry `only_short` on, or the second page is the unfiltered timeline.
 */
class OnlyShortTimelineTest extends ApiTestCase {
	private const BASE = 'https://cloud.example.org/onlyshort-api/notes/';
	private const TAG = 'onlyshortapi';

	/** @var string[] ids of the posts this test stored */
	private array $notes = [];
	private string $fastPathBefore = '';
	private string $interestsBefore = '';

	protected function setUp(): void {
		parent::setUp();
		$this->fastPathBefore = Server::get(ConfigService::class)->getAppValue(ConfigService::SOCIAL_DEST_NID_FILLED);
		$this->interestsBefore = Server::get(ConfigService::class)->getAppValue(ConfigService::SOCIAL_INTERESTS);
		// the home timeline's recipient fast path is on, so the narrowed home
		// timeline has to be the one that leaves it
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_DEST_NID_FILLED, '1');
	}

	protected function tearDown(): void {
		foreach ($this->notes as $id) {
			Server::get(StreamRequest::class)->deleteById($id, Note::TYPE);
		}
		$this->notes = [];
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_DEST_NID_FILLED, $this->fastPathBefore);
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_INTERESTS, $this->interestsBefore);
		parent::tearDown();
	}

	/**
	 * A video as the server stores one it measured, or as a remote one
	 * arrives without meta when `$seconds` is null.
	 */
	private function video(string $id, ?int $seconds, int $width = 1080, int $height = 1920): MediaAttachment {
		$media = new MediaAttachment();
		$media->setId($id);
		$media->setType('video');
		$media->setMediaType('video/mp4');
		$media->setUrl('https://cloud.example.org/onlyshort-api/media/' . $id);
		$media->setPreviewUrl('https://cloud.example.org/onlyshort-api/media/' . $id);
		if ($seconds !== null) {
			$meta = new AttachmentMeta();
			$meta->setDuration((float)$seconds);
			$meta->setOriginal(new AttachmentMetaDim([$width, $height]));
			$media->setMeta($meta);
		}

		return $media;
	}

	/** @param MediaAttachment[] $attachments */
	private function note(string $name, int $published, array $attachments): string {
		$bob = $this->account('bob');
		$note = new Note();
		$note->setId(self::BASE . $name . '-' . bin2hex(random_bytes(3)));
		$note->setAttributedTo($bob->getId());
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$bob->getFollowers()]);
		$note->setContent('<p>' . $name . ' #' . self::TAG . '</p>');
		$note->setHashtags([self::TAG]);
		$note->setLocal(true);
		$note->setAttachments($attachments);
		$note->setPublishedTime($published);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $published));
		Server::get(StreamRequest::class)->save($note);
		$this->notes[] = $note->getId();

		return $note->getId();
	}

	/** @return string[] the shorts' ids, newest first */
	private function seed(): array {
		$published = time() - 1000;
		$shorts = [];
		for ($i = 0; $i < 3; $i++) {
			array_unshift($shorts, $this->note('short-' . $i, ++$published, [$this->video('s' . $i, 20 + $i)]));
		}

		$this->note('long', ++$published, [$this->video('long', 600)]);
		$this->note('landscape', ++$published, [$this->video('wide', 30, 1920, 1080)]);
		$this->note('no-meta', ++$published, [$this->video('bare', null)]);
		$this->note('two-shorts', ++$published, [$this->video('two-a', 10), $this->video('two-b', 10)]);

		for ($i = 0; $i < 60; $i++) {
			$this->note('text-' . $i, ++$published, []);
		}

		return $shorts;
	}

	/** @return array<string, array{string}> */
	public static function routes(): array {
		return [
			'timelines/home' => ['home'],
			'timelines/public' => ['public'],
			'timelines/tag/{tag}' => ['tag'],
			'timelines/list/{id}' => ['list'],
			'accounts/{id}/statuses' => ['account'],
		];
	}

	/**
	 * The route, as a function of its query string, with what it needs set up.
	 *
	 * @return callable(array<string, mixed>): ApiResponse
	 */
	private function route(string $route): callable {
		$this->account('alice');
		$this->follow('alice', 'bob');

		switch ($route) {
			case 'home':
				return fn (array $query): ApiResponse => $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'home'], $query);
			case 'public':
				return fn (array $query): ApiResponse => $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'public'], $query + ['local' => 'true']);
			case 'tag':
				return fn (array $query): ApiResponse => $this->call(TimelineApiController::class, 'tag', ['hashtag' => self::TAG], $query);
			case 'list':
				$list = $this->call(ListController::class, 'create', [], ['title' => 'shorts'], 'alice', 'POST');
				$this->call(ListController::class, 'addAccounts', ['id' => (int)$list->body->id], [
					'account_ids' => [(string)$this->account('bob')->getNid()],
				], 'alice', 'POST');

				return fn (array $query): ApiResponse => $this->call(ListController::class, 'timeline', ['id' => (int)$list->body->id], $query);
			default:
				$bob = (string)$this->account('bob')->getNid();

				return fn (array $query): ApiResponse => $this->call(AccountApiController::class, 'accountStatuses', ['account' => $bob], $query);
		}
	}

	/** @return string[] the ids of this test's posts on the page, in its order */
	private function uris(ApiResponse $response): array {
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertIsArray($response->body);

		return array_values(array_filter(
			array_map(static fn (\stdClass $status): string => (string)$status->uri, $response->body),
			static fn (string $uri): bool => str_starts_with($uri, self::BASE)
		));
	}

	#[DataProvider('routes')]
	public function testOnlyShortFindsTheShortsUnderEverythingNewerAndPagesThroughThem(string $route): void {
		$page = $this->route($route);
		$shorts = $this->seed();

		$this->assertNotContains(
			$shorts[0], $this->uris($page(['limit' => 20])),
			$route . ': without the filter the shorts are not on the first page'
		);

		$this->assertSame(
			$shorts, $this->uris($page(['limit' => 20, 'only_short' => 'true'])),
			$route . ': a page of twenty is the three shorts, newest first'
		);

		$first = $page(['limit' => 2, 'only_short' => 'true']);
		$this->assertSame(array_slice($shorts, 0, 2), $this->uris($first), $route . ': the first page of two');
		$links = $first->links();
		$this->assertArrayHasKey('next', $links, $route . ': a full page links the next one');
		$this->assertSame('true', $links['next']['query']['only_short'] ?? null, $route . ': next keeps only_short');
		$this->assertSame('true', $links['prev']['query']['only_short'] ?? null, $route . ': prev keeps only_short');
		$this->assertArrayHasKey('max_id', $links['next']['query'], $route . ': next pages with max_id');

		$second = $page($links['next']['query']);
		$this->assertSame([$shorts[2]], $this->uris($second), $route . ': max_id continues with the shorts below');
		$this->assertArrayNotHasKey('next', $second->links(), $route . ': the last short ends the timeline');
	}

	/**
	 * For you, narrowed the same way: a ranking of the posts carrying the
	 * reader's hashtag, of which only the three shorts are left, paged by
	 * the `Link` header with `only_short` kept on.
	 */
	public function testForYouWithOnlyShortRanksOnlyTheShorts(): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_INTERESTS, '1');
		$shorts = $this->seed();
		// Interests are learned from real actions now; seed the profile through
		// the same favourite path used by the application instead of calling the
		// removed manual add() API.
		$alice = $this->account('alice');
		Server::get(InterestService::class)->saveSettings($alice, ['learning' => true]);
		foreach (array_slice($shorts, 0, 2) as $shortId) {
			$short = Server::get(StreamRequest::class)->getStreamById($shortId, true, ACore::FORMAT_LOCAL);
			Server::get(InterestService::class)->recordAction($alice, $short, InterestService::ACTION_FAVOURITE);
		}
		$page = fn (array $query): ApiResponse => $this->call(InterestsController::class, 'timeline', [], $query);

		$this->assertSame(
			$shorts, $this->uris($page(['limit' => 20, 'only_short' => 'true'])),
			'a page of twenty is the three shorts'
		);

		$first = $page(['limit' => 2, 'only_short' => 'true']);
		$this->assertSame(array_slice($shorts, 0, 2), $this->uris($first), 'the first page of two');
		$next = $first->links()['next']['query'] ?? [];
		$this->assertSame('true', $next['only_short'] ?? null, 'next keeps only_short');

		$this->assertSame([$shorts[2]], $this->uris($page($next)), 'and goes on in the same ranking');
	}
}
