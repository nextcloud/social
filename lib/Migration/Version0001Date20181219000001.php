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
 * Class Version0001Date20181219000001
 *
 * @package OCA\Social\Migration
 */
class Version0001Date20181219000001 extends SimpleMigrationStep {


	/** @var IDBConnection */
	private $connection;


	/** @var array */
	public static $editToText = [
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'source'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'summary'],
		[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'details'],
		[CoreRequestBuilder::TABLE_REQUEST_QUEUE, 'activity'],
		[CoreRequestBuilder::TABLE_SERVER_ACTORS, 'summary'],
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'content'],
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'summary'],
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'instances'],
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'source']
	];

	/** @var array */
	public static $editToChar4000 = [
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'to_array'],
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'cc'],
		[CoreRequestBuilder::TABLE_SERVER_NOTES, 'bcc']
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

		foreach (array_merge(self::$editToText, self::$editToChar4000) as $edit) {
			list($tableName, $field) = $edit;

			$table = $schema->getTable($tableName);
			if ($table->hasColumn($field . '_copy')) {
				continue;
			}

			$table->addColumn($field . '_copy', Type::TEXT, ['notnull' => false]);
		}

		return $schema;
	}


	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {

		foreach (array_merge(self::$editToText, self::$editToChar4000) as $edit) {
			list($tableName, $field) = $edit;

			$qb = $this->connection->getQueryBuilder();
			$qb->update($tableName)
			   ->set($field . '_copy', $field)
			   ->execute();
		}
	}

}

