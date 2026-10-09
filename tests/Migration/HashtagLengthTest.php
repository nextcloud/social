<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261009500100;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The trends table has to hold a hashtag as long as the table it counts.
 *
 * `social_stream_tag.hashtag` is 127 characters and `social_hashtag.hashtag`
 * was 63, so a tag between the two lengths could be stored on a post and never
 * on its trend: the write failed outright, and since the failure was not
 * caught it ended the trends pass — leaving every hashtag after it in
 * iteration order unwritten, on that run and on every run after it.
 *
 * The squash creates the column at 127 but leaves an existing table's columns
 * as they are, so an instance that created it at 63 needs its own step to be
 * widened: the upgrade from 0.24.1 kept 63 until there was one.
 */
class HashtagLengthTest extends TestCase {
	use ReadsTheSchema;

	public function testATrendHoldsATagAsLongAsAPostCanCarry(): void {
		[$type, $options] = $this->column('social_hashtag', 'hashtag');

		$this->assertSame(Types::STRING, $type);
		$this->assertSame(HashtagsRequest::HASHTAG_MAX_LENGTH, $options['length']);
	}

	/** Both tables hold the same string, and once held it at two widths. */
	public function testBothTablesHoldATagAtTheSameWidth(): void {
		[, $trend] = $this->column('social_hashtag', 'hashtag');
		[, $onAPost] = $this->column('social_stream_tag', 'hashtag');

		$this->assertSame(
			$onAPost['length'],
			$trend['length'],
			'a followable tag could be one no post can carry'
		);
	}

	/** A schema with the trends table as 0.24 and earlier created it. */
	private static function createdAt63(): FakeSchema {
		$schema = new FakeSchema();
		$schema->createTable('social_hashtag')
			->addColumn('hashtag', Types::STRING, ['length' => 63, 'notnull' => false]);

		return $schema;
	}

	public function testATableCreatedAt63IsWidenedOnUpgrade(): void {
		$schema = MigrationReplay::run([Version1000Date20261009500100::class], [], self::createdAt63());

		$this->assertSame(
			HashtagsRequest::HASHTAG_MAX_LENGTH,
			$schema->getTable('social_hashtag')->getColumn('hashtag')->getLength()
		);
	}

	public function testTheSquashAloneLeavesATableCreatedAt63As63(): void {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)],
			self::createdAt63()
		);

		$this->assertSame(63, $schema->getTable('social_hashtag')->getColumn('hashtag')->getLength(), 'the squash only adds what is missing');
	}

	public function testAFreshInstallIsLeftAsTheSquashMadeIt(): void {
		$stand = [IAppConfig::class => $this->createStub(IAppConfig::class)];
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $stand);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261009500100::class], $stand, $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function testAnInstanceWithoutTheTableIsNotTouched(): void {
		$schema = MigrationReplay::run([Version1000Date20261009500100::class]);

		$this->assertFalse($schema->hasTable('social_hashtag'));
	}
}
