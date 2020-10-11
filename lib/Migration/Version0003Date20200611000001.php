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
		$this->createClient($schema);
		$this->createFollows($schema);
		$this->createHashtags($schema);
		$this->createInstance($schema);
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
				'default' => ''
			]
		);
		$table->addColumn(
			'actor_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'actor_id_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'object_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'object_id_prim', 'string',
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
				'notnull' => false,
				'length'  => 63,
			]
		);
		$table->addColumn(
			'preferred_username', 'string',
			[
				'notnull' => false,
				'length'  => 127,
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
			'summary', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
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
				'default' => ''
			]
		);
		$table->addColumn(
			'actor_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'actor_id_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'object_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'object_id_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'follow_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'follow_id_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
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
				'length'  => 500,
				'default' => ''
			]
		);

		$table->setPrimaryKey(['hashtag']);
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
	private function createStreams(ISchemaWrapper $schema) {
		if ($schema->hasTable('social_3_stream')) {
			return;
		}

		$table = $schema->createTable('social_3_stream');

		$table->addColumn(
			'nid', 'bigint',
			[
				'autoincrement' => true,
				'length'        => 11,
				'unsigned'      => true,
			]
		);
		$table->addColumn(
			'id', 'string',
			[
				'notnull' => false,
				'length'  => 1000
			]
		);
		$table->addColumn(
			'chunk', Type::SMALLINT,
			[
				'default'  => 1,
				'length'   => 1,
				'unsigned' => true
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
				'default' => ''
			]
		);
		$table->addColumn(
			'subtype', Type::STRING,
			[
				'notnull' => false,
				'length'  => 31,
				'default' => ''
			]
		);
		$table->addColumn(
			'to', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'to_array', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'cc', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'bcc', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'content', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'summary', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'published', 'string',
			[
				'notnull' => false,
				'length'  => 31,
				'default' => ''
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
				'default' => ''
			]
		);
		$table->addColumn(
			'attributed_to_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'in_reply_to', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'in_reply_to_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'activity_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'object_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'object_id_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'hashtags', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'details', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'source', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'instances', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'attachments', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'cache', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
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
				'notnull' => false,
				'default' => false
			]
		);
		$table->addColumn(
			'filter_duplicate', 'boolean',
			[
				'notnull' => false,
				'default' => false
			]
		);

		$table->addUniqueIndex(['id_prim']);
		$table->addUniqueIndex(['nid']);
		$table->addIndex(['chunk'], 'chunk');
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
			'nid', 'bigint',
			[
				'autoincrement' => true,
				'length'        => 11,
				'unsigned'      => true,
			]
		);
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
				'default' => ''
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
			'local', 'boolean',
			[
				'notnull' => true,
				'default' => false
			]
		);
		$table->addColumn(
			'following', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'followers', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'inbox', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'shared_inbox', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'outbox', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'featured', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'url', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'preferred_username', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
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
			'icon_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'summary', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'public_key', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'source', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
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

		$table->addUniqueIndex(['id_prim']);
		$table->addUniqueIndex(['nid']);
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
				'notnull' => false,
				'length'  => 128
			]
		);
		$table->addColumn(
			'type', 'string',
			[
				'notnull' => false,
				'length'  => 31,
				'default' => ''
			]
		);
		$table->addColumn(
			'parent_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => '',
			]
		);
		$table->addColumn(
			'media_type', 'string',
			[
				'notnull' => false,
				'length'  => 63,
				'default' => '',
			]
		);
		$table->addColumn(
			'mime_type', 'string',
			[
				'notnull' => false,
				'length'  => 63,
				'default' => ''
			]
		);
		$table->addColumn(
			'url', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'local_copy', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'resized_copy', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'public', 'boolean',
			[
				'notnull' => false,
				'default' => false
			]
		);
		$table->addColumn(
			'error', 'smallint',
			[
				'notnull' => false,
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
				'notnull' => false,
				'length'  => 63,
			]
		);
		$table->addColumn(
			'author', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'activity', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);
		$table->addColumn(
			'instance', Type::TEXT,
			[
				'notnull' => false,
				'length'  => 500,
				'default' => ''
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
			'chunk', Type::SMALLINT,
			[
				'default'  => 1,
				'length'   => 1,
				'unsigned' => true
			]
		);
		$table->addColumn(
			'actor_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'actor_id_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'stream_id', 'string',
			[
				'notnull' => false,
				'length'  => 1000,
				'default' => ''
			]
		);
		$table->addColumn(
			'stream_id_prim', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn('liked', 'boolean', ['default' => false]);
		$table->addColumn('boosted', 'boolean', ['default' => false]);
		$table->addColumn('replied', 'boolean', ['default' => false]);
		$table->addColumn(
			'values', Type::TEXT,
			[
				'notnull' => false,
				'default' => ''
			]
		);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['chunk'], 'chunk_act');
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
			'chunk', Type::SMALLINT,
			[
				'default'  => 1,
				'length'   => 1,
				'unsigned' => true
			]
		);
		$table->addColumn(
			'stream_id', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'actor_id', 'string',
			[
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'type', 'string',
			[
				'notnull' => false,
				'length'  => 15,
				'default' => ''
			]
		);
		$table->addColumn(
			'subtype', 'string',
			[
				'notnull' => false,
				'length'  => 7,
				'default' => ''
			]
		);

		$table->addIndex(['chunk'], 'chunk_dest');
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
				'notnull' => false,
				'length'  => 63
			]
		);
		$table->addColumn(
			'stream_id', 'string',
			[
				'notnull' => false,
				'length'  => 255,
				'default' => ''
			]
		);
		$table->addColumn(
			'type', 'string',
			[
				'notnull' => false,
				'length'  => 31,
				'default' => ''
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
				'notnull' => false,
				'length'  => 128,
				'default' => ''
			]
		);
		$table->addColumn(
			'hashtag', 'string',
			[
				'notnull' => false,
				'length'  => 127,
				'default' => ''
			]
		);

		$table->addUniqueIndex(['stream_id', 'hashtag'], 'sh');
	}

}

