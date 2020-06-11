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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\SchemaException;
use Exception;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


/**
 * Class Version0002Date20190925000001
 *
 * @package OCA\Social\Migration
 */
class Version0002Date20190925000001 extends SimpleMigrationStep {


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

		$table = $schema->getTable('social_a2_stream_act');
		if (!$table->hasColumn('liked')) {
			$table->addColumn('liked', 'boolean');
		}
		if (!$table->hasColumn('boosted')) {
			$table->addColumn('boosted', 'boolean');
		}
		if (!$table->hasColumn('replied')) {
			$table->addColumn('replied', 'boolean');
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
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->fillTableStreamActions($schema);
	}

	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillTableStreamActions(ISchemaWrapper $schema) {

		$start = 0;
		$limit = 1000;
		while (true) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('id', 'actor_id', 'stream_id', 'values')
			   ->from('social_a2_stream_act')
			   ->setMaxResults(1000)
			   ->setFirstResult($start);

			$cursor = $qb->execute();
			$count = 0;
			while ($data = $cursor->fetch()) {
				$count++;

				$this->updateStreamActions($data);
			}
			$cursor->closeCursor();

			$start += $count;
			if ($count < $limit) {
				break;
			}
		}
	}


	/**
	 * @param array $data
	 */
	private function updateStreamActions(array $data) {
		$update = $this->connection->getQueryBuilder();
		$update->update('social_a2_stream_act');

		$id = $data['id'];
		$actorId = $data['actor_id'];
		$streamId = $data['stream_id'];

		$values = json_decode($data['values'], true);
		$liked = (array_key_exists('liked', $values) && ($values['liked'])) ? 1 : 0;
		$boosted = (array_key_exists('boosted', $values) && $values['boosted']) ? 1 : 0;
		$replied = (array_key_exists('replied', $values) && $values['replied']) ? 1 : 0;

		$update->set('actor_id_prim', $update->createNamedParameter(hash('sha512', $actorId)));
		$update->set('stream_id_prim', $update->createNamedParameter(hash('sha512', $streamId)));
		$update->set('liked', $update->createNamedParameter($liked));
		$update->set('boosted', $update->createNamedParameter($boosted));
		$update->set('replied', $update->createNamedParameter($replied));

		$expr = $update->expr();
		$update->where($expr->eq('id', $update->createNamedParameter($id)));
		try {
			$update->execute();
		} catch (UniqueConstraintViolationException $e) {
		}
	}

}
