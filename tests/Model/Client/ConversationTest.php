<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Conversation;
use PHPUnit\Framework\TestCase;

/**
 * Mastodon's Conversation entity.
 *
 * The entity is what a client decodes, so the keys and their types are the
 * contract: a missing `last_status` is null and not an absent key, and `id` is
 * a string — a client that declares `id: String` (Ivory, Mona, anything built
 * on Swift's Codable) cannot decode a number.
 */
class ConversationTest extends TestCase {
	private function conversation(): Conversation {
		$note = new Note();
		$note->setId('https://a/2')->setNid(11);

		$bob = new Person();
		$bob->setId('https://remote.example/users/bob');

		return (new Conversation())
			->setId(10)
			->setRootId('https://a/1')
			->setUnread(true)
			->setAccounts([$bob])
			->setLastStatus($note);
	}

	public function testTheEntityIsMastodonsFourKeys(): void {
		$entity = $this->conversation()->jsonSerialize();

		$this->assertSame(['id', 'unread', 'accounts', 'last_status'], array_keys($entity));
		$this->assertSame('10', $entity['id']);
		$this->assertTrue($entity['unread']);
	}

	public function testTheRootIdIsNotPartOfWhatAClientSees(): void {
		// it is carried so the writer can key a row by it without a second
		// lookup, as ListsRequest carries a list's owner
		$conversation = $this->conversation();

		$this->assertSame('https://a/1', $conversation->getRootId());
		$this->assertArrayNotHasKey('root_id', $conversation->jsonSerialize());
	}

	public function testTheAccountsAndTheStatusAreSerialisedForAClient(): void {
		// and not as ActivityPub, which is what every one of these objects
		// exports by default
		$conversation = $this->conversation();
		$conversation->jsonSerialize();

		$this->assertSame(ACore::FORMAT_LOCAL, $conversation->getLastStatus()->getExportFormat());
		$this->assertSame(ACore::FORMAT_LOCAL, $conversation->getAccounts()[0]->getExportFormat());
	}

	public function testAConversationWithNoStatusIsNullAndNotAMissingKey(): void {
		$conversation = (new Conversation())->setId(10);

		$entity = $conversation->jsonSerialize();

		$this->assertArrayHasKey('last_status', $entity);
		$this->assertNull($entity['last_status']);
		$this->assertSame([], $entity['accounts']);
		$this->assertSame(0, $conversation->getLastStatusNid());
	}

	public function testThePagingCursorIsTheNewestMessageAndNotTheConversation(): void {
		// conversations are ordered by their newest message; the conversation's
		// own id does not move when a message arrives, so it cannot page
		$this->assertSame(11, $this->conversation()->getLastStatusNid());
	}
}
