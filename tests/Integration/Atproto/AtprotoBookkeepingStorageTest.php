<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Model\Event;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Db\AtprotoBlocklistRequest;
use OCA\Social\Db\AtprotoEventRequest;
use OCA\Social\Db\AtprotoLabelerRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The Bluesky tables that keep track rather than hold content, against
 * the real database: account moves and their progress, the PLC operation
 * log a repair resends from, the firehose's event rows and their replay
 * window, the instance blocklist and the labelers a person subscribes to.
 * Each round-trips its JSON and its order, and the unique indexes that
 * make adding twice a no-op hold.
 */
class AtprotoBookkeepingStorageTest extends TestCase {
	private const USER = 'atproto-bookkeeping-integration';
	private const OTHER = 'atproto-bookkeeping-integration-2';

	private string $did;

	protected function setUp(): void {
		$this->did = 'did:plc:' . substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz234567', 2)), 0, 24);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testAMoveIsKeptWithItsProgressAndFoundByDirection(): void {
		$moves = Server::get(AtprotoMoveRequest::class);
		$away = new Move(0, $this->did, self::USER, Move::AWAY, 'https://pds.example.org', 'did:web:pds.example.org', 'alice.example.org', Move::STEP_REPO, Move::RUNNING);
		$awayId = $moves->add($away);
		$inbound = new Move(0, $this->did, self::USER, Move::INBOUND, 'https://old.example.net', '', 'alice.old.example.net', Move::STEP_INVITED, Move::WAITING, 'sealed');
		$inboundId = $moves->add($inbound);
		$this->assertGreaterThan($awayId, $inboundId);

		$this->assertSame($inboundId, $moves->latestOfUser(self::USER)?->id, 'the latest first');
		$this->assertSame($awayId, $moves->latestOfDid($this->did, Move::AWAY)?->id);
		$this->assertSame($inboundId, $moves->latestOfDid($this->did, Move::INBOUND)?->id);
		$this->assertNull($moves->latestOfDid($this->did, Move::IN));
		$this->assertNull($moves->latestOfUser(self::OTHER));

		$stored = $moves->get($awayId);
		$this->assertNotNull($stored);
		$stored->step = Move::next($stored->step);
		$stored->progress = ['records' => 12, 'blobs' => ['done' => 3, 'of' => 4], 'note' => 'é ✨'];
		$stored->error = 'the PDS answered 502';
		$moves->update($stored);

		$again = $moves->get($awayId);
		$this->assertSame(Move::STEP_BLOBS, $again?->step);
		$this->assertSame(['records' => 12, 'blobs' => ['done' => 3, 'of' => 4], 'note' => 'é ✨'], $again->progress);
		$this->assertSame('the PDS answered 502', $again->error);
		$this->assertSame('sealed', $moves->get($inboundId)?->session);

		$moves->deleteByUser(self::USER);
		$this->assertNull($moves->get($awayId));
	}

	public function testThePlcLogIsReadInOrderAndConfirmationsAreKept(): void {
		$log = Server::get(AtprotoPlcLogRequest::class);
		$this->assertSame('', $log->latestCid($this->did));
		$genesis = $log->record($this->did, 'bafy-genesis', ['type' => 'plc_operation', 'prev' => null, 'sig' => 'x']);
		$rotation = $log->record($this->did, 'bafy-rotation', ['type' => 'plc_operation', 'prev' => 'bafy-genesis', 'sig' => 'y']);

		$this->assertSame('bafy-rotation', $log->latestCid($this->did), 'the prev of the next operation');
		$rows = $log->getByDid($this->did);
		$this->assertSame(['bafy-genesis', 'bafy-rotation'], array_column($rows, 'cid'));
		$this->assertSame(['type' => 'plc_operation', 'prev' => null, 'sig' => 'x'], $rows[0]['operation']);

		$log->markSent($genesis);
		$log->markConfirmed($genesis);
		$unconfirmed = array_values(array_filter($log->getUnconfirmed(1000), fn (array $row): bool => $row['did'] === $this->did));
		$this->assertSame([$rotation], array_column($unconfirmed, 'id'), 'what a repair sends again');
		$this->assertGreaterThan(0, $log->getByDid($this->did)[0]['confirmed']);

		$log->remove($rotation);
		$this->assertSame('bafy-genesis', $log->latestCid($this->did));
	}

	/**
	 * A dead operation stays in the log with the directory's reason, is
	 * never pending again and is nobody's `prev`; a pending one keeps its
	 * place when it is signed again on another head.
	 */
	public function testADeadOperationIsKeptButFollowedByNothing(): void {
		$log = Server::get(AtprotoPlcLogRequest::class);
		$genesis = $log->record($this->did, 'bafy-genesis', ['type' => 'plc_operation', 'prev' => null, 'sig' => 'x']);
		$log->markConfirmed($genesis);
		$refused = $log->record($this->did, 'bafy-refused', ['type' => 'plc_operation', 'prev' => 'bafy-genesis', 'sig' => 'y']);
		$next = $log->record($this->did, 'bafy-next', ['type' => 'plc_operation', 'prev' => 'bafy-refused', 'sig' => 'z']);
		$this->assertSame('bafy-next', $log->latestCid($this->did));
		$this->assertSame('bafy-genesis', $log->confirmedCid($this->did));

		$log->markDead($refused, 'PLC directory refused the operation (400): ' . str_repeat('é', 300));
		$this->assertSame([$next], array_column($log->getPending($this->did), 'id'), 'the dead one is not sent again');
		$this->assertNotContains($refused, array_column($log->getUnconfirmed(1000), 'id'));
		$this->assertSame('bafy-next', $log->latestCid($this->did));
		$rows = $log->getByDid($this->did);
		$this->assertSame(['bafy-genesis', 'bafy-refused', 'bafy-next'], array_column($rows, 'cid'), 'kept for the record');
		$this->assertGreaterThan(0, $rows[1]['dead']);
		$this->assertSame(255, mb_strlen($rows[1]['reason']));
		$this->assertStringStartsWith('PLC directory refused the operation (400): ', $rows[1]['reason']);
		$this->assertSame([0, 0, 0], [$rows[0]['dead'], $rows[2]['dead'], strlen($rows[0]['reason'])]);

		$log->rewrite($next, 'bafy-next-again', ['type' => 'plc_operation', 'prev' => 'bafy-genesis', 'sig' => 'w']);
		$pending = $log->getPending($this->did);
		$this->assertSame([[$next, 'bafy-next-again', 'bafy-genesis']], array_map(static fn (array $row): array => [$row['id'], $row['cid'], $row['operation']['prev']], $pending));
		$log->markConfirmed($next);
		$this->assertSame('bafy-next-again', $log->confirmedCid($this->did));
		$this->assertSame([], $log->getPending($this->did));
	}

