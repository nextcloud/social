<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Where a post was sent, read back from the row.
 *
 * This is what a `Delete` is addressed with: `StreamService::deleteLocalItem()`
 * reads the post, adds the followers path and the boosters and repliers, and
 * hands the whole set to `ActivityService::deleteActivity()`. Everything else
 * on that list — the inbox of every account the post mentioned, above all —
 * comes from the `instances` column, written when the post was created.
 *
 * So the retraction reaches a mentioned instance only for as long as that
 * column survives a round trip, and nothing but a real database can say
 * whether it does.
 */
class StreamInstancePathsTest extends TestCase {
	private const BASE = 'https://cloud.example.org/pathsitest';
	private const AUTHOR = self::BASE . '/users/alice';
	private const MENTIONED_INBOX = 'https://remote.example/users/bob/inbox';
	private const SHARED_INBOX = 'https://remote.example/inbox';

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->streamRequest->deleteById(self::BASE . '/notes/mentioning', Note::TYPE);
		$this->cacheActorsRequest->deleteCacheById(self::AUTHOR);
	}

	/** The reads join the author's cached row, so there has to be one. */
	private function author(): void {
		$person = new Person();
		$person->setId(self::AUTHOR)->setPreferredUsername('alice');
		$person->setAccount('alice@cloud.example.org')
			->setFollowers(self::AUTHOR . '/followers')
			->setFollowing(self::AUTHOR . '/following')
			->setInbox(self::AUTHOR . '/inbox')
			->setOutbox(self::AUTHOR . '/outbox')
			->setLocal(true);
		$this->cacheActorsRequest->save($person);
	}

	/** A public post addressed at one mentioned account's inbox. */
	private function stored(): Note {
		$this->author();
		$note = new Note();
		$note->setId(self::BASE . '/notes/mentioning');
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent('<p>hello @bob</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$note->setLocal(true);
		$note->addInstancePath(
			new InstancePath(self::MENTIONED_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM)
		);
		$note->addInstancePath(
			new InstancePath(self::SHARED_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM)
		);
		$this->streamRequest->save($note);

		return $note;
	}

	/**
	 * The one that matters: an instance reached only because the post
	 * mentioned somebody there is still on the list when the post is read back
	 * to be deleted.
	 */
	public function testWhereAPostWasSentIsReadBackWithIt(): void {
		$this->stored();

		$read = $this->streamRequest->getStreamById(self::BASE . '/notes/mentioning');
		$uris = array_map(
			static fn (InstancePath $path): string => $path->getUri(),
			$read->getInstancePaths()
		);

		$this->assertContains(self::MENTIONED_INBOX, $uris);
		$this->assertContains(self::SHARED_INBOX, $uris);
	}

	/** And with what each one was, since the type decides how it is posted to. */
	public function testEachPathKeepsItsTypeAndPriority(): void {
		$this->stored();

		$read = $this->streamRequest->getStreamById(self::BASE . '/notes/mentioning');
		$paths = array_values(array_filter(
			$read->getInstancePaths(),
			static fn (InstancePath $path): bool => $path->getUri() === self::MENTIONED_INBOX
		));

		$this->assertCount(1, $paths);
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_MEDIUM, $paths[0]->getPriority());
	}
}
