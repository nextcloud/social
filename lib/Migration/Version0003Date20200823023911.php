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
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Types\Type;
use Exception;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


/**
 * Class Version0003Date20200823023911
 *
 * @package OCA\Social\Migration
 */
class Version0003Date20200823023911 extends SimpleMigrationStep {


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
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options
	): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->fixStreamNid($schema);
		$this->fixCacheActorNid($schema);

		$this->createClient($schema);
		$this->createInstance($schema);

		$this->addChunkToTable($schema, 'social_3_stream', '');
		$this->addChunkToTable($schema, 'social_3_stream_act', '_act');
		$this->addChunkToTable($schema, 'social_3_stream_dest', '_dest');

		return $schema;
	}


	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @throws Exception
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		$qb = $this->connection->getQueryBuilder();

		$qb->select('*')
		   ->from('social_3_stream')
		   ->orderBy('creation', 'asc');

		$result = $qb->execute();
		$nid = 0;
		while ($row = $result->fetch()) {
			$nid++;
			if (is_int($row['nid']) and $row['nid'] > 0) {
				continue;
			}
			$update = $this->connection->getQueryBuilder();
			$expr = $update->expr();

			$update->update('social_3_stream');
			$update->set('nid', $update->createNamedParameter($nid));
			$update->where($expr->eq('id_prim', $update->createNamedParameter($row['id_prim'])));
			$update->execute();
		}
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fixStreamNid(ISchemaWrapper $schema) {
		try {
			$table = $schema->getTable('social_3_stream');
		} catch (SchemaException $e) {
			return;
		}

		if ($table->hasColumn('nid')) {
			return;
		}

		$table->addColumn(
			'nid', 'bigint',
			[
				'length'   => 11,
				'unsigned' => true,
				'notnull' => false,
			]
		);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fixCacheActorNid(ISchemaWrapper $schema) {
		try {
			$table = $schema->getTable('social_3_cache_actor');
		} catch (SchemaException $e) {
			return;
		}

		if ($table->hasColumn('nid')) {
			return;
		}

		$table->addColumn(
			'nid', 'bigint',
			[
				'autoincrement'       => true,
				'length'              => 11,
				'unsigned'            => true,
				'customSchemaOptions' => [
					'unique' => true
				]
			]
		);

		$table->addUniqueIndex(['id_prim']);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createClient(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_client')) {
			return;
		}

		$table = $schema->createTable('social_3_client');
		$table->addColumn(
			'id', 'integer',
			[
				'autoincrement' => true,
				'notnull'       => true,
				'length'        => 7,
				'unsigned'      => true,
			]
		);
		$table->addColumn(
			'app_name', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);
		$table->addColumn(
			'app_website', 'string',
			[
				'notnull' => false,
				'length'  => 255,
				'default' => ''
			]
		);
		$table->addColumn(
			'app_redirect_uris', 'text',
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'app_client_id', 'string',
			[
				'notnull' => false,
				'length'  => 63,
				'default' => ''
			]
		);
		$table->addColumn(
			'app_client_secret', 'string',
			[
				'notnull' => false,
				'length'  => 63,
				'default' => ''
			]
		);
		$table->addColumn(
			'app_scopes', 'text',
			[
				'notnull' => false
			]
		);

		$table->addColumn(
			'auth_scopes', 'text',
			[
				'notnull' => false
			]
		);
		$table->addColumn(
			'auth_account', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);
		$table->addColumn(
			'auth_user_id', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);
		$table->addColumn(
			'auth_code', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);
		$table->addColumn(
			'token', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);
		$table->addColumn(
			'last_update', 'datetime',
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'creation', 'datetime',
			[
				'notnull' => false,
			]
		);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['auth_code', 'token', 'app_client_id', 'app_client_secret']);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createInstance(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_instance')) {
			return;
		}

		$table = $schema->createTable('social_3_instance');
		$table->addColumn(
			'local', 'smallint',
			[
				'notnull'  => false,
				'length'   => 1,
				'default'  => 0,
				'unsigned' => true
			]
		);
		$table->addColumn(
			'uri', 'string',
			[
				'notnull' => false,
				'length'  => 255,
			]
		);
		$table->addColumn(
			'title', 'string',
			[
				'notnull' => false,
				'length'  => 255,
				'default' => ''
			]
		);
		$table->addColumn(
			'version', 'string',
			[
				'notnull' => false,
				'length'  => 31,
				'default' => ''
			]
		);
		$table->addColumn(
			'short_description', 'text',
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'description', 'text',
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'email', 'string',
			[
				'notnull' => false,
				'length'  => 255,
				'default' => ''
			]
		);
		$table->addColumn(
			'urls', 'text',
			[
				'notnull' => false,
				'default' => '[]'
			]
		);
		$table->addColumn(
			'stats', 'text',
			[
				'notnull' => false,
				'default' => '[]'
			]
		);
		$table->addColumn(
			'usage', 'text',
			[
				'notnull' => false,
				'default' => '[]'
			]
		);
		$table->addColumn(
			'image', 'string',
			[
				'notnull' => false,
				'length'  => 255,
				'default' => ''
			]
		);
		$table->addColumn(
			'languages', 'text',
			[
				'notnull' => false,
				'default' => '[]'
			]
		);
		$table->addColumn(
			'contact', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);
		$table->addColumn(
			'account_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'creation', 'datetime',
			[
				'notnull' => false,
			]
		);

		$table->setPrimaryKey(['uri']);
		$table->addIndex(['local', 'uri', 'account_prim']);
	}


	/**
	 * @param ISchemaWrapper $schema
	 * @param string $tableName
	 * @param string $indexName
	 *
	 * @throws SchemaException
	 */
	private function addChunkToTable(ISchemaWrapper $schema, string $tableName, string $indexName) {
		if (!$schema->hasTable($tableName)) {
			return;
		}

		$table = $schema->getTable($tableName);
		if ($table->hasColumn('chunk')) {
			return;
		}

		$table->addColumn(
			'chunk', Type::SMALLINT,
			[
				'default'  => 1,
				'length'   => 1,
				'unsigned' => true
			]
		);

		if (!$table->hasIndex('chunk' . $indexName)) {
			$table->addIndex(['chunk'], 'chunk' . $indexName);
		}
	}

}

