<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\ListsRequest;
use OCA\Social\Model\Client\MastodonList;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * The two tables a list lives in, and that every index on them is a read path
 * the routes actually take.
 */
class ListsTablesTest extends TestCase {
	use ReadsTheSchema;

	private const LISTS = 'social_list';
	private const MEMBERS = 'social_list_member';

	public function testTheOwnerIsStoredTheShapeTheRestOfTheSchemaJoinsActorsBy(): void {
		foreach ([self::LISTS, self::MEMBERS] as $table) {
			[$type, $options] = $this->column($table, 'actor_id_prim');
			$this->assertSame(Types::STRING, $type, $table);
			$this->assertSame(32, $options['length'], $table . ': a prim is an md5');
			$this->assertTrue($options['notnull'], $table);

			// a prim is one-way, and the routes have to hand accounts back
			[$type, $options] = $this->column($table, 'actor_id');
			$this->assertSame(Types::TEXT, $type, $table);
			$this->assertTrue($options['notnull'], $table);
		}
	}

	public function testTheTitleIsAsWideAsMastodonsAndTheEntityAgrees(): void {
		[$type, $options] = $this->column(self::LISTS, 'title');

		$this->assertSame(Types::STRING, $type);
		$this->assertSame(255, $options['length']);
		$this->assertTrue($options['notnull']);
		$this->assertSame(
			255,
			ListsRequest::MAX_TITLE_LENGTH,
			'the length a title is cut to has to be the length the column holds,'
			. ' or a long title fails the insert on a strict MySQL'
		);
	}

	public function testThePolicyIsStoredByNameAndDefaultsToMastodonsDefault(): void {
		[$type, $options] = $this->column(self::LISTS, 'replies_policy');

		$this->assertSame(Types::STRING, $type, 'the enum is stored as its name, not as an ordinal');
		$this->assertTrue($options['notnull']);
		$this->assertSame(
			MastodonList::DEFAULT_REPLIES_POLICY,
			$options['default'],
			'a row written without the column has to mean what a list created without it means'
		);
	}

	public function testAListIsFoundByItsOwner(): void {
		// GET /api/v1/lists is every list of one account, and every other list
		// route is "this id, and it must be mine"
		$this->assertContains(
			[['actor_id_prim'], 'social_list_a', false],
			$this->indexesOf(self::LISTS)
		);
	}

	public function testAMembershipIsUniquePerListAndAccount(): void {
		// which is what makes adding an account twice a no-op, and it is the
		// index the list timeline joins social_stream.attributed_to_prim on
		$unique = array_values(array_filter(
			$this->indexesOf(self::MEMBERS),
			static fn (array $index): bool => $index[2]
		));

		$this->assertCount(1, $unique);
		$this->assertSame(['list_id', 'actor_id_prim'], $unique[0][0]);
		$this->assertSame('social_lm_la', $unique[0][1]);
	}

	public function testAnAccountCanBeAskedWhichListsItIsIn(): void {
		// GET /api/v1/accounts/{id}/lists reads the membership table by member,
		// which the (list_id, actor_id_prim) index cannot answer: its leading
		// column is the list
		$this->assertContains(
			[['actor_id_prim'], 'social_lm_a', false],
			$this->indexesOf(self::MEMBERS)
		);
	}

	public function testBothTablesCarryAnAutoincrementKeyToPageOn(): void {
		foreach ([self::LISTS, self::MEMBERS] as $table) {
			$this->assertSame(['id'], $this->primaryKeyOf($table), $table);

			[$type, $options] = $this->column($table, 'id');
			$this->assertSame(Types::BIGINT, $type, $table);
			$this->assertTrue($options['autoincrement'], $table);
		}
	}
}
