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
 * A page of somebody's work, to put on a CV.
 *
 * Pixelfed's `Portfolio`, and the feature its photographers ask for. A profile
 * is a feed — a stream of everything somebody posted, newest first, with the
 * follow button and the boosts and the replies around it. A portfolio is the
 * opposite: a page with a title and a sentence, a chosen set of pictures, and
 * nothing else on it. It is what somebody links from a CV, and a profile is
 * not that however it is styled.
 *
 * **One per account.** Not a table of them: a person has one portfolio the way
 * they have one profile, and what would be a second is a collection, which
 * this app already has. The unique index on the account is what says so.
 *
 * **Off until it is turned on.** A row that exists is a page somebody is
 * drafting; `active` is the moment they decide the internet may read it. A
 * portfolio nobody has activated is a 404 to everybody but its owner, rather
 * than an empty page with their name on it.
 *
 * **Public posts only, always.** The page is readable signed out — that is the
 * point of it — so the posts on it are only ever the ones already addressed to
 * the whole internet. That rule lives in the query rather than in a switch,
 * because a switch is a thing somebody can get wrong once and leak a
 * followers-only photograph to a search engine for ever.
 *
 * The display switches are Pixelfed's, minus the ones that mean nothing here
 * (`show_license`, which this app does not store, and `show_link`, which
 * always points at a profile that is always there).
 */
class Version1000Date20260915000012 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CoreRequestBuilder::TABLE_PORTFOLIOS)) {
			return null;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_PORTFOLIOS);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('actor_id', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('actor_id_prim', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		// whether the internet may read it
		$table->addColumn('active', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);
		$table->addColumn('title', Types::STRING, [
			'notnull' => false,
			'length' => 128,
			'default' => '',
		]);
		$table->addColumn('intro', Types::TEXT, [
			'notnull' => false,
		]);
		// 'grid' or 'rows'
		$table->addColumn('layout', Types::STRING, [
			'notnull' => false,
			'length' => 15,
			'default' => 'grid',
		]);
		// 'recent' (the account's newest public pictures) or 'collection'
		$table->addColumn('source', Types::STRING, [
			'notnull' => false,
			'length' => 15,
			'default' => 'recent',
		]);
		$table->addColumn('collection_id', Types::BIGINT, [
			'notnull' => false,
			'default' => 0,
			'length' => 11,
			'unsigned' => true,
		]);
		$table->addColumn('show_captions', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
		$table->addColumn('show_places', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
		$table->addColumn('show_dates', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);
		$table->addColumn('show_avatar', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
		$table->addColumn('creation', Types::DATETIME, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		// one per account, and the index every read of one uses
		$table->addUniqueIndex(['actor_id_prim'], 'social_pfol_a');

		return $schema;
	}
}
