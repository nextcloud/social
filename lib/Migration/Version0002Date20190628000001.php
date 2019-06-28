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
 * Class Version0002Date20190226000001
 *
 * @package OCA\Social\Migration
 */
class Version0002Date20190628000001 extends SimpleMigrationStep {


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
		if ($schema->hasTable('social_a2_stream')) {
			$table = $schema->getTable('social_a2_stream');
			if (!$table->hasColumn('subtype')) {
				$table->addColumn(
					'subtype', Type::STRING,
					[
						'notnull' => false,
						'length'  => 31,
					]
				);
			}
		}


		if (!$schema->hasTable('social_a2_likes')) {
			$table = $schema->createTable('social_a2_likes');

			$table->addColumn(
				'id', 'string',
				[
					'notnull' => false,
					'length'  => 1000
				]
			);
			$table->addColumn(
				'id_prim', 'string',
				[
					'notnull' => false,
					'length'  => 128
				]
			);
			$table->addColumn(
				'type', 'string',
				[
					'notnull' => false,
					'length'  => 31,
				]
			);
			$table->addColumn(
				'actor_id', 'string',
				[
					'notnull' => true,
					'length'  => 1000,
				]
			);
			$table->addColumn(
				'object_id', 'string',
				[
					'notnull' => true,
					'length'  => 1000,
				]
			);
			$table->addColumn(
				'creation', 'datetime',
				[
					'notnull' => false,
				]
			);

			$table->setPrimaryKey(['id_prim']);
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
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('social_a2_stream');
		$expr = $qb->expr();
		$qb->where($expr->eq('type', $qb->createNamedParameter('SocialAppNotification')));

		$qb->execute();
	}

}

