<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
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
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version0001Date20181204203147 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_server_actors')) {
			$table = $schema->createTable('social_server_actors');
			$table->addColumn('id', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('user_id', 'string', [
				'notnull' => true,
				'length' => 63,
			]);
			$table->addColumn('preferred_username', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('name', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('summary', 'string', [
				'notnull' => true,
				'length' => 3000,
			]);
			$table->addColumn('public_key', 'string', [
				'notnull' => false,
				'length' => 1000,
			]);
			$table->addColumn('private_key', 'string', [
				'notnull' => false,
				'length' => 2000,
			]);
			$table->addColumn('avatar_version', 'integer', [
				'notnull' => false,
				'length' => 2,
			]);
			$table->addColumn('creation', 'datetime', [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('social_server_follows')) {
			$table = $schema->createTable('social_server_follows');
			$table->addColumn('id', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('type', 'string', [
				'notnull' => false,
				'length' => 31,
			]);
			$table->addColumn('actor_id', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('object_id', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('follow_id', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('accepted', 'boolean', [
				'notnull' => true,
				'default' => false
			]);
			$table->addColumn('creation', 'datetime', [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('social_server_notes')) {
			$table = $schema->createTable('social_server_notes');
			$table->addColumn('id', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('type', 'string', [
				'notnull' => true,
				'length' => 31,
			]);
			$table->addColumn('to', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('to_array', 'string', [
				'notnull' => true,
				'length' => 2000,
			]);
			$table->addColumn('cc', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('bcc', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('content', 'string', [
				'notnull' => true,
				'length' => 3000,
			]);
			$table->addColumn('summary', 'string', [
				'notnull' => true,
				'length' => 3000,
			]);
			$table->addColumn('published', 'string', [
				'notnull' => true,
				'length' => 31,
			]);
			$table->addColumn('published_time', 'datetime', [
				'notnull' => false,
			]);
			$table->addColumn('attributed_to', 'string', [
				'notnull' => false,
				'length' => 127,
			]);
			$table->addColumn('in_reply_to', 'string', [
				'notnull' => false,
				'length' => 127,
			]);
			$table->addColumn('source', 'string', [
				'notnull' => true,
				'length' => 3000,
			]);
			$table->addColumn('instances', 'string', [
				'notnull' => true,
				'length' => 3000,
			]);
			$table->addColumn('creation', 'datetime', [
				'notnull' => false,
			]);
			$table->addColumn('local', 'boolean', [
				'notnull' => true,
				'default' => false
			]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('social_cache_actors')) {
			$table = $schema->createTable('social_cache_actors');
			$table->addColumn('id', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('type', 'string', [
				'notnull' => true,
				'length' => 31,
			]);
			$table->addColumn('account', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('local', 'boolean', [
				'notnull' => true,
				'default' => false
			]);
			$table->addColumn('following', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('followers', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('inbox', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('shared_inbox', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('outbox', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('featured', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('url', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('preferred_username', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('name', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('icon_id', 'string', [
				'notnull' => false,
				'length' => 127,
			]);
			$table->addColumn('summary', 'string', [
				'notnull' => true,
				'length' => 3000,
			]);
			$table->addColumn('public_key', 'string', [
				'notnull' => true,
				'length' => 500,
			]);
			$table->addColumn('source', 'string', [
				'notnull' => true,
				'length' => 3000,
			]);
			$table->addColumn('details', 'string', [
				'notnull' => false,
				'length' => 3000,
			]);
			$table->addColumn('creation', 'datetime', [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('social_cache_documents')) {
			$table = $schema->createTable('social_cache_documents');
			$table->addColumn('id', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('type', 'string', [
				'notnull' => true,
				'length' => 31,
			]);
			$table->addColumn('media_type', 'string', [
				'notnull' => true,
				'length' => 63,
			]);
			$table->addColumn('mime_type', 'string', [
				'notnull' => true,
				'length' => 63,
			]);
			$table->addColumn('url', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('local_copy', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('public', 'boolean', [
				'notnull' => true,
				'default' => false
			]);
			$table->addColumn('error', 'smallint', [
				'notnull' => true,
				'length' => 1,
			]);
			$table->addColumn('creation', 'datetime', [
				'notnull' => false,
			]);
			$table->addColumn('caching', 'datetime', [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['url'], 'unique_url');
		}

		if (!$schema->hasTable('social_request_queue')) {
			$table = $schema->createTable('social_request_queue');
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 11,
				'unsigned' => true,
			]);
			$table->addColumn('token', 'string', [
				'notnull' => true,
				'length' => 63,
			]);
			$table->addColumn('author', 'string', [
				'notnull' => true,
				'length' => 127,
			]);
			$table->addColumn('activity', 'string', [
				'notnull' => true,
				'length' => 6000,
			]);
			$table->addColumn('instance', 'string', [
				'notnull' => false,
				'length' => 500,
			]);
			$table->addColumn('priority', 'smallint', [
				'notnull' => false,
				'length' => 1,
				'default' => 0,
			]);
			$table->addColumn('status', 'smallint', [
				'notnull' => false,
				'length' => 1,
				'default' => 0,
			]);
			$table->addColumn('tries', 'smallint', [
				'notnull' => false,
				'length' => 2,
				'default' => 0,
			]);
			$table->addColumn('last', 'datetime', [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id']);
		}
		return $schema;
	}
}
