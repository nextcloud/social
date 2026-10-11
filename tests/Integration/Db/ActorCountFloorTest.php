<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `CacheActorsRequest::bumpCount()` against the real database: a decrement
 * stops at 0 in the same UPDATE, and a counter that was never counted (`-1`)
 * is moved by no step at all, so a bump can never write the "not counted"
 * marker over a real number.
 *
 * The row carries a '-countfloor-' id and is removed in tearDown.
 */
class ActorCountFloorTest extends TestCase {
	private const ACTOR = 'https://remote.example/countfloor/users/bob';

	private CacheActorsRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(CacheActorsRequest::class);
		$this->request->deleteCacheById(self::ACTOR);

		$actor = new Person();
		$actor->setId(self::ACTOR);
		$actor->setPreferredUsername('bob');
		$actor->setAccount('bob@remote.example');
		$actor->setInbox(self::ACTOR . '/inbox');
		$actor->setFollowers(self::ACTOR . '/followers');
		$actor->setFollowing(self::ACTOR . '/following');
		$this->request->save($actor);
	}

	protected function tearDown(): void {
		$this->request->deleteCacheById(self::ACTOR);
		parent::tearDown();
	}

	/** Writes the column directly: `setCounts()` cannot write the marker. */
	private function followersAre(int $value): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('social_cache_actor')
			->set('count_followers', $qb->createNamedParameter($value))
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5(self::ACTOR))));
		$qb->executeStatement();
	}

	private function followers(): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('count_followers')
			->from('social_cache_actor')
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5(self::ACTOR))));
		$cursor = $qb->executeQuery();
		$value = $cursor->fetchOne();
		$cursor->closeCursor();

		return (int)$value;
	}

	/** @return array<string, array{int, int, int}> start, step, expected */
	public static function steps(): array {
		return [
			'a decrement at 0 stays 0' => [0, -1, 0],
			'a decrement past 0 stops at 0' => [2, -3, 0],
			'a decrement above 0 counts down' => [5, -1, 4],
			'an increment counts up' => [5, 1, 6],
			'a decrement leaves "not counted" alone' => [-1, -1, -1],
			'an increment leaves "not counted" alone' => [-1, 1, -1],
		];
	}

	#[DataProvider('steps')]
	public function testABumpMovesACountedCounterAndNeverBelowZero(int $start, int $step, int $expected): void {
		$this->followersAre($start);

		$this->request->bumpCount(self::ACTOR, 'count_followers', $step);

		$this->assertSame($expected, $this->followers());
	}
}
