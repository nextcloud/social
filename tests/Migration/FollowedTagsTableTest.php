<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/** The table a followed hashtag lives in. */
class FollowedTagsTableTest extends TestCase {
	use ReadsTheSchema;

	public function testTheTableIsTheOneTheCodeReadsAndWrites(): void {
		$this->assertColumnsAre(
			CoreRequestBuilder::TABLE_FOLLOWED_TAGS,
			CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_FOLLOWED_TAGS],
			'every column of the table is declared in CoreRequestBuilder, and nothing else'
		);
	}

	public function testTheOwnerAndTheTagAreTheShapeTheRestOfTheSchemaUses(): void {
		[$type, $options] = $this->column(CoreRequestBuilder::TABLE_FOLLOWED_TAGS, 'actor_id_prim');
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length'], 'a prim is an md5');
		$this->assertTrue($options['notnull']);

		[$type, $options] = $this->column(CoreRequestBuilder::TABLE_FOLLOWED_TAGS, 'hashtag');
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(
			127,
			$options['length'],
			'the same width social_stream_tag.hashtag has, or a followable tag could'
			. ' be one no post can carry'
		);
		$this->assertTrue($options['notnull']);
	}

	public function testThePairIsUniqueAndIsTheIndexTheTimelineJoinReads(): void {
		$unique = array_values(array_filter(
			$this->indexesOf(CoreRequestBuilder::TABLE_FOLLOWED_TAGS),
			static fn (array $index): bool => $index[2]
		));

		$this->assertCount(1, $unique);
		$this->assertSame(['actor_id_prim', 'hashtag'], $unique[0][0]);
		$this->assertSame('social_ft_ah', $unique[0][1]);
	}

	public function testTheRowsCarryAnAutoincrementKeyToPageOn(): void {
		$this->assertSame(['id'], $this->primaryKeyOf(CoreRequestBuilder::TABLE_FOLLOWED_TAGS));

		[$type, $options] = $this->column(CoreRequestBuilder::TABLE_FOLLOWED_TAGS, 'id');
		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['autoincrement']);
	}
}
