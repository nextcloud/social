<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StatusDeletionEventsRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** The streaming deletion cursor against the installed database. */
class StatusDeletionEventsTest extends TestCase {
	private StatusDeletionEventsRequest $events;
	private int $cursor = 0;
	/** @var list<int> */
	private array $eventIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->events = Server::get(StatusDeletionEventsRequest::class);
		$this->cursor = $this->events->newestId();
	}

	protected function tearDown(): void {
		if ($this->eventIds !== []) {
			$qb = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
			$qb->delete(CoreRequestBuilder::TABLE_STATUS_DELETIONS)
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->eventIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		parent::tearDown();
	}

	public function testRecordsPublicStatusIdAndStreamFilters(): void {
		$this->events->record((new Note())
			->setNid('9223372036854000000')
			->setHashtags(['Nextcloud', 'Mastodon'])
			->setVisibility(Stream::TYPE_PUBLIC)
			->setLocal(true));

		$rows = $this->events->getAfterId($this->cursor, 20);
		$event = array_values(array_filter($rows, fn (array $row): bool => $row['status_nid'] === '9223372036854000000'))[0] ?? null;
		$this->assertNotNull($event);
		$this->eventIds[] = $event['id'];
		$this->assertSame(['Nextcloud', 'Mastodon'], $event['hashtags']);
		$this->assertTrue($event['local']);
		$this->assertFalse($event['media']);
		$this->assertSame($event['id'], $this->events->newestId());
	}

	public function testDoesNotRecordPrivateStatusDeletions(): void {
		$this->events->record((new Note())
			->setNid('9223372036854000001')
			->setVisibility(Stream::TYPE_DIRECT));

		$ids = array_column($this->events->getAfterId($this->cursor, 20), 'status_nid');
		$this->assertNotContains('9223372036854000001', $ids);
	}
}
