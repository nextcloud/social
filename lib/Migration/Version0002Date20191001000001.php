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
 * Class Version0002Date20191001000001
 *
 * @package OCA\Social\Migration
 */
class Version0002Date20191001000001 extends SimpleMigrationStep {


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

		if (!$schema->hasTable('social_a2_stream_tags')) {
			$table = $schema->createTable('social_a2_stream_tags');

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

			if (!$table->hasIndex('sh')) {
				$table->addUniqueIndex(['stream_id', 'hashtag'], 'sh');
			}
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

		$this->fillTableStreamHashtags($schema);
	}

	/**
	 * @param ISchemaWrapper $schema
	 */
	private function fillTableStreamHashtags(ISchemaWrapper $schema) {

		$start = 0;
		$limit = 1000;
		while (true) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select('id', 'hashtags')
			   ->from('social_a2_stream')
			   ->setMaxResults(1000)
			   ->setFirstResult($start);

			$cursor = $qb->execute();
			$count = 0;
			while ($data = $cursor->fetch()) {
				$count++;

				$this->updateStreamTags($data);
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
	private function updateStreamTags(array $data) {
		if ($data['hashtags'] === '' || $data['hashtags'] === null) {
			return;
		}

		$id = $data['id'];
		$tags = json_decode($data['hashtags'], true);

		foreach ($tags as $tag) {
			if ($tag === '') {
				continue;
			}
			$insert = $this->connection->getQueryBuilder();
			$insert->insert('social_a2_stream_tags');

			$insert->setValue('stream_id', $insert->createNamedParameter(hash('sha512', $id)));
			$insert->setValue('hashtag', $insert->createNamedParameter($tag));
			try {
				$insert->execute();
			} catch (UniqueConstraintViolationException $e) {
			}
		}

	}

}
