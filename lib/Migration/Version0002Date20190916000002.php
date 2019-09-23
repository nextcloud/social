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
use Exception;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


/**
 * Class Version0002Date20190916000002
 *
 * @package OCA\Social\Migration
 */
class Version0002Date20190916000002 extends SimpleMigrationStep {


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

		$table = $schema->getTable('social_a2_follows');
		if (!$table->hasIndex('afoa')) {
			$table->addUniqueIndex(['accepted', 'follow_id_prim', 'object_id_prim', 'actor_id_prim'], 'afoa');
		}
		if (!$table->hasIndex('aoa')) {
			$table->addUniqueIndex(['accepted', 'object_id_prim', 'actor_id_prim'], 'aoa');
		}

		$table = $schema->getTable('social_a2_stream');
		if (!$table->hasIndex('ipoha')) {
			$table->addUniqueIndex(
				['id_prim', 'published_time', 'object_id_prim', 'hidden_on_timeline', 'attributed_to_prim'],
				'ipoha'
			);
		}
		if (!$table->hasIndex('id_prim')) {
			$table->addUniqueIndex(
				['id_prim'],
				'id_prim'
			);
		}
		if (!$table->hasIndex('object_id_prim')) {
			$table->addUniqueIndex(
				['object_id_prim'],
				'object_id_prim'
			);
		}
		if (!$table->hasIndex('attributed_to_prim')) {
			$table->addUniqueIndex(
				['attributed_to_prim'],
				'attributed_to_prim'
			);
		}

		$table = $schema->getTable('social_a2_cache_actors');
		if (!$table->hasIndex('i')) {
			$table->addUniqueIndex(['id_prim'], 'i');
		}

		$table = $schema->getTable('social_a2_stream_action');
		if (!$table->hasIndex('sa')) {
			$table->addIndex(['stream_id_prim', 'actor_id_prim'], 'sa');
		}

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
	}


}
