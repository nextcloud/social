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
 * An account's three counters, in columns that can be added to.
 *
 * They lived in the `details` JSON blob, which meant the only way to change one
 * was to recompute all of them: `AccountService::addLocalActorDetailCount()`
 * ran four aggregate queries — `COUNT(*)` over `social_follow` twice, the
 * pending requests, and a count of the account's public posts through the
 * recipient join — and it ran **on every post written and every follow
 * accepted**. For an account with a million followers that is a million index
 * entries counted so that a number on a profile can go up by one.
 *
 * A column can be incremented. `SET count_followers = count_followers + 1` is
 * one row, atomic, and the same statement on every database this app supports —
 * which is why this is three columns rather than a read-modify-write of the
 * JSON, whose lost updates under concurrent follows would be exactly the drift
 * nobody could explain.
 *
 * The JSON keeps being what is *read*, so nothing downstream changes: the
 * counters are composed back into `details` when the row is written. What has
 * changed is that they are arrived at by addition rather than by counting, and
 * the counting still happens — in the cron's local-actor walk, which is where
 * drift is reconciled and which is bounded now.
 *
 * `-1` rather than `0` is the "never counted" value: an account whose row was
 * written before this migration has no counter, and zero is a claim ("this
 * account has no followers") where -1 is the absence of one. The reader falls
 * back to the JSON for those until the walk reaches them.
 */
class Version1000Date20260917000002 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_CACHE_ACTORS)) {
			return $schema;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_CACHE_ACTORS);

		foreach (['count_followers', 'count_following', 'count_posts'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::INTEGER, [
					'notnull' => false,
					'default' => -1,
				]);
			}
		}

		return $schema;
	}
}
