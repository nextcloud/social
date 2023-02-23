<?php

declare(strict_types=1);

/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2023, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20230217000002 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_cache_doc')) {
			$table = $schema->getTable('social_cache_doc');

			if (!$table->hasColumn('nid')) {
				$table->addColumn(
					'nid', Types::BIGINT,
					[
						'autoincrement' => true,
						'notnull' => true,
						'length' => 14,
						'unsigned' => true,
					]
				);
				$table->setPrimaryKey(['nid']);
			}

			if (!$table->hasColumn('account')) {
				$table->addColumn(
					'account', Types::STRING,
					[
						'notnull' => true,
						'length' => 127,
					]
				);
			}

			if (!$table->hasColumn('blurhash')) {
				$table->addColumn(
					'blurhash', Types::STRING,
					[
						'notnull' => true,
						'length' => 63,
						'default' => ''
					]
				);
			}

			if (!$table->hasColumn('description')) {
				$table->addColumn(
					'description', Types::TEXT,
					[
						'notnull' => true,
						'default' => ''
					]
				);
			}
		}

		return $schema;
	}
}