	public function testFirehoseRowsAreNumberedInOrderAndPrunedPastTheWindow(): void {
		$events = Server::get(AtprotoEventRequest::class);
		$body = "\xa1\x63did\x78\x20" . str_pad($this->did, 32, 'x');
		$first = $events->append($this->did, Event::KIND_IDENTITY, $body, 1000);
		$second = $events->append($this->did, Event::KIND_ACCOUNT, "\x00\xff binary", 2000);

		$this->assertSame($first + 1, $second);
		$this->assertGreaterThanOrEqual($second, $events->latestSeq());
		$after = $events->after($first - 1, 2);
		$this->assertSame([$first, $second], array_map(static fn (Event $event): int => $event->seq, $after));
		$this->assertSame($body, $after[0]->body, 'the frame body is bytes, kept whole');
		$this->assertSame("\x00\xff binary", $after[1]->body);
		$this->assertCount(1, $events->after($first - 1, 1), 'the limit holds');

		$events->pruneBefore(1500);
		$this->assertSame([$second], array_map(static fn (Event $event): int => $event->seq, array_filter($events->after($first - 1), fn (Event $event): bool => $event->did === $this->did)));
		$events->pruneBefore(2500);
		$this->assertSame([], array_filter($events->after($first - 1), fn (Event $event): bool => $event->did === $this->did));
	}

	public function testABlocklistEntryIsAddedOnce(): void {
		$blocklist = Server::get(AtprotoBlocklistRequest::class);
		$host = 'spam-' . substr($this->did, 8) . '.example';

		$this->assertTrue($blocklist->add(AtprotoBlocklistRequest::KIND_HOST, $host, 'spam'));
		$this->assertFalse($blocklist->add(AtprotoBlocklistRequest::KIND_HOST, $host, 'again'));
		$this->assertTrue($blocklist->add(AtprotoBlocklistRequest::KIND_DID, $this->did, ''), 'the same table, another kind');

		$mine = array_values(array_filter($blocklist->getAll(), fn (array $row): bool => in_array($row['value'], [$host, $this->did], true)));
		$this->assertSame([[AtprotoBlocklistRequest::KIND_DID, $this->did], [AtprotoBlocklistRequest::KIND_HOST, $host]], array_map(static fn (array $row): array => [$row['kind'], $row['value']], $mine));
		$this->assertSame('spam', $mine[1]['reason'], 'the first reason stays');

		$this->assertTrue($blocklist->remove(AtprotoBlocklistRequest::KIND_HOST, $host));
		$this->assertFalse($blocklist->remove(AtprotoBlocklistRequest::KIND_HOST, $host));
		$this->assertTrue($blocklist->remove(AtprotoBlocklistRequest::KIND_DID, $this->did));
	}

	public function testLabelerSubscriptionsArePerPersonWithTheirSettings(): void {
		$labelers = Server::get(AtprotoLabelerRequest::class);
		$this->assertTrue($labelers->subscribe(self::USER, $this->did));
		$this->assertFalse($labelers->subscribe(self::USER, $this->did));
		$this->assertTrue($labelers->subscribe(self::OTHER, $this->did));
		$this->assertTrue($labelers->subscribe(self::USER, 'did:plc:ar7c4by46qjdydhdevvrndac'));

		$subscribed = $labelers->getByUser(self::USER);
		$this->assertEqualsCanonicalizing([$this->did, 'did:plc:ar7c4by46qjdydhdevvrndac'], array_keys($subscribed), 'subscribed within one second, so in no pinned order');
		$this->assertSame([[], []], array_values($subscribed), 'no settings yet');
		$labelers->setSettings(self::USER, $this->did, ['spam' => 'hide', 'rude' => 'warn']);
		$this->assertSame(['spam' => 'hide', 'rude' => 'warn'], $labelers->getByUser(self::USER)[$this->did]);
		$this->assertSame([], $labelers->getByUser(self::OTHER)[$this->did], 'settings are the person\'s own');
		$this->assertContains($this->did, $labelers->getSubscribedDids(1000));

		$this->assertTrue($labelers->unsubscribe(self::USER, $this->did));
		$this->assertArrayHasKey($this->did, $labelers->getByUser(self::OTHER));
	}

	private function cleanUp(): void {
		Server::get(AtprotoMoveRequest::class)->deleteByUser(self::USER);
		Server::get(AtprotoLabelerRequest::class)->deleteByUser(self::USER);
		Server::get(AtprotoLabelerRequest::class)->deleteByUser(self::OTHER);
		$log = Server::get(AtprotoPlcLogRequest::class);
		foreach ($log->getByDid($this->did) as $row) {
			$log->remove($row['id']);
		}
	}
}
