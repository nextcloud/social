<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executes the real timeline queries — with a viewer and a block/mute row present,
 * so SocialLimitsQueryBuilder::filterHiddenActors() actually joins — against the
 * database the CI job installs. The unit suite mocks the query builder and so never
 * builds this SQL; a malformed anti-join would 500 every timeline in production
 * while staying green in units. This asserts the SQL is valid on SQLite / MySQL /
 * PostgreSQL at each hidden-actor level.
 */
class StreamFilterTest extends TestCase {
	private StreamRequest $streamRequest;
	private ActorRelationRequest $actorRelationRequest;

	private const VIEWER = 'https://cloud.example.org/users/viewer-itest';
	private const BLOCKED = 'https://remote.example/users/blocked-itest';

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->actorRelationRequest = Server::get(ActorRelationRequest::class);
		$this->actorRelationRequest->deleteRelatedId(self::VIEWER);
	}

	protected function tearDown(): void {
		$this->actorRelationRequest->deleteRelatedId(self::VIEWER);
		parent::tearDown();
	}

	private function viewer(): Person {
		$viewer = new Person();
		$viewer->setId(self::VIEWER);
		$viewer->setLocal(true);

		return $viewer;
	}

	#[DataProvider('hiddenLevelProbes')]
	public function testEveryTimelineLevelBuildsValidSqlWithRelationsPresent(string $probe): void {
		// one relation of each kind, so all three branches of the filter are live
		$this->actorRelationRequest->save(self::VIEWER, self::BLOCKED, ActorRelation::TYPE_BLOCK);
		$this->actorRelationRequest->save(self::VIEWER, self::BLOCKED . '/x', ActorRelation::TYPE_MUTE, true);
		$this->actorRelationRequest->save(self::VIEWER, self::BLOCKED . '/y', ActorRelation::TYPE_MUTE, false);

		$this->streamRequest->setViewer($this->viewer());

		$options = new ProbeOptions();
		$options->setProbe($probe);
		$options->setAccountId(self::VIEWER);
		$options->setLimit(10);

		// The assertion is that this executes at all: filterHiddenActors() joins
		// social_actor_relation (and social_stream for the boost case) and the query
		// must be valid SQL on the CI database. An empty result is fine — the point
		// is that the anti-join runs.
		$result = $this->streamRequest->getTimeline($options);
		$this->assertIsArray($result);
	}

	public static function hiddenLevelProbes(): array {
		return [
			'public (full filter)' => [ProbeOptions::PUBLIC],
			'home (full filter + follow join)' => [ProbeOptions::HOME],
			'account (blocks only)' => [ProbeOptions::ACCOUNT],
			'direct (full filter)' => [ProbeOptions::DIRECT],
			'favourites (blocks only)' => [ProbeOptions::FAVOURITES],
			'notifications (block + mute-notif)' => [ProbeOptions::NOTIFICATIONS],
		];
	}

	public function testSinglePostContextBuildsValidSqlWithABlock(): void {
		$this->actorRelationRequest->save(self::VIEWER, self::BLOCKED, ActorRelation::TYPE_BLOCK);
		$this->streamRequest->setViewer($this->viewer());

		// getTimelineHome path already covered; this drives the getStreamByNid /
		// descendants join (limitToViewer at HIDDEN_DIRECT). A missing post throws a
		// domain exception, not an SQL error, so any non-SQL outcome proves validity.
		try {
			$this->streamRequest->getDescendants('https://remote.example/notes/does-not-exist');
			$this->addToAssertionCount(1);
		} catch (\OCP\DB\Exception $e) {
			$this->fail('descendants query is not valid SQL: ' . $e->getMessage());
		} catch (\Throwable $e) {
			// a domain-level miss is fine — the SQL executed
			$this->addToAssertionCount(1);
		}
	}
}
