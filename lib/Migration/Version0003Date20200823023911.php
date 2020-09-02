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
		$this->createClientAuth($schema);
		$this->createClientToken($schema);
		$this->createInstance($schema);

		$this->addChunkToTable($schema, 'social_3_stream', '');
		$this->addChunkToTable($schema, 'social_3_stream_act', '_act');
		$this->addChunkToTable($schema, 'social_3_stream_dest', '_dest');

		return $schema;
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
				'autoincrement'       => true,
				'length'              => 11,
				'unsigned'            => true,
				'customSchemaOptions' => [
					'unique' => true
				]
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
			'name', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);
		$table->addColumn(
			'website', 'string',
			[
				'notnull' => false,
				'length'  => 255,
				'default' => ''
			]
		);
		$table->addColumn(
			'scopes', 'string',
			[
				'notnull' => false,
				'default' => '255'
			]
		);
		$table->addColumn(
			'redirect_uris', 'text',
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'client_id', 'string',
			[
				'notnull' => false,
				'length'  => 63,
				'default' => ''
			]
		);
		$table->addColumn(
			'client_secret', 'string',
			[
				'notnull' => false,
				'length'  => 63,
				'default' => ''
			]
		);
		$table->addColumn(
			'creation', 'datetime',
			[
				'notnull' => false,
			]
		);

		$table->setPrimaryKey(['id']);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createClientAuth(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_client_auth')) {
			return;
		}

		$table = $schema->createTable('social_3_client_auth');
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
			'client_id', 'integer',
			[
				'notnull'  => false,
				'length'   => 7,
				'unsigned' => true
			]
		);
		$table->addColumn(
			'scopes', 'text',
			[
				'notnull' => false
			]
		);
		$table->addColumn(
			'account', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);
		$table->addColumn(
			'user_id', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);
		$table->addColumn(
			'code', 'string',
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
		$table->addIndex(['client_id']);
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
	 */
	private function createClientToken(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_client_token')) {
			return;
		}

		$table = $schema->createTable('social_3_client_token');
		$table->addColumn(
			'id', 'integer',
			[
				'autoincrement' => true,
				'notnull'       => true,
				'length'        => 11,
				'unsigned'      => true
			]
		);
		$table->addColumn(
			'auth_id', 'integer',
			[
				'notnull'  => false,
				'length'   => 7,
				'unsigned' => true
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
			'scopes', 'text',
			[
				'notnull' => false
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
		$table->addIndex(['token', 'auth_id']);
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

