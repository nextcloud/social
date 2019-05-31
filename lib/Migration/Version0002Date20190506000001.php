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
use DateTime;
use Doctrine\DBAL\Types\Type;
use Exception;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


/**
 * Class Version0002Date20190226000001
 *
 * @package OCA\Social\Migration
 */
class Version0002Date20190506000001 extends SimpleMigrationStep {


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
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options
	): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->createActors($schema);
		$this->createFollows($schema);
		$this->createHashtags($schema);
		$this->createStreams($schema);
		$this->createCacheActors($schema);
		$this->createCacheDocuments($schema);
		$this->createRequestQueue($schema);
		$this->createStreamActions($schema);
		$this->createStreamQueue($schema);

		return $schema;
	}


	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->fillActors($schema);
		$this->fillFollows($schema);
		$this->fillHashtags($schema);
		$this->fillStreams($schema);
		$this->fillCacheActors($schema);
		$this->fillCacheDocuments($schema);
		$this->fillRequestQueue($schema);
		$this->fillStreamActions($schema);
		$this->fillStreamQueue($schema);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createActors(ISchemaWrapper $schema) {
		if ($schema->hasTable(CoreRequestBuilder::TABLE_ACTORS)) {
			return;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_ACTORS);

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
			'user_id', 'string',
			[
				'notnull' => true,
				'length'  => 63,
			]
		);
		$table->addColumn(
			'preferred_username', 'string',
			[
				'notnull' => true,
				'length'  => 127,
			]
		);
		$table->addColumn(
			'name', 'string',
			[
				'notnull' => true,
				'length'  => 127,
			]
		);
		$table->addColumn(
			'summary', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'public_key', Type::TEXT,
			[
				'notnull' => false
			]
		);
		$table->addColumn(
			'private_key', Type::TEXT,
			[
				'notnull' => false
			]
		);
		$table->addColumn(
			'avatar_version', 'integer',
			[
				'notnull' => false,
				'length'  => 2,
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


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createFollows(ISchemaWrapper $schema) {

		if ($schema->hasTable(CoreRequestBuilder::TABLE_FOLLOWS)) {
			return;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_FOLLOWS);

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
			'follow_id', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'accepted', 'boolean',
			[
				'notnull' => true,
				'default' => false
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


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createHashtags(ISchemaWrapper $schema) {
		if ($schema->hasTable(CoreRequestBuilder::TABLE_HASHTAGS)) {
			return;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_HASHTAGS);
		$table->addColumn(
			'hashtag', 'string',
			[
				'notnull' => false,
				'length'  => 63
			]
		);
		$table->addColumn(
			'trend', 'string',
			[
				'notnull' => false,
				'length'  => 500
			]
		);

		$table->setPrimaryKey(['hashtag']);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createStreams(ISchemaWrapper $schema) {
		if ($schema->hasTable(CoreRequestBuilder::TABLE_STREAMS)) {
			return;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_STREAMS);

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
				'notnull' => true,
				'length'  => 31,
			]
		);
		$table->addColumn(
			'to', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'to_array', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'cc', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'bcc', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'content', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'summary', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'published', 'string',
			[
				'notnull' => true,
				'length'  => 31,
			]
		);
		$table->addColumn(
			'published_time', 'datetime',
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'attributed_to', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'in_reply_to', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'activity_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'object_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'hashtags', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'source', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'instances', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'attachments', Type::TEXT,
			[
				'notnull' => false
			]
		);
		$table->addColumn(
			'cache', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'creation', 'datetime',
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'local', 'boolean',
			[
				'notnull' => true,
				'default' => false
			]
		);
		$table->addColumn(
			'hidden_on_timeline', 'boolean',
			[
				'notnull' => true,
				'default' => false
			]
		);

		$table->setPrimaryKey(['id_prim']);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createCacheActors(ISchemaWrapper $schema) {
		if ($schema->hasTable(CoreRequestBuilder::TABLE_CACHE_ACTORS)) {
			return;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_CACHE_ACTORS);
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
				'notnull' => true,
				'length'  => 31,
			]
		);
		$table->addColumn(
			'account', 'string',
			[
				'notnull' => true,
				'length'  => 127,
			]
		);
		$table->addColumn(
			'local', 'boolean',
			[
				'notnull' => true,
				'default' => false
			]
		);
		$table->addColumn(
			'following', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'followers', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'inbox', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'shared_inbox', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'outbox', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'featured', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'url', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'preferred_username', 'string',
			[
				'notnull' => true,
				'length'  => 127
			]
		);
		$table->addColumn(
			'name', 'string',
			[
				'notnull' => true,
				'length'  => 127
			]
		);
		$table->addColumn(
			'icon_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000
			]
		);
		$table->addColumn(
			'summary', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'public_key', Type::TEXT,
			[
				'notnull' => false
			]
		);
		$table->addColumn(
			'source', Type::TEXT,
			[
				'notnull' => true,
				'length'  => 3000,
			]
		);
		$table->addColumn(
			'details', 'string',
			[
				'notnull' => false,
				'length'  => 3000,
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


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createCacheDocuments(ISchemaWrapper $schema) {
		if ($schema->hasTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)) {
			return;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS);
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
				'notnull' => true,
				'length'  => 128
			]
		);
		$table->addColumn(
			'type', 'string',
			[
				'notnull' => true,
				'length'  => 31,
			]
		);
		$table->addColumn(
			'parent_id', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'media_type', 'string',
			[
				'notnull' => true,
				'length'  => 63,
			]
		);
		$table->addColumn(
			'mime_type', 'string',
			[
				'notnull' => true,
				'length'  => 63,
			]
		);
		$table->addColumn(
			'url', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'local_copy', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'public', 'boolean',
			[
				'notnull' => true,
				'default' => false
			]
		);
		$table->addColumn(
			'error', 'smallint',
			[
				'notnull' => true,
				'length'  => 1,
			]
		);
		$table->addColumn(
			'creation', 'datetime',
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'caching', 'datetime',
			[
				'notnull' => false,
			]
		);

		$table->setPrimaryKey(['id_prim']);
//		$table->addUniqueIndex(['url'], 'unique_url');
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createRequestQueue(ISchemaWrapper $schema) {
		if ($schema->hasTable(CoreRequestBuilder::TABLE_REQUEST_QUEUE)) {
			return;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_REQUEST_QUEUE);
		$table->addColumn(
			'id', 'bigint',
			[
				'autoincrement' => true,
				'notnull'       => true,
				'length'        => 11,
				'unsigned'      => true,
			]
		);
		$table->addColumn(
			'token', 'string',
			[
				'notnull' => true,
				'length'  => 63,
			]
		);
		$table->addColumn(
			'author', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'activity', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'instance', Type::TEXT,
			[
				'notnull' => true,
				'length'  => 500,
			]
		);
		$table->addColumn(
			'priority', 'smallint',
			[
				'notnull' => false,
				'length'  => 1,
				'default' => 0,
			]
		);
		$table->addColumn(
			'status', 'smallint',
			[
				'notnull' => false,
				'length'  => 1,
				'default' => 0,
			]
		);
		$table->addColumn(
			'tries', 'smallint',
			[
				'notnull' => false,
				'length'  => 2,
				'default' => 0,
			]
		);
		$table->addColumn(
			'last', 'datetime',
			[
				'notnull' => false,
			]
		);

		$table->setPrimaryKey(['id']);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createStreamActions(ISchemaWrapper $schema) {
		if ($schema->hasTable(CoreRequestBuilder::TABLE_STREAM_ACTIONS)) {
			return;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_STREAM_ACTIONS);

		$table->addColumn(
			'id', Type::INTEGER,
			[
				'autoincrement' => true,
				'notnull'       => true,
				'length'        => 11,
				'unsigned'      => true
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
			'stream_id', 'string',
			[
				'notnull' => true,
				'length'  => 1000,
			]
		);
		$table->addColumn(
			'values', Type::TEXT,
			[
				'notnull' => false
			]
		);

		$table->setPrimaryKey(['id']);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createStreamQueue(ISchemaWrapper $schema) {
		if ($schema->hasTable(CoreRequestBuilder::TABLE_STREAM_QUEUE)) {
			return;
		}

		$table = $schema->createTable(CoreRequestBuilder::TABLE_STREAM_QUEUE);
		$table->addColumn(
			'id', 'bigint',
			[
				'autoincrement' => true,
				'notnull'       => true,
				'length'        => 11,
				'unsigned'      => true,
			]
		);
		$table->addColumn(
			'token', 'string',
			[
				'notnull' => true,
				'length'  => 63,
			]
		);
		$table->addColumn(
			'stream_id', 'string',
			[
				'notnull' => true,
				'length'  => 255,
			]
		);
		$table->addColumn(
			'type', 'string',
			[
				'notnull' => true,
				'length'  => 31,
			]
		);
		$table->addColumn(
			'status', 'smallint',
			[
				'notnull' => false,
				'length'  => 1,
				'default' => 0,
			]
		);
		$table->addColumn(
			'tries', 'smallint',
			[
				'notnull' => false,
				'length'  => 2,
				'default' => 0,
			]
		);
		$table->addColumn(
			'last', 'datetime',
			[
				'notnull' => false,
			]
		);
		$table->setPrimaryKey(['id']);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillActors(ISchemaWrapper $schema) {
		$this->duplicateTable(
			$schema, 'social_server_actors', CoreRequestBuilder::TABLE_ACTORS,
			[
				'id',
				'id_prim',
				'user_id',
				'preferred_username',
				'name',
				'summary',
				'public_key',
				'private_key',
				'avatar_version',
				'creation'
			]
		);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillFollows(ISchemaWrapper $schema) {
		$this->duplicateTable(
			$schema, 'social_server_follows', CoreRequestBuilder::TABLE_FOLLOWS,
			[
				'id',
				'id_prim',
				'type',
				'actor_id',
				'object_id',
				'follow_id',
				'accepted',
				'creation'
			]
		);
	}

	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillHashtags(ISchemaWrapper $schema) {
		$this->duplicateTable(
			$schema, 'social_server_hashtags', CoreRequestBuilder::TABLE_HASHTAGS,
			[
				'hashtag',
				'trend'
			]
		);


//		if (!$schema->hasTable('social_server_hashtags')) {
//			return;
//		}
//
//		$qb = $this->connection->getQueryBuilder();
//		$qb->select('*')
//		   ->from('social_server_hashtags');
//
//		$cursor = $qb->execute();
//		while ($data = $cursor->fetch()) {
//			$insert = $this->connection->getQueryBuilder();
//			$insert->insert(CoreRequestBuilder::TABLE_HASHTAGS);
//
//			$insert->setValue(
//				'hashtag', $insert->createNamedParameter($this->get('hashtag', $data, ''))
//			)
//				   ->setValue(
//					   'trend', $insert->createNamedParameter($this->get('trend', $data, ''))
//				   );
//
//			$insert->execute();
//		}
//
//		$cursor->closeCursor();
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillStreams(ISchemaWrapper $schema) {
		$this->duplicateTable(
			$schema, 'social_server_notes', CoreRequestBuilder::TABLE_STREAMS,
			[
				'id',
				'id_prim',
				'type',
				'to',
				'to_array',
				'cc',
				'bcc',
				'content',
				'summary',
				'published',
				'published_time',
				'attributed_to',
				'in_reply_to',
				'activity_id',
				'object_id',
				'hashtags',
				'source',
				'instances',
				'attachments',
				'cache',
				'creation',
				'local'
			]
		);
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillCacheActors(ISchemaWrapper $schema) {
		$this->duplicateTable(
			$schema, 'social_cache_actors', CoreRequestBuilder::TABLE_CACHE_ACTORS,
			[
				'id',
				'id_prim',
				'type',
				'account',
				'local',
				'following',
				'followers',
				'inbox',
				'shared_inbox',
				'outbox',
				'featured',
				'url',
				'preferred_username',
				'name',
				'icon_id',
				'summary',
				'public_key',
				'source',
				'details',
				'creation'
			]
		);
	}

	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillCacheDocuments(ISchemaWrapper $schema) {
		$this->duplicateTable(
			$schema, 'social_cache_documents', CoreRequestBuilder::TABLE_CACHE_DOCUMENTS,
			[
				'id',
				'id_prim',
				'type',
				'parent_id',
				'media_type',
				'mime_type',
				'url',
				'local_copy',
				'public',
				'error',
				'creation',
				'caching'
			]
		);

	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillRequestQueue(ISchemaWrapper $schema) {
		$this->duplicateTable(
			$schema, 'social_request_queue', CoreRequestBuilder::TABLE_REQUEST_QUEUE,
			[
				'id',
				'token',
				'author',
				'activity',
				'instance',
				'priority',
				'status',
				'tries',
				'last'
			]
		);
	}


	private function fillStreamActions(ISchemaWrapper $schema) {
		$this->duplicateTable(
			$schema, 'social_stream_actions', CoreRequestBuilder::TABLE_STREAM_ACTIONS,
			[
				'id',
				'actor_id',
				'stream_id',
				'values'
			]
		);
	}


	private function fillStreamQueue(ISchemaWrapper $schema) {
		$this->duplicateTable(
			$schema, 'social_queue_stream', CoreRequestBuilder::TABLE_STREAM_QUEUE,
			[
				'id',
				'token',
				'stream_id',
				'type',
				'status',
				'tries',
				'last'
			]
		);
	}


	/**
	 * @param string $k
	 * @param array $arr
	 * @param string $default
	 *
	 * @return string
	 */
	private function get(string $k, array $arr, string $default = ''): string {
		if ($arr === null) {
			return $default;
		}

		if (!array_key_exists($k, $arr)) {
			$subs = explode('.', $k, 2);
			if (sizeof($subs) > 1) {
				if (!array_key_exists($subs[0], $arr)) {
					return $default;
				}

				$r = $arr[$subs[0]];
				if (!is_array($r)) {
					return $default;
				}

				return $this->get($subs[1], $r, $default);
			} else {
				return $default;
			}
		}

		if ($arr[$k] === null || !is_string($arr[$k]) && (!is_int($arr[$k]))) {
			return $default;
		}

		return (string)$arr[$k];
	}


	/**
	 * @param ISchemaWrapper $schema
	 * @param string $source
	 * @param string $dest
	 * @param array $fields
	 */
	private function duplicateTable(
		ISchemaWrapper $schema, string $source, string $dest, array $fields
	) {
		if (!$schema->hasTable($source)) {
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')
		   ->from($source);

		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$this->insertInto($dest, $fields, $data);
		}

		$cursor->closeCursor();
	}


	/**
	 * @param string $table
	 * @param array $fields
	 * @param array $data
	 *
	 * @throws Exception
	 */
	private function insertInto(string $table, array $fields, array $data) {
		$insert = $this->connection->getQueryBuilder();
		$insert->insert($table);

		foreach ($fields as $field) {
			$value = $this->get($field, $data, '');
			if ($field === 'id_prim'
				&& $value === ''
				&& $this->get('id', $data, '') !== '') {
				$value = hash('sha512', $this->get('id', $data, ''));
			}

			if ($field === 'creation' && $value === '') {
				$insert->setValue(
					'creation',
					$insert->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
				);
			} else {
				$insert->setValue(
					$field, $insert->createNamedParameter($value)
				);
			}
		}

		$insert->execute();
	}


}

