<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * The two tables an announcement lives in: that the columns are the ones the
 * entity is built out of, and that the index is the read the client route
 * takes.
 */
class AnnouncementTablesTest extends TestCase {
	use ReadsTheSchema;

	private const ANNOUNCEMENTS = 'social_announcement';
	private const READS = 'social_announce_read';

	public function testTheNoticeIsStoredWhole(): void {
		// a notice about an outage is paragraphs, not a title: a VARCHAR would
		// cut one off on MySQL and fail the insert on a strict one
		[$type, $options] = $this->column(self::ANNOUNCEMENTS, 'content');

		$this->assertSame(Types::TEXT, $type);
		$this->assertTrue($options['notnull']);
	}

	public function testBothBoundsOfTheWindowAreNullableDates(): void {
		// NULL is "no bound", which is what the read compares against: an
		// announcement with no window has to match either way round
		foreach (['starts_at', 'ends_at'] as $column) {
			[$type, $options] = $this->column(self::ANNOUNCEMENTS, $column);

			$this->assertSame(Types::DATETIME, $type, $column);
			$this->assertFalse($options['notnull'], $column);
		}
	}

	public function testAnAnnouncementIsDatedAtBothEndsOfItsOwnLife(): void {
		// creation is the entity's published_at and last_update its updated_at,
		// which Mastodon documents as non-nullable
		foreach (['creation', 'last_update'] as $column) {
			$this->assertSame(
				Types::DATETIME,
				$this->column(self::ANNOUNCEMENTS, $column)[0],
				$column
			);
		}
	}

	public function testWholeDaysIsAFlagThatDefaultsToOff(): void {
		[$type, $options] = $this->column(self::ANNOUNCEMENTS, 'all_day');

		$this->assertSame(Types::SMALLINT, $type);
		$this->assertTrue($options['notnull']);
		$this->assertSame(0, $options['default'], 'a row written without it is not a whole-day window');
	}

	public function testAnAnnouncementNeedsNoIndexBeyondItsKey(): void {
		// every read of this table is its whole active set, and it holds a
		// handful of rows: an index on a date would be read past on all of them
		$this->assertSame([], $this->indexesOf(self::ANNOUNCEMENTS));
		$this->assertSame(['id'], $this->primaryKeyOf(self::ANNOUNCEMENTS));
	}

	public function testADismissalIsUniquePerAccountAndAnnouncement(): void {
		// which is what makes dismissing twice a no-op, and it is the index the
		// client read probes: one account's dismissals among a page of ids, so
		// the account is the leading column
		$unique = array_values(array_filter(
			$this->indexesOf(self::READS),
			static fn (array $index): bool => $index[2]
		));

		$this->assertCount(1, $unique);
		$this->assertSame(['actor_id_prim', 'announcement_id'], $unique[0][0]);
		$this->assertSame('social_annread_aa', $unique[0][1]);
	}

	public function testTheAccountIsStoredTheShapeTheRestOfTheSchemaJoinsActorsBy(): void {
		[$type, $options] = $this->column(self::READS, 'actor_id_prim');

		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length'], 'a prim is an md5');
		$this->assertTrue($options['notnull']);
	}

	public function testADismissalPointsAtAnAnnouncementByItsKey(): void {
		[$type, $options] = $this->column(self::READS, 'announcement_id');

		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['notnull']);
		$this->assertTrue($options['unsigned']);
	}

	public function testBothTablesCarryAnAutoincrementKey(): void {
		foreach ([self::ANNOUNCEMENTS, self::READS] as $table) {
			$this->assertSame(['id'], $this->primaryKeyOf($table), $table);

			[$type, $options] = $this->column($table, 'id');
			$this->assertSame(Types::BIGINT, $type, $table);
			$this->assertTrue($options['autoincrement'], $table);
		}
	}
}
