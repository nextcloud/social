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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_starter_pack.member_count`: how many members a pack has, so the
 * index of packs is drawn without reading and decoding every member list.
 * The packs already read in are counted from their `members`; run again,
 * it finds none left to count.
 */
class Version1000Date20261016000030 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STARTER_PACKS)) {
			return null;
		}
		$table = $schema->getTable(CoreRequestBuilder::TABLE_STARTER_PACKS);
		if ($table->hasColumn('member_count')) {
			return null;
		}

		$table->addColumn('member_count', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);

		return $schema;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->connection->getQueryBuilder();
		$select->select('id', 'members')
			->from(CoreRequestBuilder::TABLE_STARTER_PACKS)
			->where($select->expr()->eq('member_count', $select->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$cursor = $select->executeQuery();
		$counts = [];
		while ($row = $cursor->fetch()) {
			$members = json_decode((string)($row['members'] ?? ''), true);
			if (is_array($members) && $members !== []) {
				$counts[(int)$row['id']] = count($members);
			}
		}
		$cursor->closeCursor();

		foreach ($counts as $id => $count) {
			$update = $this->connection->getQueryBuilder();
			$update->update(CoreRequestBuilder::TABLE_STARTER_PACKS)
				->set('member_count', $update->createNamedParameter($count, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}
	}
}
