<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\ListController;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\Server;

/**
 * `only_news` on a list timeline, through the controller and against the real
 * database.
 *
 * The route took the parameter and the list's page query never applied it,
 * so a list read as news came back as the whole list. Three posts that link
 * somewhere, the oldest of all, under forty newer posts of text: a page of
 * twenty finding the three proves the narrowing is in the query that picks
 * the page, and paging two at a time has to keep `only_news` in the `Link`
 * header.
 */
class OnlyNewsListTimelineTest extends ApiTestCase {
	private const BASE = 'https://cloud.example.org/onlynews-list/notes/';

	/** @var string[] ids of the posts this test stored */
	private array $notes = [];

	protected function tearDown(): void {
		foreach ($this->notes as $id) {
			Server::get(StreamRequest::class)->deleteById($id, Note::TYPE);
		}
		$this->notes = [];
		parent::tearDown();
	}

	private function note(string $name, int $published, string $content): string {
		$bob = $this->account('bob');
		$note = new Note();
		$note->setId(self::BASE . $name . '-' . bin2hex(random_bytes(3)));
		$note->setAttributedTo($bob->getId());
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$bob->getFollowers()]);
		$note->setContent($content);
		$note->setLocal(true);
		$note->setPublishedTime($published);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $published));
		Server::get(StreamRequest::class)->save($note);
		$this->notes[] = $note->getId();

		return $note->getId();
	}

	/** @return string[] the news posts' ids, newest first */
	private function seed(): array {
		$published = time() - 1000;
		$news = [];
		for ($i = 0; $i < 3; $i++) {
			array_unshift($news, $this->note('news-' . $i, ++$published, '<p>read <a href="https://paper.example/piece-' . $i . '">this</a></p>'));
		}
		for ($i = 0; $i < 40; $i++) {
			$this->note('text-' . $i, ++$published, '<p>good morning ' . $i . '</p>');
		}

		return $news;
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

	public function testOnlyNewsOnAListFindsTheNewsUnderEverythingNewerAndPagesThroughIt(): void {
		$this->account('alice');
		$this->follow('alice', 'bob');
		$list = $this->call(ListController::class, 'create', [], ['title' => 'news'], 'alice', 'POST');
		$this->call(ListController::class, 'addAccounts', ['id' => (int)$list->body->id], [
			'account_ids' => [(string)$this->account('bob')->getNid()],
		], 'alice', 'POST');
		$page = fn (array $query): ApiResponse => $this->call(ListController::class, 'timeline', ['id' => (int)$list->body->id], $query);
		$news = $this->seed();

		$this->assertNotContains($news[0], $this->uris($page(['limit' => 20])), 'without the filter the news is not on the first page');
		$this->assertSame($news, $this->uris($page(['limit' => 20, 'only_news' => 'true'])), 'a page of twenty is the three news posts, newest first');

		$first = $page(['limit' => 2, 'only_news' => 'true']);
		$this->assertSame(array_slice($news, 0, 2), $this->uris($first), 'the first page of two');
		$links = $first->links();
		$this->assertSame('true', $links['next']['query']['only_news'] ?? null, 'next keeps only_news');

		$second = $page($links['next']['query']);
		$this->assertSame([$news[2]], $this->uris($second), 'max_id continues with the news below');
		$this->assertArrayNotHasKey('next', $second->links(), 'the last news post ends the timeline');
	}
}
