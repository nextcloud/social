<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `ActorsRequest::walk()` against the real table: every local account once,
 * across pages, while the caller writes to the table as it goes — and on an
 * instance whose social URL changed since the accounts were made, where the
 * id a model is given no longer hashes to the stored `id_prim` the walk is
 * keyed on.
 */
class ActorsWalkTest extends TestCase {
	private const BASE = 'https://old-address.example.org/actorswalk';
	private const COUNT = 230;

	/** @var array<string, string> stored id_prim => preferred username */
	private array $seeded = [];

	protected function setUp(): void {
		parent::setUp();
		$rows = [];
		for ($i = 0; $i < self::COUNT; $i++) {
			$name = 'actorswalk' . $i . bin2hex(random_bytes(3));
			$prim = md5(self::BASE . '/' . $name);
			$this->seeded[$prim] = $name;
			$rows[] = [
				'id' => self::BASE . '/' . $name, 'id_prim' => $prim, 'user_id' => $name,
				'preferred_username' => $name, 'creation' => new \DateTime(), 'deleted' => null,
				'private_key' => '', 'public_key' => '', 'fields' => '[]', 'actor_type' => '',
			];
		}
		RowSeeder::insertAll(CoreRequestBuilder::TABLE_ACTORS, $rows);
	}

	protected function tearDown(): void {
		RowSeeder::deleteIn(CoreRequestBuilder::TABLE_ACTORS, 'id_prim', array_keys($this->seeded));
		parent::tearDown();
	}

	public function testEveryAccountIsVisitedOnceAcrossPagesWhileTheTableIsWrittenTo(): void {
		$actors = Server::get(ActorsRequest::class);
		$visited = [];
		foreach ($actors->walk(100) as $actor) {
			$this->assertInstanceOf(Person::class, $actor);
			$visited[] = $actor->getPreferredUsername();
			// a write to the row just handed over, as a key rotation makes one
			if (in_array($actor->getPreferredUsername(), $this->seeded, true)) {
				$qb = Server::get(IDBConnection::class)->getQueryBuilder();
				$qb->update(CoreRequestBuilder::TABLE_ACTORS)
					->set('public_key', $qb->createNamedParameter('walked'))
					->where($qb->expr()->eq('preferred_username', $qb->createNamedParameter($actor->getPreferredUsername())));
				$qb->executeStatement();
			}
		}

		$ours = array_values(array_intersect($visited, $this->seeded));
		$this->assertCount(self::COUNT, $ours, 'every seeded account, over three pages of a hundred');
		$this->assertCount(count($ours), array_unique($ours), 'and none of them twice');
		$this->assertSame(self::COUNT, RowSeeder::count(CoreRequestBuilder::TABLE_ACTORS, ['public_key' => 'walked']), 'each written as it was passed');
		$this->assertNotSame(
			array_key_first($this->seeded), md5($actors->getFromUsername(reset($this->seeded))->getId()),
			'the model\'s id is not what the stored prim hashes: the walk does not page on it'
		);
	}
}
