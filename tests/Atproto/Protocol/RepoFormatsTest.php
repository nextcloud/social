<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Protocol;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\MstReader;
use OCA\Social\Atproto\Protocol\Tid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The containers a repository travels in, read as untrusted input: a CAR
 * from another PDS (a move here), the tree inside it, its commit, and the
 * TIDs that order everything. Round trips with what this app writes, the
 * values the specifications fix, and refusals of what is malformed.
 */
class RepoFormatsTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	public function testTheCidsOfKnownBytes(): void {
		$this->assertSame('bafkreihdwdcefgh4dqkjv67uzcmw7ojee6xedzdetojuzjevtenxquvyku', Cid::forRaw('')->toString(), 'the raw CID of nothing, as every IPFS tool computes it');
		$this->assertSame(Cid::CODEC_RAW, Cid::forRaw('x')->codec());
		$this->assertSame(Cid::forRaw('x')->toString(), Cid::forRawDigest(hash('sha256', 'x', true))->toString());
		$this->assertSame(Cid::forRaw('x')->toString(), Cid::fromBytes(Cid::forRaw('x')->bytes())->toString());
		$this->assertSame(36, strlen(Cid::forDagCbor(DagCbor::encode(['a' => 1]))->bytes()), 'v1, codec, sha2-256, 32 bytes');
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: string}>
	 */
	public static function tids(): array {
		return [
			'zero' => [0, 0, '2222222222222'],
			'the largest' => [(1 << 53) - 1, 1023, 'bzzzzzzzzzzzz'],
			'a real one' => [1688137381887007, 6, '3jzfcijpj2z2a'],
		];
	}

	#[DataProvider('tids')]
	public function testATidIsItsTimeAndClockInSortableBase32(int $micros, int $clock, string $tid): void {
		$this->assertSame($tid, Tid::encode($micros, $clock));
		$this->assertSame([$micros, $clock], Tid::decode($tid));
		$this->assertTrue(Tid::isValid($tid));
	}

	public function testTidsSortAsTheirTimesDo(): void {
		$earlier = Tid::encode(1688137381887007, 1023);
		$later = Tid::encode(1688137381887008, 0);

		$this->assertLessThan(0, strcmp($earlier, $later));
	}

	public function testARevisionAlwaysMovesForward(): void {
		$future = Tid::encode((int)(microtime(true) * 1000000) + 3600 * 1000000, 5);

		$next = Tid::after($future);
		$this->assertGreaterThan(0, strcmp($next, $future), 'past a head written by a clock an hour ahead');
		$this->assertGreaterThan(0, strcmp(Tid::after($next), $next));
		$this->assertGreaterThan(0, strcmp(Tid::after(''), ''), 'the first revision');
	}

	public function testTidsOutOfRangeAreRefused(): void {
		foreach ([[-1, 0], [1 << 53, 0], [0, 1024], [0, -1]] as [$micros, $clock]) {
			try {
				Tid::encode($micros, $clock);
				$this->fail('encoded ' . $micros . '/' . $clock);
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
		$this->expectException(InvalidArgumentException::class);
		Tid::decode('3jzfcijpj2z2');
	}

	/**
	 * A commit signed with a P-256 key (an account that moved here from a
	 * PDS using one) reads back and verifies as a K-256 one does.
	 */
	#[DataProvider('curves')]
	public function testACommitOnEitherCurveRoundTrips(Curve $curve): void {
		$key = PrivateKey::generate($curve);
		$root = (new Mst(['app.bsky.feed.post/3jzfcijpj2z2a' => Cid::forRaw('r')]))->build()->root;
		$commit = Commit::sign(self::DID, $root, '3jzfcijpj2z2a', $key);

		$read = Commit::fromBytes($commit->toBytes());
		$this->assertTrue($read->verify($key->publicKey()));
		$this->assertFalse($read->verify(PrivateKey::generate($curve)->publicKey()));
		$this->assertSame($commit->cid()->toString(), Cid::forDagCbor($commit->toBytes())->toString());
		$this->assertSame(['did', 'rev', 'sig', 'data', 'prev', 'version'], array_keys(DagCbor::decode($commit->toBytes())), 'canonical key order');
	}

	public static function curves(): array {
		return ['K-256' => [Curve::K256], 'P-256' => [Curve::P256]];
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function malformedCommits(): array {
		$root = Cid::forRaw('r');
		$good = ['did' => self::DID, 'version' => 3, 'data' => $root, 'rev' => '3jzfcijpj2z2a', 'prev' => null, 'sig' => new Bytes(str_repeat("\1", 64))];

		return [
			'no prev at all' => [array_diff_key($good, ['prev' => true])],
			'a short signature' => [['sig' => new Bytes(str_repeat("\1", 63))] + $good],
			'a signature as text' => [['sig' => str_repeat('a', 64)] + $good],
			'a revision that is not a TID' => [['rev' => '1'] + $good],
			'data that is not a link' => [['data' => $root->toString()] + $good],
			'a DID that is not text' => [['did' => 7] + $good],
			'version 4' => [['version' => 4] + $good],
		];
	}

	#[DataProvider('malformedCommits')]
	public function testAMalformedCommitIsRefused(array $commit): void {
		$this->expectException(InvalidArgumentException::class);
		Commit::fromBytes(DagCbor::encode($commit));
	}

	/**
	 * The tree of a repository read back out of its blocks gives every
	 * record path and CID that went in, in key order, across the layers a
	 * few hundred keys make.
	 */
	public function testTheLeavesOfATreeAreReadBackFromItsBlocks(): void {
		$leaves = [];
		for ($i = 0; $i < 300; $i++) {
			$leaves['app.bsky.feed.post/' . Tid::encode(1688137381887007 + $i * 7919, $i % 1024)] = Cid::forRaw((string)$i);
		}
		$leaves['app.bsky.actor.profile/self'] = Cid::forRaw('profile');
		$tree = (new Mst($leaves))->build();
		$this->assertGreaterThan(1, count($tree->blocks), 'more than one layer');

		$read = MstReader::leaves($tree->root, $tree->blocks);
		ksort($leaves, SORT_STRING);
		$this->assertSame(array_keys($leaves), array_keys($read));
		foreach ($leaves as $path => $cid) {
			$this->assertTrue($cid->equals($read[$path]));
		}
	}

	public function testATreeWithAMissingNodeIsRefused(): void {
		$leaves = [];
		for ($i = 0; $i < 100; $i++) {
			$leaves['app.bsky.feed.post/' . Tid::encode(1688137381887007 + $i, 0)] = Cid::forRaw((string)$i);
		}
		$tree = (new Mst($leaves))->build();
		$blocks = $tree->blocks;
		$this->assertGreaterThan(1, count($blocks));
		unset($blocks[array_values(array_diff(array_keys($blocks), [$tree->root->toString()]))[0]]);

		$this->expectException(InvalidArgumentException::class);
		MstReader::leaves($tree->root, $blocks);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformedNodes(): array {
		$leaf = Cid::forRaw('r');

		return [
			'not a map' => [[1, 2]],
			'no entries' => [['l' => null]],
			'a prefix longer than the key before' => [['l' => null, 'e' => [['p' => 3, 'k' => new Bytes('app.bsky.feed.post/3jzfcijpj2z2a'), 'v' => $leaf, 't' => null]]]],
			'a key as text' => [['l' => null, 'e' => [['p' => 0, 'k' => 'app.bsky.feed.post/3jzfcijpj2z2a', 'v' => $leaf, 't' => null]]]],
			'a value that is not a link' => [['l' => null, 'e' => [['p' => 0, 'k' => new Bytes('app.bsky.feed.post/3jzfcijpj2z2a'), 'v' => 'x', 't' => null]]]],
			'a key that is not a record path' => [['l' => null, 'e' => [['p' => 0, 'k' => new Bytes('../../etc/passwd'), 'v' => $leaf, 't' => null]]]],
			'a negative prefix' => [['l' => null, 'e' => [['p' => -1, 'k' => new Bytes('app.bsky.feed.post/3jzfcijpj2z2a'), 'v' => $leaf, 't' => null]]]],
		];
	}

	#[DataProvider('malformedNodes')]
	public function testAMalformedNodeIsRefused(mixed $node): void {
		$bytes = DagCbor::encode($node);
		$cid = Cid::forDagCbor($bytes);

		$this->expectException(InvalidArgumentException::class);
		MstReader::leaves($cid, [$cid->toString() => $bytes]);
	}

	/**
	 * A node whose subtree is itself never ends; the walk does, at the
	 * depth no real repository reaches.
	 */
	public function testATreeThatLoopsIsRefused(): void {
		$blocks = [];
		$cid = Cid::forRaw('seed');
		// a chain of nodes each pointing left to the one before, deeper than any repository
		for ($i = 0; $i < 70; $i++) {
			$bytes = DagCbor::encode(['e' => [], 'l' => $i === 0 ? null : $cid]);
			$cid = Cid::forDagCbor($bytes);
			$blocks[$cid->toString()] = $bytes;
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('deeper');
		MstReader::leaves($cid, $blocks);
	}

	public function testACarRoundTripsWithRawAndDagCborBlocks(): void {
		$record = DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'hi']);
		$blocks = [Cid::forDagCbor($record)->toString() => $record, Cid::forRaw('pixels')->toString() => 'pixels'];
		$car = Car::encode([Cid::forDagCbor($record)], $blocks);

		$read = Car::decode($car);
		$this->assertSame($blocks, $read['blocks']);
		$this->assertSame(Cid::forDagCbor($record)->toString(), $read['roots'][0]->toString());
		$this->assertEquals(['version' => 1, 'roots' => [Cid::forDagCbor($record)]], DagCbor::decode(substr($car, 1, ord($car[0]))), 'the header is a DAG-CBOR map of version and roots');
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformedCars(): array {
		$record = DagCbor::encode(['text' => 'hi']);
		$cid = Cid::forDagCbor($record);
		$good = Car::encode([$cid], [$cid->toString() => $record]);
		$header = static fn (array $value): string => Encoding::varint(strlen(DagCbor::encode($value))) . DagCbor::encode($value);

		return [
			'nothing' => [''],
			'cut short' => [substr($good, 0, -3)],
			'version 2' => [$header(['version' => 2, 'roots' => [$cid]])],
			'a root that is not a link' => [$header(['version' => 1, 'roots' => [$cid->toString()]])],
			'no roots' => [$header(['version' => 1])],
			'a block shorter than its CID' => [$header(['version' => 1, 'roots' => [$cid]]) . Encoding::varint(10) . str_repeat("\0", 10)],
			'a length past the end' => [$header(['version' => 1, 'roots' => [$cid]]) . Encoding::varint(1000) . $cid->bytes() . $record],
			'a block whose bytes are not its CID' => [$header(['version' => 1, 'roots' => [$cid]]) . Encoding::varint(36 + strlen($record) + 1) . $cid->bytes() . $record . 'x'],
		];
	}

	#[DataProvider('malformedCars')]
	public function testAMalformedCarIsRefused(string $car): void {
		$this->expectException(InvalidArgumentException::class);
		Car::decode($car);
	}

	public function testACarWithMoreBlocksThanAllowedIsRefused(): void {
		$blocks = [];
		foreach (['a', 'b', 'c'] as $bytes) {
			$blocks[Cid::forRaw($bytes)->toString()] = $bytes;
		}
		$car = Car::encode([], $blocks);

		$this->assertCount(3, Car::decode($car, 3)['blocks']);
		$this->expectException(InvalidArgumentException::class);
		Car::decode($car, 2);
	}
}
