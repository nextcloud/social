<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Service;

use OCA\Social\Cron\ThreadRoots;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ConversationService;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The root a direct message stores with it (`social_stream.root_prim`),
 * against the database: written when the message is stored, moved when a
 * parent arrives after its replies, given to the messages stored before the
 * column by `Cron\ThreadRoots`, and read by the conversations in place of a
 * walk up the chain.
 */
class ThreadRootsTest extends TestCase {
	private const BASE = 'https://cloud.example.org/roots';
	private const READER = self::BASE . '/users/reader';
	private const BOB = 'https://remote.example/roots/users/bob';
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
		$this->second = time() - 1000;

		$this->person(self::READER, true);
		$this->person(self::BOB, false);
	}

	protected function tearDown(): void {
		$this->cleanup();
		Server::get(ConfigService::class)->deleteAppValue(ThreadRoots::CURSOR);
		parent::tearDown();
	}

	private function cleanup(): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('id')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->like('id', $qb->createNamedParameter(self::NOTES . '%')));
		$cursor = $qb->executeQuery();
		$ids = array_map(static fn (array $row): string => (string)$row['id'], $cursor->fetchAll());
		$cursor->closeCursor();
		foreach ($ids as $id) {
			$this->streamRequest->deleteById($id, Note::TYPE);
		}
		foreach ([self::READER, self::BOB] as $id) {
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

	/** A direct message between the reader and bob, or a public post with `$to` public. */
	private function message(string $name, string $inReplyTo = '', string $to = self::READER): void {
		$id = self::NOTES . $name;
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo(self::BOB);
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
	}

	/** A chat of `$count` messages, each answering the one before. */
	private function chat(int $count, int $from = 1): void {
		for ($n = $from; $n < $from + $count; $n++) {
			$this->message('c' . $n, ($n === 1) ? '' : 'c' . ($n - 1));
		}
	}

	/** @return array<string, string> post name => the name of its stored root, '' for none */
	private function roots(): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('s.id', 'r.id AS root')
			->from(CoreRequestBuilder::TABLE_STREAM, 's')
			->leftJoin('s', CoreRequestBuilder::TABLE_STREAM, 'r', $qb->expr()->eq('r.id_prim', 's.root_prim'))
			->where($qb->expr()->like('s.id', $qb->createNamedParameter(self::NOTES . '%')));
		$cursor = $qb->executeQuery();
		$roots = [];
		while ($row = $cursor->fetch()) {
			$roots[basename((string)$row['id'])] = basename((string)($row['root'] ?? ''));
		}
		$cursor->closeCursor();
		ksort($roots);

		return $roots;
	}

	/** Forgets every stored root, as the rows stored before the column were. */
	private function forgetRoots(): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_STREAM)
			->set('root_prim', $qb->createNamedParameter(null))
			->where($qb->expr()->like('id', $qb->createNamedParameter(self::NOTES . '%')));
		$qb->executeStatement();
	}

	private function viewer(): Person {
		return $this->cacheActorsRequest->getFromId(self::READER);
	}

	public function testADirectMessageStoresItsThreadsRoot(): void {
		$this->chat(3);
		$this->message('p1', '', ACore::CONTEXT_PUBLIC);

		$this->assertSame(['c1' => 'c1', 'c2' => 'c1', 'c3' => 'c1', 'p1' => ''], $this->roots());
	}

	/** Replies that arrived before their parent move under its root when it comes. */
	public function testAParentArrivingAfterItsRepliesTakesThemUnder(): void {
		$this->message('c3', 'c2');
		$this->message('c4', 'c3');
		$this->message('c2', 'c1');
		$this->assertSame(['c2' => 'c2', 'c3' => 'c2', 'c4' => 'c2'], $this->roots());

		$this->message('c1');

		$this->assertSame(['c1' => 'c1', 'c2' => 'c1', 'c3' => 'c1', 'c4' => 'c1'], $this->roots());
	}

	/**
	 * A chat is a chain, and one longer than the walk above the page used to
	 * be grouped under wherever the walk stopped — a conversation whose id
	 * changed with every message. With the root stored it stays the chat's
	 * first message, and the badge reads it in one statement.
	 */
	public function testALongChatKeepsItsConversationAsItGrows(): void {
		$this->chat(80);
		$service = Server::get(ConversationService::class);
		$viewer = $this->viewer();

		$first = $service->getPage($viewer, ConversationService::MAX_LIMIT)['conversations'];
		$this->message('c81', 'c80');
		$run = QueryCounter::record(fn (): array => $service->getPage($viewer, ConversationService::MAX_LIMIT)['conversations']);
		$count = QueryCounter::record(fn (): int => $service->countUnread($viewer));

		$this->assertCount(1, $first);
		$this->assertSame('c1', basename($first[0]->getRootId()));
		$this->assertSame('c1', basename($run->result()[0]->getRootId()));
		$this->assertSame((string)$first[0]->getId(), (string)$run->result()[0]->getId());
		$this->assertSame(1, $count->result());
		// the window, the roots, the markers
		$this->assertLessThanOrEqual(4, $count->count(), $count->summary());
	}

	/**
	 * Marking a long chat read reads the thread from its stored root, so the
	 * marker reaches its newest message however far below the root that is.
	 */
	public function testALongChatIsMarkedReadToItsNewestMessage(): void {
		$this->chat(30);
		$service = Server::get(ConversationService::class);
		$viewer = $this->viewer();
		$conversation = $service->getPage($viewer, ConversationService::MAX_LIMIT)['conversations'][0];

		$service->markRead($viewer, $conversation->getId());

		$this->assertSame(0, $service->countUnread($viewer));
	}

	/** The messages stored before the column get their roots from the job, once. */
	public function testTheBackfillGivesTheOlderMessagesTheirRoots(): void {
		$this->chat(30);
		$this->message('p1', '', ACore::CONTEXT_PUBLIC);
		$this->forgetRoots();
		$this->assertSame('', $this->roots()['c30']);

		$config = Server::get(ConfigService::class);
		$config->setAppValue(ThreadRoots::CURSOR, '0');
		$job = Server::get(ThreadRoots::class);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		$roots = $this->roots();
		$this->assertSame('c1', $roots['c30']);
		$this->assertSame('c1', $roots['c1']);
		$this->assertSame('', $roots['p1'], 'a post that is no direct message has none');
		$this->assertNotSame('0', $config->getAppValue(ThreadRoots::CURSOR));

		// run again, it finds them filled and writes nothing
		$again = QueryCounter::record(fn (): array => $this->conversations->backfillRoots('0', ThreadRoots::CHUNK));
		$this->assertSame([], $again->matching('/^\s*UPDATE/i'));
		$this->assertSame($roots, $this->roots());
	}

	/** Until the job has reached them, a message without a root is walked up as before. */
	public function testAMessageWithoutARootIsWalked(): void {
		$this->chat(12);
		$this->forgetRoots();
		$this->message('c13', 'c12');

		$conversations = Server::get(ConversationService::class)->getPage($this->viewer(), ConversationService::MAX_LIMIT)['conversations'];

		$this->assertCount(1, $conversations);
		$this->assertSame('c1', basename($conversations[0]->getRootId()));
		$this->assertSame('c1', basename($this->conversations->rootOf(self::NOTES . 'c13')));
	}
}
