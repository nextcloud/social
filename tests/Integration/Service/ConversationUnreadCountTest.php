<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConversationService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The direct-message badge against the real database: the count read off the
 * messages' places in their threads (`countUnread()`) is the number of
 * conversations the list itself (`getPage()`) marks unread, over threads that
 * exercise every rule the two share — read and dismissed markers, a reply the
 * reader wrote last, a root outside what is stored.
 */
class ConversationUnreadCountTest extends TestCase {
	private const BASE = 'https://cloud.example.org/dmbadge';
	private const READER = self::BASE . '/users/reader';
	private const BOB = 'https://remote.example/dmbadge/users/bob';
	private const CAROL = 'https://remote.example/dmbadge/users/carol';
	private const NOTES = self::BASE . '/notes/';

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private ConversationsRequest $conversations;
	/** @var list<string> */
	private array $notes = [];
	/** when the last message was published: a nid orders by its second first */
	private int $second = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->conversations = Server::get(ConversationsRequest::class);
		$this->cleanup();
		$this->second = time() - 100;

		$this->person(self::READER, true);
		$this->person(self::BOB, false);
		$this->person(self::CAROL, false);
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([...$this->notes, ...array_map(fn (string $n): string => self::NOTES . $n, ['a1', 'a2', 'a3', 'b1', 'b2', 'c1', 'd1', 'd2', 'e1', 'f2', 'g1', 'g2'])] as $id) {
			$this->streamRequest->deleteById($id, Note::TYPE);
		}
		foreach ([self::READER, self::BOB, self::CAROL] as $id) {
			$this->conversations->deleteRelatedId($id);
			$this->cacheActorsRequest->deleteCacheById($id);
		}
		$this->notes = [];
	}

	private function person(string $id, bool $local): void {
		$username = basename($id);
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . '@' . parse_url($id, PHP_URL_HOST))
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);
	}

	/** A direct message, and its nid as stored. */
	private function message(string $name, string $author, string $to, string $inReplyTo = ''): string {
		$id = self::NOTES . $name;
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo($author);
		$note->setTo($to);
		$note->setContent('<p>' . $name . '</p>');
		if ($inReplyTo !== '') {
			$note->setInReplyTo(self::NOTES . $inReplyTo);
		}
		$this->second++;
		$note->setPublishedTime($this->second);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $this->second));
		$this->streamRequest->save($note);
		$this->notes[] = $id;

		return (string)$this->streamRequest->getStreamById($id)->getNid();
	}

	private function viewer(): Person {
		return $this->cacheActorsRequest->getFromId(self::READER);
	}

	public function testTheBadgeCountsWhatTheListMarksUnread(): void {
		// unread: three messages, one conversation
		$this->message('a1', self::BOB, self::READER);
		$this->message('a2', self::BOB, self::READER, 'a1');
		$this->message('a3', self::BOB, self::READER, 'a2');
		// the reader wrote last
		$this->message('b1', self::BOB, self::READER);
		$this->message('b2', self::READER, self::BOB, 'b1');
		// read up to its newest
		$c1 = $this->message('c1', self::CAROL, self::READER);
		$this->conversations->markRead(self::READER, self::NOTES . 'c1', $c1);
		// dismissed, and back with a newer message
		$d1 = $this->message('d1', self::BOB, self::READER);
		$this->conversations->markHidden(self::READER, self::NOTES . 'd1', $d1);
		$this->message('d2', self::BOB, self::READER, 'd1');
		// dismissed
		$e1 = $this->message('e1', self::CAROL, self::READER);
		$this->conversations->markHidden(self::READER, self::NOTES . 'e1', $e1);
		// a reply to a message this server never stored
		$this->message('f2', self::CAROL, self::READER, 'f1');
		// read, then a newer message
		$g1 = $this->message('g1', self::BOB, self::READER);
		$this->conversations->markRead(self::READER, self::NOTES . 'g1', $g1);
		$this->message('g2', self::CAROL, self::READER, 'g1');

		$service = Server::get(ConversationService::class);
		$unread = [];
		foreach ($service->getPage($this->viewer(), ConversationService::MAX_LIMIT)['conversations'] as $conversation) {
			if ($conversation->isUnread()) {
				$unread[] = basename($conversation->getRootId());
			}
		}
		sort($unread);

		$this->assertSame(['a1', 'd1', 'f2', 'g1'], $unread);
		$this->assertSame(count($unread), $service->countUnread($this->viewer()));
		$this->assertSame(2, $service->countUnread($this->viewer(), 2), 'capped');
	}

	public function testNoMessagesIsNoBadge(): void {
		$this->assertSame(0, Server::get(ConversationService::class)->countUnread($this->viewer()));
	}
}
