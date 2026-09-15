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
 * Where a story came from, now that stories travel.
 *
 * A story used to be local by definition: it had no ActivityPub identity, was
 * never sent anywhere and never arrived from anywhere, so the row needed no
 * way of saying whose network it belonged to. It travels now — published as an
 * `Add` to the author's followers, withdrawn with a `Delete`, which is what
 * Pixelfed's inbox handles — and a row therefore has to answer two questions
 * it never had to before.
 *
 * `source_id` is the story's ActivityPub id: ours for a local story, the
 * remote one for a story that arrived. Beside it, `source_id_prim` is the hash
 * the unique index is on — an id is a URL and too long to index whole on
 * MySQL — and that index is what makes a story delivered twice one row, which
 * matters more here than elsewhere because a fan-out reaches an instance once
 * per follower on it.
 *
 * `local` says which side wrote it, exactly as it does on `social_stream` and
 * `social_cache_actor`: what may be deleted from the API, whose stories are
 * published outward, and which rows a purge of a remote account takes with it
 * all follow from it.
 *
 * Existing rows are local and get their id minted by the code on first read
 * rather than in a `postSchemaChange` — a story lives a day, so within a day
 * of this migration the question has answered itself.
 */
class Version1000Date20260915000003 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STORIES)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_STORIES);
		$changed = false;

		if (!$table->hasColumn('source_id')) {
			$table->addColumn('source_id', Types::TEXT, [
				'notnull' => false,
				'default' => '',
			]);
			$changed = true;
		}

		if (!$table->hasColumn('source_id_prim')) {
			$table->addColumn('source_id_prim', Types::STRING, [
				'notnull' => false,
				'length' => 32,
				'default' => '',
			]);
			$changed = true;
		}

		if (!$table->hasColumn('local')) {
			$table->addColumn('local', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
			$changed = true;
		}

		if (!$table->hasIndex('social_story_src')) {
			$table->addUniqueIndex(['source_id_prim'], 'social_story_src');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
