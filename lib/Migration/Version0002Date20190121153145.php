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
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Types\Type;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


/**
 * Class Version0001Date20190121153145
 *
 * @package OCA\Social\Migration
 */
class Version0002Date20190121153145 extends SimpleMigrationStep {


	/** @var IDBConnection */
	private $connection;


	/**
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}


	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return ISchemaWrapper
	 * @throws SchemaException
	 * @throws DBALException
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options
	): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable(CoreRequestBuilder::TABLE_SERVER_NOTES);
		if (!$table->hasColumn('cache')) {
			$table->addColumn('cache', Type::TEXT, ['notnull' => false]);
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_SERVER_NOTES);
		if (!$table->hasColumn('activity_id')) {
			$table->addColumn('activity_id', Type::STRING, ['notnull' => false, 'length' => 255]);
		}

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_QUEUE_STREAM)) {
			$table = $schema->createTable(CoreRequestBuilder::TABLE_QUEUE_STREAM);
			$table->addColumn(
				'id', 'bigint', [
						'autoincrement' => true,
						'notnull'       => true,
						'length'        => 11,
						'unsigned'      => true,
					]
			);
			$table->addColumn(
				'token', 'string', [
						   'notnull' => true,
						   'length'  => 63,
					   ]
			);
			$table->addColumn(
				'stream_id', 'string', [
							   'notnull' => true,
							   'length'  => 255,
						   ]
			);
			$table->addColumn(
				'type', 'string', [
						  'notnull' => true,
						  'length'  => 31,
					  ]
			);
			$table->addColumn(
				'status', 'smallint', [
							'notnull' => false,
							'length'  => 1,
							'default' => 0,
						]
			);
			$table->addColumn(
				'tries', 'smallint', [
						   'notnull' => false,
						   'length'  => 2,
						   'default' => 0,
					   ]
			);
			$table->addColumn(
				'last', 'datetime', [
						  'notnull' => false,
					  ]
			);
			$table->setPrimaryKey(['id']);
		}

		return $schema;
	}


	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}

}

