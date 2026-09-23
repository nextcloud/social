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
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * An edit can mention someone who was not in the original post. Their inbox
 * path must survive the same database roundtrip as the edited post, or the
 * Update built from the reloaded row silently goes to the old recipients.
 */
class EditedRecipientsTest extends TestCase {
	private const BASE = 'https://cloud.example.org/edited-recipients';
	private const AUTHOR = self::BASE . '/users/author';
	private const NOTE_ID = self::BASE . '/notes/edited-mention';
	private const FOLLOWERS = self::BASE . '/users/author/followers';
	private const ORIGINAL_INBOX = 'https://old.example/inbox';
	private const NEW_INBOX = 'https://new.example/inbox';

	private StreamRequest $streamRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cleanup();
		$author = new Person();
		$author->setId(self::AUTHOR)
			->setPreferredUsername('author')
			->setAccount('author@cloud.example.org')
			->setFollowers(self::FOLLOWERS)
			->setFollowing(self::AUTHOR . '/following')
			->setInbox(self::AUTHOR . '/inbox')
			->setOutbox(self::AUTHOR . '/outbox');
		Server::get(CacheActorsRequest::class)->save($author);
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->streamRequest->deleteById(self::NOTE_ID, Note::TYPE);
		Server::get(CacheActorsRequest::class)->deleteCacheById(self::AUTHOR);
	}

	public function testAnEditedPostKeepsItsOriginalAndNewInboxPaths(): void {
		$note = new Note();
		$note->setId(self::NOTE_ID)
			->setAttributedTo(self::AUTHOR)
			->setTo(ACore::CONTEXT_PUBLIC)
			->setCcArray([self::FOLLOWERS])
			->setContent('<p>before</p>')
			->setPublished(gmdate('Y-m-d\\TH:i:s\\Z'))
			->setPublishedTime(time())
			->setVisibility(Stream::TYPE_PUBLIC)
			->setLocal(true);
		$note->addInstancePath(new InstancePath(
			self::FOLLOWERS, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
		));
		$note->addInstancePath(new InstancePath(
			self::ORIGINAL_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM
		));
		$this->streamRequest->save($note);

		$edited = $this->streamRequest->getStreamById(self::NOTE_ID);
		$edited->addCc('https://new.example/users/bob');
		$edited->addTag([
			'type' => 'Mention',
			'href' => 'https://new.example/users/bob',
			'name' => '@bob@new.example',
		]);
		$edited->addInstancePath(new InstancePath(
			self::NEW_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM
		));

		$this->streamRequest->update($edited, true);

		$reloaded = $this->streamRequest->getStreamById(self::NOTE_ID);
		$paths = array_map(
			static fn (InstancePath $path): string => $path->getUri(),
			$reloaded->getInstancePaths()
		);
		$this->assertContains(self::ORIGINAL_INBOX, $paths);
		$this->assertContains(self::NEW_INBOX, $paths);
	}
}
