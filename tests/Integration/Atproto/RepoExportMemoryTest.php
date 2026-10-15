<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Tests\Integration\PeakMemory;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `com.atproto.sync.getRepo` writes the CAR out as the repository is
 * read: what the export holds at its peak is a page of rows and one
 * piece of the CAR, the same over five times the records — not the
 * repository, which can be larger than the memory a request has. The
 * same holds for the CAR a move sends, which goes through a temporary
 * stream.
 */
class RepoExportMemoryTest extends TestCase {
	private const DID = 'did:plc:integrationexport000000';
	private const RECORD_BYTES = 4096;

	private RepositoryService $repositories;

	protected function setUp(): void {
		$this->repositories = Server::get(RepositoryService::class);
		$this->repositories->delete(self::DID);
	}

	protected function tearDown(): void {
		$this->repositories->delete(self::DID);
	}

	public function testTheExportHoldsAPageNotTheRepository(): void {
		$small = $this->peakOfExporting(600);
		$this->repositories->delete(self::DID);
		$large = $this->peakOfExporting(3000);

		PeakMemory::assertFlat($small, $large, 'the repository export');
		$this->assertLessThan(3000 * self::RECORD_BYTES / 2, $large, 'the peak is below half the CAR');
	}

	public function testThePiecesAreTheCarWhole(): void {
		$this->seed(120);
		$pieces = iterator_to_array($this->repositories->streamCar(self::DID), false);

		$decoded = Car::decode(implode('', $pieces));
		$this->assertSame($this->repositories->getHead(self::DID)?->commitCid, $decoded['roots'][0]->toString());
		$this->assertGreaterThan(120, count($decoded['blocks']), 'the records and the tree');
		$stream = $this->repositories->carStream(self::DID);
		$this->assertSame(Car::decode((string)stream_get_contents($stream))['blocks'], $decoded['blocks']);
		fclose($stream);
	}

	/** The CAR a move uploads is spilled to disk as it is read, never one string. */
	public function testTheStreamForAMoveHoldsAPageNotTheRepository(): void {
		$this->seed(3000);
		$size = 0;

		$peak = PeakMemory::of(function () use (&$size): void {
			$car = $this->repositories->carStream(self::DID);
			$size = fstat($car)['size'];
			fclose($car);
		});

		$this->assertGreaterThan(3000 * self::RECORD_BYTES, $size, 'the whole CAR is in the stream');
		$this->assertLessThan(3000 * self::RECORD_BYTES / 2, $peak, 'the peak is below half the CAR');
	}

	private function peakOfExporting(int $records): int {
		$this->seed($records);
		$total = 0;

		$peak = PeakMemory::of(function () use (&$total): void {
			foreach ($this->repositories->streamCar(self::DID) as $piece) {
				$total += strlen($piece);
			}
		});
		$this->assertGreaterThan($records * self::RECORD_BYTES, $total, 'the whole CAR went out');

		return $peak;
	}

	private function seed(int $count): void {
		$records = [];
		for ($i = 0; $i < $count; $i++) {
			$records['app.bsky.feed.post/3kznmn7x' . str_pad(base_convert((string)$i, 10, 32), 5, '0', STR_PAD_LEFT)] = DagCbor::encode([
				'$type' => 'app.bsky.feed.post',
				'text' => $i . ' ' . str_repeat(chr(97 + $i % 26), self::RECORD_BYTES),
				'createdAt' => '2026-10-08T10:00:00.000Z',
			]);
		}
		$this->repositories->import(self::DID, PrivateKey::generate(Curve::K256), $records);
	}
}
