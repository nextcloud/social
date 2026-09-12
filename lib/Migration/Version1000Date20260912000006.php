<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The two lists that are about an address rather than an account:
 * `social_access_block`.
 *
 * An IP range this instance answers nothing from, and an email domain it does
 * not hand fediverse accounts to. Mastodon keeps them in two tables with two
 * entity shapes; here they are one table with a `type`, because what differs
 * between them is a severity column and a count, and neither is worth a second
 * table on an instance that will hold tens of these rows.
 */
class Version1000Date20260912000006 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_access_block')) {
			return null;
		}

		$table = $schema->createTable('social_access_block');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		/** 'ip' or 'email_domain' */
		$table->addColumn('type', Types::STRING, [
			'notnull' => true,
			'length' => 15,
		]);
		/** an address or CIDR range, or a hostname */
		$table->addColumn('value', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		/** Mastodon's ip_block severity; only 'no_access' has meaning here */
		$table->addColumn('severity', Types::STRING, [
			'notnull' => false,
			'length' => 31,
		]);
		/** why, for whoever reads the list later */
		$table->addColumn('comment', Types::TEXT, [
			'notnull' => false,
		]);
		/** when it lifts itself, or NULL for never */
		$table->addColumn('expires', Types::DATETIME, [
			'notnull' => false,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// both what makes blocking the same thing twice a replacement rather
		// than a second row, and the index the reads use: a whole list at a
		// time, which is how both are consulted
		$table->addUniqueIndex(['type', 'value'], 'social_acb_tv');

		return $schema;
	}
}
