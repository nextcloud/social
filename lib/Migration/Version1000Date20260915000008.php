<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The subjects an instance says it is about.
 *
 * Explore is trending, and trending on a small instance is four hashtags and a
 * wedding. Pixelfed has `DiscoverCategory` for exactly this: an administrator
 * naming what their instance is for — "Architecture", "Street", "Mosses" — and
 * the hashtags each of those means. It is the difference between an Explore
 * page that looks abandoned and one that looks like somewhere to start.
 *
 * Curated, not computed, and deliberately so. A category is a claim the
 * instance makes about itself, which is not something a trend counter can
 * work out: the tags people here use most are a fact, and the tags this
 * instance would like to be known for are a decision.
 *
 * The hashtags are a JSON array on the row rather than a table of their own.
 * There are a handful of categories on any instance that has them, each naming
 * a handful of tags, nothing joins on them, and the whole set is read at once
 * by the one page that shows it.
 */
class Version1000Date20260915000008 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_DISCOVER_CATS)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_DISCOVER_CATS);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('name', Types::STRING, [
			'notnull' => false,
			'length' => 64,
			'default' => '',
		]);
		$table->addColumn('hashtags', Types::TEXT, [
			'notnull' => false,
			'default' => '',
		]);
		// where it sits on the page; an administrator orders these by what the
		// instance is most about, which is not alphabetical
		$table->addColumn('position', Types::INTEGER, [
			'notnull' => false,
			'default' => 0,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);

		return $schema;
	}
}
