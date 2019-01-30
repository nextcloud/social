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
 * Class Version0002Date20190108103942
 *
 * @package OCA\Social\Migration
 */
class Version0002Date20190118124201 extends SimpleMigrationStep {


	/** @var IDBConnection */
	private $connection;


	/** @var array */
	public static $editToChar2000 = [
		[CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, 'id'],
		[CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, 'url'],
		[CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, 'local_copy'],

		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'id'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'account'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'following'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'followers'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'inbox'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'shared_inbox'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'outbox'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'featured'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'url'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'preferred_username'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'name'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'icon_id'],

		[CoreRequestBuilder::TABLE_REQUEST_QUEUE, 'author'],

		[CoreRequestBuilder::TABLE_SERVER_FOLLOWS, 'id'],
		[CoreRequestBuilder::TABLE_SERVER_FOLLOWS, 'actor_id'],
		[CoreRequestBuilder::TABLE_SERVER_FOLLOWS, 'object_id'],
		[CoreRequestBuilder::TABLE_SERVER_FOLLOWS, 'follow_id'],

		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'id'],
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'to'],
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'attributed_to'],
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'in_reply_to']
	];


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

		foreach (self::$editToChar2000 as $edit) {
			list($tableName, $field) = $edit;

			$table = $schema->getTable($tableName);
			if ($table->hasColumn($field . '_copy')) {
				continue;
			}

			$table->addColumn($field . '_copy', Type::TEXT, ['notnull' => false]);
		}

		return $schema;	}


	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		foreach (self::$editToChar2000 as $edit) {
			list($tableName, $field) = $edit;

			$qb = $this->connection->getQueryBuilder();
			$qb->update($tableName)
			   ->set($field . '_copy', $field)
			   ->execute();
		}
	}

}

