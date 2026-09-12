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
 * Link preview cards: one row per post that carries a link, holding what the
 * linked page said about itself (OpenGraph). Cards are derived data — they are
 * never federated — so the row is a cache keyed by the post it belongs to and
 * dies with it.
 */
class Version1000Date20260908000005 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_stream_card')) {
			return null;
		}

		$table = $schema->createTable('social_stream_card');
		$table->addColumn('stream_id_prim', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('url', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('title', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('description', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('image', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('provider_name', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['stream_id_prim']);

		return $schema;
	}
}
