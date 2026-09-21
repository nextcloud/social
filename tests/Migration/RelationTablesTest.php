<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\AccountNotesRequestBuilder;
use OCA\Social\Db\DomainBlocksRequestBuilder;
use OCA\Social\Db\MuteExpiryRequestBuilder;
use OCA\Social\Service\AccountRelationService;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * The three tables the per-account decisions live in, and that every index on
 * them is a read path a route actually takes.
 */
class RelationTablesTest extends TestCase {
	use ReadsTheSchema;

	private const DOMAIN_BLOCKS = 'social_domain_block';
	private const NOTES = 'social_account_note';
	private const MUTE_EXPIRY = 'social_mute_expiry';

	public function testTheTablesAreTheOnesTheCodeReadsAndWrites(): void {
		$this->assertSame(self::DOMAIN_BLOCKS, DomainBlocksRequestBuilder::TABLE_DOMAIN_BLOCKS);
		$this->assertSame(self::NOTES, AccountNotesRequestBuilder::TABLE_ACCOUNT_NOTES);
		$this->assertSame(self::MUTE_EXPIRY, MuteExpiryRequestBuilder::TABLE_MUTE_EXPIRY);
	}

	public function testEveryTableKeysItsOwnerTheShapeTheRestOfTheSchemaJoinsActorsBy(): void {
		foreach ([self::DOMAIN_BLOCKS, self::NOTES, self::MUTE_EXPIRY] as $table) {
			[$type, $options] = $this->column($table, 'actor_id_prim');

			$this->assertSame(Types::STRING, $type, $table);
			$this->assertSame(32, $options['length'], $table . ': a prim is an md5');
			$this->assertTrue($options['notnull'], $table);
		}
	}

	public function testABlockedInstanceIsStoredOncePerAccount(): void {
		// which is what makes blocking twice a no-op, and it is the index every
		// timeline read probes
		$this->assertSame(
			[[['actor_id_prim', 'domain'], 'social_dblk_ad', true]],
			$this->indexesOf(self::DOMAIN_BLOCKS)
		);
	}

	public function testTheDomainColumnHoldsAnyHostThatCanBeTyped(): void {
		[$type, $options] = $this->column(self::DOMAIN_BLOCKS, 'domain');

		$this->assertSame(Types::STRING, $type);
		$this->assertSame(255, $options['length'], 'the longest a host name can be');
		$this->assertTrue($options['notnull']);
	}

	public function testANoteSaysWhoItIsAboutAndNotOnlyItsHash(): void {
		// a prim is one-way: without the id beside it the row cannot say whose
		// note it is, and this is the one table here worth exporting
		$this->assertSame(Types::TEXT, $this->column(self::NOTES, 'object_id')[0]);

		[$type, $options] = $this->column(self::NOTES, 'object_id_prim');
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length']);
	}

	public function testThereIsOneNotePerPairAndWritingASecondReplacesIt(): void {
		$this->assertSame(
			[[['actor_id_prim', 'object_id_prim'], 'social_anote_ao', true]],
			$this->indexesOf(self::NOTES)
		);
	}

	public function testTheNoteColumnHoldsWhatMastodonAllows(): void {
		// 2000 characters is no VARCHAR anything could index, and nothing ever
		// searches by a note
		$this->assertSame(Types::TEXT, $this->column(self::NOTES, 'note')[0]);
		$this->assertSame(2000, AccountRelationService::MAX_NOTE);
	}

	public function testAnExpiryRowThatExpiresAtNothingCannotBeWritten(): void {
		// no expiry is no row; a nullable column would make "permanent" and
		// "expired in 1970" the same write
		[$type, $options] = $this->column(self::MUTE_EXPIRY, 'expires_at');

		$this->assertSame(Types::DATETIME, $type);
		$this->assertTrue($options['notnull']);
	}

	public function testThereIsOneExpiryPerMute(): void {
		// the timeline join reads it by (viewer, muted account), and re-muting
		// with another duration has to move the expiry rather than add one
		$this->assertSame(
			[[['actor_id_prim', 'object_id_prim'], 'social_mexp_ao', true]],
			$this->indexesOf(self::MUTE_EXPIRY)
		);
	}

	public function testEveryTableCarriesAnAutoincrementKey(): void {
		foreach ([self::DOMAIN_BLOCKS, self::NOTES, self::MUTE_EXPIRY] as $table) {
			$this->assertSame(['id'], $this->primaryKeyOf($table), $table);

			[$type, $options] = $this->column($table, 'id');
			$this->assertSame(Types::BIGINT, $type, $table);
			$this->assertTrue($options['autoincrement'], $table);
		}
	}
}
