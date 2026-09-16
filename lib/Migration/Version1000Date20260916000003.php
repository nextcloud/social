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
 * Who may quote a post, and the permissions that have been granted.
 *
 * This app answers a `QuoteRequest` already — FEP-044f, the mechanism Mastodon
 * 4.5 quotes run on — but the answer was derived from one thing: whether the
 * post was addressed to the public collection. An author who wanted their
 * public post quoted by their followers and nobody else, or by nobody at all,
 * had no way to say so, and Mastodon's own client offers exactly that choice on
 * every post it composes.
 *
 * `social_stream.quote_policy` is that choice: `public`, `followers` or
 * `nobody`, and empty for a post written before anybody was asked — which keeps
 * meaning what it meant, the visibility rule. Stored on the post rather than on
 * the account because it is a decision about *this* post; a default for new
 * posts is a client preference and belongs where the composer's other defaults
 * are.
 *
 * `social_quote_grant` is one row per permission this instance has given out:
 * which post was quoted, which post quotes it, who asked, and the
 * `QuoteRequest` the grant answers. The last of those is the reason the table
 * exists at all — **taking a quote back** means sending a `Reject` naming the
 * request that was accepted, and without a row there is nothing to name. It is
 * also what the author's list of "who has quoted this" is read from, including
 * the quotes by servers whose posts never reached anybody here.
 */
class Version1000Date20260916000003 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_STREAM)) {
			$stream = $schema->getTable(CoreRequestBuilder::TABLE_STREAM);
			if (!$stream->hasColumn('quote_policy')) {
				// '' is "nobody was asked", which the visibility rule answers,
				// and is what every post written before this migration is
				$stream->addColumn('quote_policy', Types::STRING, [
					'notnull' => false,
					'length' => 15,
					'default' => '',
				]);
			}
		}

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_QUOTE_GRANTS)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_QUOTE_GRANTS);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			/** the local post that was quoted */
			$table->addColumn('target_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('target_id_prim', Types::STRING, ['notnull' => false, 'length' => 32]);
			/** the post doing the quoting, wherever it lives */
			$table->addColumn('quoting_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('quoting_id_prim', Types::STRING, ['notnull' => false, 'length' => 32]);
			/** who asked, so the Reject knows which inbox to go to */
			$table->addColumn('actor_id', Types::TEXT, ['notnull' => false]);
			/** the QuoteRequest this grant answered: what a revocation names */
			$table->addColumn('request_id', Types::TEXT, ['notnull' => false]);
			/** the stamp that was issued, which the quoting post carries */
			$table->addColumn('authorization', Types::TEXT, ['notnull' => false]);
			$table->addColumn('creation', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			// one grant per (quoted post, quoting post): a request delivered
			// twice — which happens whenever a peer retries — is one row
			$table->addUniqueIndex(['target_id_prim', 'quoting_id_prim'], 'social_qgrant_tq');
		}

		return $schema;
	}
}
