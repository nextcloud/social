<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The fourteen tables this app was built on, and the rules the rest of the
 * schema follows from them.
 *
 * These were the initial migration's, and this asked what that one step
 * produced. The step is one paragraph of the squash now, and the questions are
 * the same ones — an actor is keyed by the hash of its ActivityPub id, a table
 * with a numeric key keeps that hash unique — so they are asked of the schema.
 */
class CoreSchemaTest extends TestCase {
	use ReadsTheSchema;

	/** The tables the app was built on, before anything was added to it. */
	private const TABLES = [
		'social_action',
		'social_actor',
		'social_cache_actor',
		'social_cache_doc',
		'social_client',
		'social_follow',
		'social_hashtag',
		'social_instance',
		'social_req_queue',
		'social_stream',
		'social_stream_act',
		'social_stream_dest',
		'social_stream_queue',
		'social_stream_tag',
	];

	public function testTheAppStillInstallsTheFourteenTablesItWasBuiltOn(): void {
		foreach (self::TABLES as $table) {
			$this->assertNotEmpty($this->columnNames($table), $table);
		}
	}

	public function testEveryTableItInstallsIsStillOneTheCodeNames(): void {
		$named = [];
		foreach ((new ReflectionClass(CoreRequestBuilder::class))->getConstants() as $name => $value) {
			if (str_starts_with($name, 'TABLE_')) {
				$named[] = $value;
			}
		}

		foreach (self::TABLES as $table) {
			// a table installed here that nothing names any more would be dead
			// weight on every fresh install, which is what the 14 dropped
			// `social_3_*` tables were
			$this->assertContains($table, $named, $table);
		}
	}

	public function testTheActorTablesAreKeyedByTheHashedActivityPubId(): void {
		// `id_prim` is the md5 of the ActivityPub id: the id itself is a URL,
		// too long for an indexed key on MySQL, and every join in lib/Db uses
		// the hashed form
		foreach (['social_actor', 'social_action', 'social_follow'] as $table) {
			$this->assertSame(['id_prim'], $this->primaryKeyOf($table), $table);
			$this->assertContains('id_prim', $this->columnNames($table), $table);
		}
	}

	public function testTheTablesWithNumericIdsKeepTheHashedIdUnique(): void {
		// `nid` is an autoincrement number, so the hashed ActivityPub id needs
		// a unique index of its own or the same object could be stored twice
		foreach (['social_stream', 'social_cache_actor'] as $table) {
			$this->assertSame(['nid'], $this->primaryKeyOf($table), $table);
			$this->assertContains([['id_prim'], null, true], $this->indexesOf($table), $table);
		}

		$this->assertSame(['nid'], $this->primaryKeyOf('social_cache_doc'));
	}

	/**
	 * The two side tables with the highest insert rate in the app.
	 *
	 * They shipped without a key of their own and were given an autoincrement
	 * one later, which is what lets a row be addressed at all.
	 */
	public function testTheTwoSideTablesEndedUpWithAKeyAndTheirOwnIndexes(): void {
		foreach (['social_stream_dest', 'social_stream_tag'] as $table) {
			$this->assertSame(['id'], $this->primaryKeyOf($table), $table);
			$this->assertNotEmpty($this->indexesOf($table), $table);
		}
	}

	public function testEveryTableIsAddressableByAPrimaryKey(): void {
		foreach (self::TABLES as $table) {
			$this->assertNotEmpty($this->primaryKeyOf($table), $table);
		}
	}

	public function testTheCacheTablesCarryTheColumnsTheRepairStepsUsedToAdd(): void {
		foreach (['account', 'meta', 'blurhash', 'description'] as $column) {
			[, $options] = $this->column('social_cache_doc', $column);

			// PostgreSQL refuses a NOT NULL column on a populated table unless
			// it has a default; MySQL quietly invents one
			if ($options['notnull'] ?? false) {
				$this->assertArrayHasKey('default', $options, $column);
			}
		}

		$this->assertContains('details_update', $this->columnNames('social_cache_actor'));
		$this->assertContains('visibility', $this->columnNames('social_stream'));
	}
}
