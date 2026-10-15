<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20261016000030;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `Version1000Date20261016000030` against the real database: the packs
 * already stored are counted from their member lists, a pack with none
 * stays at nothing, and a second run changes nothing.
 */
class StarterPackMemberCountBackfillTest extends TestCase {
	/** @var string[] uri prims of this run */
	private array $packs = [];

	protected function tearDown(): void {
		foreach ($this->packs as $prim) {
			RowSeeder::delete(CoreRequestBuilder::TABLE_STARTER_PACKS, ['uri_prim' => $prim]);
		}
		parent::tearDown();
	}

	private function pack(string $members): string {
		$prim = md5(random_bytes(16));
		$this->packs[] = $prim;
		RowSeeder::insert(CoreRequestBuilder::TABLE_STARTER_PACKS, [
			'uri' => 'https://packs.example/c/' . $prim, 'uri_prim' => $prim, 'slug' => 'backfill-' . $prim,
			'members' => $members, 'member_count' => 0,
		]);

		return $prim;
	}

	private function memberCount(string $prim): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('member_count')->from(CoreRequestBuilder::TABLE_STARTER_PACKS)
			->where($qb->expr()->eq('uri_prim', $qb->createNamedParameter($prim)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return (int)$value;
	}

	private function runStep(): void {
		$schema = $this->createStub(ISchemaWrapper::class);
		Server::get(Version1000Date20261016000030::class)
			->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);
	}

	public function testThePacksAlreadyStoredAreCountedAndRunAgainNothingMoves(): void {
		$three = $this->pack('["https://a.example/u/1","https://a.example/u/2","https://a.example/u/3"]');
		$none = $this->pack('[]');
		$broken = $this->pack('not json');

		for ($run = 1; $run <= 2; $run++) {
			$this->runStep();

			$this->assertSame(3, $this->memberCount($three), 'run ' . $run);
			$this->assertSame(0, $this->memberCount($none), 'run ' . $run);
			$this->assertSame(0, $this->memberCount($broken), 'run ' . $run);
		}
	}
}
