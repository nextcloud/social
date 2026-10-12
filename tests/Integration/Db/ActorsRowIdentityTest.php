<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A local account's row is found again after the social URL changed.
 *
 * The id an account model is given is derived from today's social URL; the
 * row is stored under the md5 of the id it was made with. Seeded the way an
 * instance that moved its address has them, a key rotation and a profile
 * write have to land on the row, and the walks that page the table by
 * `id_prim` have to carry on from the prim the row has.
 */
class ActorsRowIdentityTest extends TestCase {
	private const BASE = 'https://old-address.example.org/rowidentity';

	/** @var array<string, string> stored id_prim => preferred username */
	private array $seeded = [];

	protected function setUp(): void {
		parent::setUp();
		for ($i = 0; $i < 3; $i++) {
			$name = 'rowidentity' . $i . bin2hex(random_bytes(3));
			$prim = md5(self::BASE . '/@' . $name);
			$this->seeded[$prim] = $name;
			RowSeeder::insert(CoreRequestBuilder::TABLE_ACTORS, [
				'id' => self::BASE . '/@' . $name, 'id_prim' => $prim, 'user_id' => $name,
				'preferred_username' => $name, 'creation' => new \DateTime('-2 years'), 'deleted' => null,
				'private_key' => '', 'public_key' => 'the old key', 'fields' => '[]', 'actor_type' => '',
			]);
		}
	}

	protected function tearDown(): void {
		RowSeeder::deleteIn(CoreRequestBuilder::TABLE_ACTORS, 'id_prim', array_keys($this->seeded));
		parent::tearDown();
	}

	private function column(string $prim, string $column): string {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($column)->from(CoreRequestBuilder::TABLE_ACTORS)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($prim)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return (string)$value;
	}

	public function testAKeyRotationAndAProfileWriteLandOnTheRowAfterTheSocialUrlChanged(): void {
		$actors = Server::get(ActorsRequest::class);
		$prim = array_key_first($this->seeded);
		$actor = $actors->getFromUsername($this->seeded[$prim]);
		$this->assertNotSame($prim, md5($actor->getId()), 'the model\'s id is not what the row is stored under');
		$this->assertSame($prim, $actor->getStoredIdPrim());

		Server::get(SignatureService::class)->generateKeys($actor);
		$actors->refreshKeys($actor);
		$this->assertSame($actor->getPublicKey(), $this->column($prim, 'public_key'), 'the new key is the row\'s');
		$this->assertSame($actor->getPrivateKey(), $actors->getFromUsername($this->seeded[$prim])->getPrivateKey());

		$actor->setFields([['name' => 'Web', 'value' => 'https://example.org/']]);
		$actors->updateFields($actor);
		$this->assertSame(json_encode($actor->getFields()), $this->column($prim, 'fields'), 'a profile write lands too');

		foreach (array_slice(array_keys($this->seeded), 1) as $other) {
			$this->assertSame('the old key', $this->column($other, 'public_key'), 'and on no other row');
		}
	}

	public function testThePagedWalkCarriesOnFromTheStoredPrim(): void {
		$actors = Server::get(ActorsRequest::class);
		$prims = array_keys($this->seeded);
		sort($prims);

		$first = $actors->getPage(1, $this->before($prims[0]));
		$this->assertSame($prims[0], ActorsRequest::rowPrimOf($first[0]), 'the cursor a walk keeps is the row\'s own prim');

		$next = $actors->getPage(1, ActorsRequest::rowPrimOf($first[0]));
		$this->assertGreaterThan($prims[0], ActorsRequest::rowPrimOf($next[0]), 'and the next page starts after that row');
		$this->assertLessThanOrEqual($prims[1], ActorsRequest::rowPrimOf($next[0]), 'without skipping one of ours');
	}

	/** The largest prim of the table below `$prim`, or '' when there is none. */
	private function before(string $prim): string {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('id_prim')->from(CoreRequestBuilder::TABLE_ACTORS)
			->where($qb->expr()->lt('id_prim', $qb->createNamedParameter($prim)))
			->orderBy('id_prim', 'desc')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return ($value === false) ? '' : (string)$value;
	}
}
