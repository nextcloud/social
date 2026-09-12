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
 * Gives `social_stream` the five post fields that lived only inside the stored
 * wire object: `tags`, `language`, `updated`, `quote` and `quote_authorization`.
 *
 * All five are user-visible — a client renders the language, the edit stamp and
 * the quote, and every outgoing copy of a post carries its `tag` array — and
 * all five were read by `json_decode`ing the `source` column once per timeline
 * row. Nothing could query, index or sort on them. A language filter, which is
 * the thing most obviously missing, had nothing to filter on at all.
 *
 * What the DDL becomes:
 *
 * - `tags` TEXT, the `tag` array as JSON. See the commit message for why this
 *   is a JSON column rather than a side table: the array is heterogeneous
 *   (Hashtag, Mention and Emoji entries), the one queryable facet of it —
 *   hashtags — already has `social_stream_tag`, and what the column is read for
 *   is re-exporting the post with the same mentions it was published with.
 *   A second side table would be a second source of truth for the hashtags.
 * - `language` VARCHAR(15), a BCP 47 tag, plus the index `social_s_lang`.
 *   `Stream::normalizeLanguage()` cannot emit more than twelve characters
 *   (`xxx-Xxxx-XXX`), so fifteen is the field plus headroom rather than a
 *   guess. The index is the point of the exercise: a language filter is an
 *   equality on a low-cardinality column, and single-column on purpose —
 *   the timelines order on `nid` and each one reaches `social_stream` through
 *   a different set of joins, so a composite would have to pick one of them to
 *   privilege.
 * - `updated` DATETIME, nullable with no default: a post that was never edited
 *   has no edit time, and `NULL` says that where an empty string would not.
 *   `published`/`published_time` set the precedent of a datetime beside the
 *   textual form, except that here only the datetime is kept — the app writes
 *   its own edits as `gmdate('Y-m-d\TH:i:s\Z')` and a remote `updated` in
 *   another offset denotes the same instant, which is all anything reads it for.
 * - `quote` and `quote_authorization` TEXT, both ActivityPub ids.
 *
 * No `_prim` column for either id, deliberately. `id_prim`, `attributed_to_prim`,
 * `object_id_prim` and `in_reply_to_prim` exist because a query filters on those
 * ids by equality — `in_reply_to_prim` is what `getDescendants()` walks. Nothing
 * in the app looks a post up by what it quotes: `QuoteRequestInterface` finds the
 * quoting post by the id the answer names as its instrument, which is `id_prim`,
 * and `quote_authorization` is never a lookup key at all, only a URI remote
 * servers dereference. An md5 column and its index on the largest table in the
 * app is write cost and disk on every insert for a query nobody makes; when one
 * appears — "how many posts quote this" — it is one migration of exactly this
 * shape, which is how `in_reply_to_prim` itself arrived.
 *
 * Every addition is guarded by `hasColumn()`/`hasIndex()`, so a second run asks
 * for nothing: a migration that re-adds an existing column fails the upgrade it
 * is part of. Existing rows get empty columns and are filled in afterwards by
 * the `BackfillStreamPostFields` repair step; until it has run, and for a row
 * it could not decode, `Stream::importFromDatabase()` still falls back to the
 * stored wire object.
 */
class Version1000Date20260912000007 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_stream')) {
			return null;
		}

		$table = $schema->getTable('social_stream');
		$changed = false;

		if (!$table->hasColumn('tags')) {
			$table->addColumn('tags', Types::TEXT, [
				'notnull' => false,
				'default' => '',
			]);
			$changed = true;
		}

		if (!$table->hasColumn('language')) {
			$table->addColumn('language', Types::STRING, [
				'notnull' => false,
				'length' => 15,
				'default' => '',
			]);
			$changed = true;
		}

		if (!$table->hasColumn('updated')) {
			// nullable and no default: a post that was never edited has no
			// edit time, which is not the same fact as "edited at the epoch"
			$table->addColumn('updated', Types::DATETIME, [
				'notnull' => false,
			]);
			$changed = true;
		}

		foreach (['quote', 'quote_authorization'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::TEXT, [
					'notnull' => false,
					'default' => '',
				]);
				$changed = true;
			}
		}

		if ($table->hasColumn('language') && !$table->hasIndex('social_s_lang')) {
			$table->addIndex(['language'], 'social_s_lang');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
