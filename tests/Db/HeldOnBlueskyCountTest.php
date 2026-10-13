<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Model\Details;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `AtprotoRepoRequest::countHeldOnBluesky()`, run against an in-memory SQLite
 * database: which of the likes, boosts, replies and quotes held here of a
 * local post the AppView counts too.
 */
#[AllowMockObjectsWithoutExpectations]
#[RequiresPhpExtension('pdo_sqlite')]
class HeldOnBlueskyCountTest extends TestCase {
	private const POST = 'https://cloud.example/users/alice/statuses/1';
	private const OTHER = 'https://cloud.example/users/alice/statuses/2';
	private const BLUESKY = 'https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private PDO $pdo;
	private int $n = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE social_action (id TEXT, id_prim TEXT, type TEXT, actor_id TEXT, object_id_prim TEXT)');
		$this->pdo->exec('CREATE TABLE social_stream (id TEXT, id_prim TEXT, in_reply_to_prim TEXT, quote_prim TEXT)');
		$this->pdo->exec('CREATE TABLE social_atproto_record (collection TEXT, local_id_prim TEXT)');
	}

	private function request(): AtprotoRepoRequest {
		$connection = $this->createStub(IDBConnection::class);
		$connection->method('escapeLikeParameter')->willReturnCallback(static fn (string $s): string => addcslashes($s, '\\_%'));
		$connection->method('executeQuery')->willReturnCallback(function (string $sql, array $params): IResult {
			$statement = $this->pdo->prepare(str_replace('*PREFIX*', '', $sql));
			$statement->execute($params);
			$row = $statement->fetch(PDO::FETCH_ASSOC);
			$result = $this->createStub(IResult::class);
			$result->method('fetch')->willReturn($row);

			return $result;
		});

		return new AtprotoRepoRequest(
			$connection, new NullLogger(), $this->createStub(IURLGenerator::class),
			$this->createStub(ConfigService::class), $this->createStub(MiscService::class),
		);
	}

	private function action(string $type, string $actor, string $object = self::POST, string $record = ''): void {
		$id = $actor . '#' . strtolower($type) . '-' . ++$this->n;
		$this->pdo->prepare('INSERT INTO social_action VALUES (?, ?, ?, ?, ?)')
			->execute([$id, md5($id), $type, $actor, md5($object)]);
		if ($record !== '') {
			$this->record($record, $id);
		}
	}

	private function post(string $id, string $replyTo = '', string $quotes = '', bool $record = false): void {
		$this->pdo->prepare('INSERT INTO social_stream VALUES (?, ?, ?, ?)')
			->execute([$id, md5($id), $replyTo === '' ? '' : md5($replyTo), $quotes === '' ? '' : md5($quotes)]);
		if ($record) {
			$this->record(RecordMapper::POST, $id);
		}
	}

	private function record(string $collection, string $localId): void {
		$this->pdo->prepare('INSERT INTO social_atproto_record VALUES (?, ?)')->execute([$collection, md5($localId)]);
	}

	public function testWhatCameFromBlueskyAndWhatLocalAccountsWroteThereIsCounted(): void {
		// from Bluesky, by notification
		$this->action('Like', self::BLUESKY);
		$this->action('Like', self::BLUESKY . 'x');
		$this->action('Announce', self::BLUESKY);
		// local accounts that wrote their like or boost to Bluesky too
		$this->action('Like', 'https://cloud.example/users/bob', self::POST, RecordMapper::LIKE);
		$this->action('Announce', 'https://cloud.example/users/bob', self::POST, RecordMapper::REPOST);
		// neither: the Fediverse, a local account not on Bluesky, a record of
		// another kind, another post
		$this->action('Like', 'https://mastodon.example/users/carol');
		$this->action('Like', 'https://cloud.example/users/dave');
		$this->action('Like', 'https://cloud.example/users/erin', self::POST, RecordMapper::REPOST);
		$this->action('Like', self::BLUESKY, self::OTHER);
		$this->action('Dislike', self::BLUESKY);

		$this->post(self::BLUESKY . '/post/3kreply', self::POST);
		$this->post('https://cloud.example/users/bob/statuses/9', self::POST, '', true);
		$this->post('https://mastodon.example/notes/1', self::POST);
		$this->post('https://cloud.example/users/dave/statuses/3', self::POST);
		$this->post(self::BLUESKY . '/post/3kquote', '', self::POST);
		$this->post('https://mastodon.example/notes/2', '', self::POST);

		$this->assertSame(
			[Details::LIKES => 3, Details::BOOSTS => 2, Details::REPLIES => 2, Details::QUOTES => 1],
			$this->request()->countHeldOnBluesky(self::POST)
		);
	}

	public function testAPostNothingHappenedToHoldsNothing(): void {
		$this->assertSame(
			[Details::LIKES => 0, Details::BOOSTS => 0, Details::REPLIES => 0, Details::QUOTES => 0],
			$this->request()->countHeldOnBluesky(self::POST)
		);
	}

	public function testAnIdThatIsNoUrlIsNotLookedUp(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->expects($this->never())->method('executeQuery');
		$request = new AtprotoRepoRequest(
			$connection, new NullLogger(), $this->createStub(IURLGenerator::class),
			$this->createStub(ConfigService::class), $this->createStub(MiscService::class),
		);

		$this->assertSame(0, $request->countHeldOnBluesky('')[Details::LIKES]);
	}
}
