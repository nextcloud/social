<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Command;

use OCA\Social\Command\NoteCreate;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\SignatureService;
use OCP\IUserManager;
use OCP\Server;

/**
 * `--type` used to be the option that quietly did the opposite of its own help.
 *
 * The help says "public (default)", but an omitted or misspelled value reached
 * Post::setType() as a string it did not recognise, and an unrecognised
 * visibility becomes a direct post — addressed, in that case, to nobody at all.
 * The command exited 0 and printed the object, so nothing in the output
 * suggested the note had gone nowhere.
 */
class NoteCreateTest extends CommandTestCase {
	private string $userId = '';

	/** Set when this test made the actor, so only then does it remove it. */
	private string $createdActor = '';

	/** @var string[] ids of the notes this test created */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();

		// whichever account this instance happens to have: the command resolves
		// its author through confirmUserId(), so a fabricated one will not do
		$users = Server::get(IUserManager::class)->search('', 1);
		if ($users === []) {
			$this->markTestSkipped('no account on this instance to post as');
		}

		$this->userId = (string)array_key_first($users);

		// built directly rather than through AccountService, which refreshes the
		// actor cache and so reaches out to this instance's own public address —
		// something a test runner generally cannot do
		$actorsRequest = Server::get(ActorsRequest::class);
		try {
			$actorsRequest->getFromUserId($this->userId);
		} catch (ActorDoesNotExistException $e) {
			$actor = new Person();
			$actor->setPreferredUsername($this->userId);
			$actor->setUserId($this->userId);
			Server::get(SignatureService::class)->generateKeys($actor);
			$actorsRequest->create($actor);
			$this->createdActor = $this->userId;
		}
	}

	protected function tearDown(): void {
		$streamRequest = Server::get(StreamRequest::class);
		foreach ($this->created as $id) {
			$streamRequest->deleteById($id);
		}

		if ($this->createdActor !== '') {
			Server::get(ActorsRequest::class)->delete($this->createdActor);
		}

		parent::tearDown();
	}

	public function testAMisspelledTypeIsRefusedRatherThanPostedToNobody(): void {
		$tester = $this->tester(NoteCreate::class);

		$code = $this->runNonInteractive($tester, [
			'user_id' => $this->userId,
			'content' => 'this note should never be created',
			'--type' => 'folowers',
		]);

		$display = $tester->getDisplay();
		$this->assertSame(1, $code);
		$this->assertStringContainsString('unknown type', $display);
		$this->assertStringContainsString('followers', $display, 'the message names what it would have accepted');
		$this->assertStringNotContainsString('token:', $display, 'nothing was posted');
	}

	public function testAnOmittedTypeIsThePublicItsHelpPromises(): void {
		$tester = $this->tester(NoteCreate::class);

		$code = $this->runNonInteractive($tester, [
			'user_id' => $this->userId,
			'content' => 'note without an explicit type ' . time(),
		]);

		$display = $tester->getDisplay();
		$this->assertSame(0, $code);
		$this->rememberCreated($display);
		$this->assertStringContainsString(
			'https://www.w3.org/ns/activitystreams#Public',
			$display,
			'the note is addressed to the public collection, not held as a direct post'
		);
	}

	/** The command prints the object it created; keep its id so tearDown can drop it. */
	private function rememberCreated(string $display): void {
		if (preg_match('/"id"\s*:\s*"([^"]+)"/', $display, $matches) === 1) {
			$this->created[] = $matches[1];
		}
	}
}
