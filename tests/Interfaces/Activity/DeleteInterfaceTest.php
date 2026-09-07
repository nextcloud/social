<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;

require_once __DIR__ . '/../ActivityPubTestCase.php';

class DeleteInterfaceTest extends ActivityPubTestCase {
	private const BOB = self::REMOTE_URL . '/users/bob';
	private const NOTE = self::REMOTE_URL . '/notes/1';

	private DeleteInterface $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new DeleteInterface();
	}

	/** A Delete from bob's server, either naming its target by id or embedding it. */
	private function delete(string $objectId, ?ACore $object = null, ?string $origin = null): ACore {
		$delete = $this->incoming(Delete::TYPE, self::BOB . '#delete/1', self::BOB, $object, $origin);
		if ($object === null) {
			$delete->setObjectId($objectId);
		}

		return $delete;
	}

	public function testDeleteNamingAKnownNoteRemovesTheNote(): void {
		$note = $this->note(self::NOTE, self::BOB);
		$this->noteInterface->method('getItemById')->with(self::NOTE)->willReturn($note);

		$this->noteInterface->expects($this->once())->method('delete')->with($this->identicalTo($note));
		$this->personInterface->expects($this->never())->method('getItemById');
		$this->personInterface->expects($this->never())->method('delete');

		$this->handler->processIncomingRequest($this->delete(self::NOTE));
	}

	public function testDeleteNamingAKnownActorRemovesTheActor(): void {
		$bob = $this->person(self::BOB);
		$this->noteInterface->method('getItemById')->willThrowException(new ItemNotFoundException());
		$this->personInterface->method('getItemById')->with(self::BOB)->willReturn($bob);

		$this->personInterface->expects($this->once())->method('delete')->with($this->identicalTo($bob));
		$this->noteInterface->expects($this->never())->method('delete');

		$this->handler->processIncomingRequest($this->delete(self::BOB));
	}

	public function testDeleteNamingSomethingUnknownDoesNothing(): void {
		$this->noteInterface->method('getItemById')->willThrowException(new ItemNotFoundException());
		$this->personInterface->method('getItemById')->willThrowException(new ItemNotFoundException());

		$this->noteInterface->expects($this->never())->method('delete');
		$this->personInterface->expects($this->never())->method('delete');

		$this->handler->processIncomingRequest($this->delete(self::REMOTE_URL . '/whatever/1'));
	}

	public function testDeleteEmbeddingANoteIsHandedToTheNoteInterface(): void {
		$note = $this->note(self::NOTE, self::BOB);
		$delete = $this->delete(self::NOTE, $note);

		$this->noteInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($delete), $this->identicalTo($note));
		$this->noteInterface->expects($this->never())->method('getItemById');

		$this->handler->processIncomingRequest($delete);
	}

	public function testDeleteEmbeddingAnActorIsHandedToThePersonInterface(): void {
		$bob = $this->person(self::BOB);
		$delete = $this->delete(self::BOB, $bob);

		$this->personInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($delete), $this->identicalTo($bob));

		$this->handler->processIncomingRequest($delete);
	}

	public function testDeleteOfSomethingHostedElsewhereIsRefused(): void {
		$this->noteInterface->expects($this->never())->method('getItemById');
		$this->personInterface->expects($this->never())->method('getItemById');

		$this->expectException(InvalidOriginException::class);

		// bob's server may only delete what lives on bob's server
		$this->handler->processIncomingRequest($this->delete('https://other.example/notes/1'));
	}

	public function testDeleteNotComingFromItsOwnOriginIsRefused(): void {
		$this->noteInterface->expects($this->never())->method('getItemById');

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->delete(self::NOTE, null, 'evil.example'));
	}
}
