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
 * What a moderator has decided about something that is trending.
 *
 * Trending is counted and shown with nobody in the loop, so the first ugly
 * hashtag to catch on does so on the Explore page of every account here, and
 * the only thing an administrator could do about it was wait. Mastodon has
 * nine admin routes for exactly this — approve and reject, for tags, links and
 * statuses — and this app had none of them.
 *
 * **Rejected is what is stored; everything else trends.** The other way round
 * — nothing trends until a moderator approves it — is also a design Mastodon
 * offers, and it is the wrong default here: it would empty the Explore page of
 * every instance on upgrade and leave it empty until somebody found the new
 * panel. What an administrator wants on the day they need this is a way to
 * take one thing down, and that is what a row here is.
 *
 * One table for the three kinds rather than three, because what differs
 * between them is a word: a tag is named by its text, a link by its URL and a
 * status by its id, and all three are "this must not trend". Splitting them
 * would be three tables with identical columns and three queries to keep in
 * step, and the trend reads would each have to learn which one to consult.
 *
 * `ref_prim` is the md5 of the reference, because a URL is longer than an
 * index may be on MySQL and a hashtag is not — the same reason every other
 * `_prim` column in this schema exists.
 */
class Version1000Date20260916000001 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_TREND_REVIEW)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_TREND_REVIEW);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		// 'tag', 'link' or 'status'
		$table->addColumn('kind', Types::STRING, [
			'notnull' => false,
			'length' => 15,
			'default' => '',
		]);
		$table->addColumn('ref', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('ref_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		// false is the row's whole point; true is a decision recorded so that
		// a moderator can see what they have already looked at
		$table->addColumn('approved', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);
		$table->addColumn('moderator', Types::STRING, [
			'notnull' => false,
			'length' => 64,
			'default' => '',
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// one decision per thing, which is what makes deciding twice a change
		// rather than a second row
		$table->addUniqueIndex(['kind', 'ref_prim'], 'social_trrev_kr');

		return $schema;
	}
}
