<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
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
use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


/**
 * Class Version0002Date20190313133046
 *
 * @package OCA\Social\Migration
 */
class Version0002Date20190313133046 extends SimpleMigrationStep {


	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_stream_actions')) {
			$table = $schema->createTable('social_stream_actions');
			$table->addColumn(
				'id', Type::INTEGER, [
						'autoincrement' => true,
						'notnull'       => true,
						'length'        => 11,
						'unsigned'      => true
					]
			);
			$table->addColumn(
				'actor_id', 'string', [
							  'notnull' => true,
							  'length'  => 127,
						  ]
			);
			$table->addColumn(
				'stream_id', 'string', [
							   'notnull' => true,
							   'length'  => 1000,
						   ]
			);
			$table->addColumn(
				'values', Type::TEXT, [
							'notnull' => false
						]
			);
			$table->setPrimaryKey(['id']);
		}

		return $schema;
	}

}

