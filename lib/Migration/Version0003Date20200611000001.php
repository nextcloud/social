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
use Doctrine\DBAL\Types\Type;
use Exception;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


/**
 * Class Version0003Date20200611000001
 *
 * @package OCA\Social\Migration
 */
class Version0003Date20200611000001 extends SimpleMigrationStep {


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

		$this->createActions($schema);
		$this->createActors($schema);
		$this->createCacheActors($schema);
		$this->createCacheDocuments($schema);
		$this->createFollows($schema);
		$this->createHashtags($schema);
		$this->createRequestQueue($schema);
		$this->createStreams($schema);
		$this->createStreamActions($schema);
		$this->createStreamDest($schema);
		$this->createStreamQueue($schema);
		$this->createStreamTags($schema);

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


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createActions(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_action')) {
			return;
		}

		$table = $schema->createTable('social_3_action');
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
			'actor_id_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
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
			'object_id_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
			]
		);
		$table->addColumn(
			'creation', 'datetime',
			[
				'notnull' => false,
			]
		);

		$table->setPrimaryKey(['id_prim']);
		$table->addUniqueIndex(['actor_id_prim', 'object_id_prim', 'type'], 'aot');
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createActors(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_actor')) {
			return;
		}

		$table = $schema->createTable('social_3_actor');

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
		if ($schema->hasTable('social_3_follow')) {
			return;
		}

		$table = $schema->createTable('social_3_follow');
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
			'actor_id_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
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
			'object_id_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
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
			'follow_id_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
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
		$table->addUniqueIndex(['accepted', 'follow_id_prim', 'object_id_prim', 'actor_id_prim'], 'afoa');
		$table->addUniqueIndex(['accepted', 'object_id_prim', 'actor_id_prim'], 'aoa');
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createHashtags(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_hashtag')) {
			return;
		}

		$table = $schema->createTable('social_3_hashtag');
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
		if ($schema->hasTable('social_3_stream')) {
			return;
		}

		$table = $schema->createTable('social_3_stream');

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
			'subtype', Type::STRING,
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
			'to_array', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'cc', Type::TEXT,
			[
				'notnull' => true
			]
		);
		$table->addColumn(
			'bcc', Type::TEXT,
			[
				'notnull' => true
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
			'attributed_to_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
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
			'in_reply_to_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
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
			'object_id_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
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
			'details', Type::TEXT,
			[
				'notnull' => true
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
			'filter_duplicate', 'boolean',
			[
				'notnull' => true,
				'default' => false
			]
		);

		$table->setPrimaryKey(['id_prim']);
		$table->addUniqueIndex(
			[
				'id_prim',
				'published_time',
				'object_id_prim',
				'filter_duplicate',
				'attributed_to_prim'
			],
			'ipoha'
		);
		$table->addIndex(['object_id_prim'], 'object_id_prim');
		$table->addIndex(['in_reply_to_prim'], 'in_reply_to_prim');
		$table->addIndex(['attributed_to_prim'], 'attributed_to_prim');
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createCacheActors(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_cache_actor')) {
			return;
		}

		$table = $schema->createTable('social_3_cache_actor');
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
				'notnull' => true
			]
		);
		$table->addColumn(
			'details', Type::TEXT,
			[
				'notnull' => false
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
		if ($schema->hasTable('social_3_cache_doc')) {
			return;
		}

		$table = $schema->createTable('social_3_cache_doc');
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
			'resized_copy', Type::TEXT,
			[
				'notnull' => true
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
		if ($schema->hasTable('social_3_req_queue')) {
			return;
		}

		$table = $schema->createTable('social_3_req_queue');
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
		if ($schema->hasTable('social_3_stream_act')) {
			return;
		}

		$table = $schema->createTable('social_3_stream_act');

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
			'actor_id_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
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
			'stream_id_prim', 'string',
			[
				'notnull' => true,
				'length'  => 128,
			]
		);
		$table->addColumn('liked', 'boolean');
		$table->addColumn('boosted', 'boolean');
		$table->addColumn('replied', 'boolean');
		$table->addColumn(
			'values', Type::TEXT,
			[
				'notnull' => false
			]
		);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['stream_id_prim', 'actor_id_prim'], 'sa');
	}


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createStreamDest(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_stream_dest')) {
			return;
		}

		$table = $schema->createTable('social_3_stream_dest');
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


	/**
	 * @param ISchemaWrapper $schema
	 */
	private function createStreamQueue(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_stream_queue')) {
			return;
		}

		$table = $schema->createTable('social_3_stream_queue');
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
	private function createStreamTags(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_stream_tag')) {
			return;
		}

		$table = $schema->createTable('social_3_stream_tag');

		$table->addColumn(
			'stream_id', 'string',
			[
				'notnull' => true,
				'length'  => 128,
			]
		);
		$table->addColumn(
			'hashtag', 'string',
			[
				'notnull' => false,
				'length'  => 127,
			]
		);

		$table->addUniqueIndex(['stream_id', 'hashtag'], 'sh');
	}

}

