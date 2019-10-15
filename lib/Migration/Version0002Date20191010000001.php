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
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCP\AppFramework\QueryException;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


require_once __DIR__ . '/../../appinfo/autoload.php';


/**
 * Class Version0002Date20191010000001
 *
 * @package OCA\Social\Migration
 */
class Version0002Date20191010000001 extends SimpleMigrationStep {


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
		if (!$table->hasColumn('follow_id_prim')) {
			$table->addColumn(
				'follow_id_prim', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
		}
		if (!$table->hasColumn('actor_id_prim')) {
			$table->addColumn(
				'actor_id_prim', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
		}
		if (!$table->hasColumn('object_id_prim')) {
			$table->addColumn(
				'object_id_prim', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
		}

		if (!$schema->hasTable('social_a2_stream_dest')) {
			$table = $schema->createTable('social_a2_stream_dest');

			$table->addColumn(
				'stream_id', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
			$table->addColumn(
				'actor_id', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
			$table->addColumn(
				'type', 'string',
				[
					'notnull' => false,
					'length'  => 15,
				]
			);
			$table->addColumn(
				'subtype', 'string',
				[
					'notnull' => false,
					'length'  => 7,
				]
			);

			$table->addUniqueIndex(['stream_id', 'actor_id', 'type'], 'sat');
			$table->addIndex(['type', 'subtype'], 'ts');
		}


		$table = $schema->getTable('social_a2_stream');
		if (!$table->hasColumn('in_reply_to_prim')) {
			$table->addColumn(
				'in_reply_to_prim', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
		}
		if (!$table->hasColumn('object_id_prim')) {
			$table->addColumn(
				'object_id_prim', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
		}
		if (!$table->hasColumn('attributed_to_prim')) {
			$table->addColumn(
				'attributed_to_prim', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
		}


		$table = $schema->getTable('social_a2_stream_action');
		if (!$table->hasColumn('actor_id_prim')) {
			$table->addColumn(
				'actor_id_prim', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
		}
		if (!$table->hasColumn('stream_id_prim')) {
			$table->addColumn(
				'stream_id_prim', 'string',
				[
					'notnull' => true,
					'length'  => 128,
				]
			);
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

		$this->updateTableStream($schema);
		$this->updateTableFollows($schema);
		$this->updateTableStreamActions($schema);
		$this->fillTableStreamDest($schema);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function updateTableFollows(ISchemaWrapper $schema) {
		if (!$schema->hasTable('social_a2_follows')) {
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
		   ->from('social_a2_follows');

		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$this->updateFollowPrim($data);
		}

		$cursor->closeCursor();
	}

	/**
	 * @param array $data
	 */
	private function updateFollowPrim(array $data) {
		if ($data['follow_id_prim'] !== '') {
			return;
		}

		$update = $this->connection->getQueryBuilder();
		$update->update('social_a2_follows');
		$update->set('follow_id_prim', $update->createNamedParameter(hash('sha512', $data['follow_id'])));
		$update->set('object_id_prim', $update->createNamedParameter(hash('sha512', $data['object_id'])));
		$update->set('actor_id_prim', $update->createNamedParameter(hash('sha512', $data['actor_id'])));

		$expr = $update->expr();
		$update->where($expr->eq('id_prim', $update->createNamedParameter($data['id_prim'])));

		$update->execute();
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function updateTableStreamActions(ISchemaWrapper $schema) {
		if (!$schema->hasTable('social_a2_stream_action')) {
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
		   ->from('social_a2_stream_action');

		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$this->updateStreamActionsPrim($data);
		}

		$cursor->closeCursor();
	}

	/**
	 * @param array $data
	 */
	private function updateStreamActionsPrim(array $data) {
		if ($data['actor_id_prim'] !== '') {
			return;
		}

		$update = $this->connection->getQueryBuilder();
		$update->update('social_a2_stream_action');
		$update->set('stream_id_prim', $update->createNamedParameter(hash('sha512', $data['stream_id'])));
		$update->set('actor_id_prim', $update->createNamedParameter(hash('sha512', $data['actor_id'])));

		$expr = $update->expr();
		$update->where($expr->eq('stream_id', $update->createNamedParameter($data['stream_id'])));
		$update->andWhere($expr->eq('actor_id', $update->createNamedParameter($data['actor_id'])));

		$update->execute();
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function updateTableStream(ISchemaWrapper $schema) {
		if (!$schema->hasTable('social_a2_stream')) {
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('id_prim', 'object_id', 'attributed_to', 'in_reply_to')
		   ->from('social_a2_stream');

		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$this->updateStreamPrim($data);
		}

		$cursor->closeCursor();
	}


	/**
	 * @param array $data
	 */
	private function updateStreamPrim(array $data) {
		$update = $this->connection->getQueryBuilder();
		$update->update('social_a2_stream');
		if ($data['object_id'] !== '') {
			$update->set('object_id_prim', $update->createNamedParameter(hash('sha512', $data['object_id'])));
		}
		if ($data['in_reply_to'] !== '') {
			$update->set(
				'in_reply_to_prim', $update->createNamedParameter(hash('sha512', $data['in_reply_to']))
			);
		}
		$update->set(
			'attributed_to_prim', $update->createNamedParameter(hash('sha512', $data['attributed_to']))
		);

		$expr = $update->expr();
		$update->where($expr->eq('id_prim', $update->createNamedParameter($data['id_prim'])));

		$update->execute();
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillTableStreamDest(ISchemaWrapper $schema) {
		if (!$schema->hasTable('social_a2_stream')) {
			return;
		}

		try {
			$streamRequest = \OC::$server->query(StreamRequest::class);
			$streamDestRequest = \OC::$server->query(StreamDestRequest::class);
			$streamTagsRequest = \OC::$server->query(StreamTagsRequest::class);
		} catch (QueryException $e) {
			\OC::$server->getLogger()
						->log(2, 'issue while querying stream* request');

			return;
		}

		$streamDestRequest->emptyStreamDest();
		$streamTagsRequest->emptyStreamTags();
		$streams = $streamRequest->getAll();

		foreach ($streams as $stream) {
			try {
				$streamDestRequest->generateStreamDest($stream);
				$streamTagsRequest->generateStreamTags($stream);
			} catch (Exception $e) {
				\OC::$server->getLogger()
							->log(
								2, '-- ' . get_class($e) . ' - ' . $e->getMessage() . ' - ' . json_encode(
									 $stream
								 )
							);
			}
		}
	}

}
