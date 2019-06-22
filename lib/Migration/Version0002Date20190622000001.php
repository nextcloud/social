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
class Version0002Date20190622000001 extends SimpleMigrationStep {


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
		if (!$schema->hasTable('social_a2_stream')) {
			return $schema;
		}

		$table = $schema->getTable('social_a2_stream');
		if (!$table->hasColumn('details')) {
			$table->addColumn(
				'details', Type::TEXT,
				[
					'notnull' => false
				]
			);
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('social_a2_stream');
		$expr = $qb->expr();
		$qb->where($expr->eq('type', $qb->createNamedParameter('Announce')));

		$qb->execute();

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


		$this->check(
			$schema, 'id', 'social_server_follows', 'social_a2_follows', 'accepted', 'boolean'
		);
		$this->check(
			$schema, 'id', 'social_server_notes', 'social_a2_stream', 'local', 'boolean'
		);
		$this->check(
			$schema, 'id', 'social_cache_actors', 'social_a2_cache_actors', 'local', 'boolean'
		);
		$this->check(
			$schema, 'id', 'social_cache_documents', 'social_a2_cache_documts', 'public',
			'boolean'
		);


		$this->check(
			$schema, 'id', 'social_server_actors', 'social_a2_actors', 'creation', 'datetime'
		);
		$this->check(
			$schema, 'id', 'social_server_follows', 'social_a2_follows', 'creation', 'datetime'
		);
		$this->check(
			$schema, 'id', 'social_server_notes', 'social_a2_stream', 'creation', 'datetime'
		);
		$this->check(
			$schema, 'id', 'social_cache_actors', 'social_a2_cache_actors', 'creation',
			'datetime'
		);
		$this->check(
			$schema, 'id', 'social_cache_documents', 'social_a2_cache_documts', 'creation',
			'datetime'
		);

		$this->check(
			$schema, 'id', 'social_cache_documents', 'social_a2_cache_documts', 'caching',
			'datetime'
		);

//		$this->check($schema, 'id', 'social_request_queue', 'social_a2_request_queue','last', 'datetime');
//		$this->check($schema, 'id', 'social_queue_stream', 'social_a2_stream_queue', 'last', 'datetime');

		$this->check(
			$schema, 'id', 'social_server_notes', 'social_a2_stream', 'published_time',
			'datetime'
		);
	}


	private function check(
		ISchemaWrapper $schema,
		string $prim,
		string $source,
		string $dest,
		string $field,
		string $type
	) {
		if (!$schema->hasTable($source)) {
			return;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select($prim, $field)
		   ->from($source);

		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$this->fixMigration($dest, $prim, $field, $type, $data);
		}

		$cursor->closeCursor();
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
	 * @param string $k
	 * @param array $arr
	 * @param bool $default
	 *
	 * @return bool
	 */
	protected function getBool(string $k, array $arr, bool $default = false): bool {
		if ($arr === null) {
			return $default;
		}

		if (!array_key_exists($k, $arr)) {
			$subs = explode('.', $k, 2);
			if (sizeof($subs) > 1) {
				if (!array_key_exists($subs[0], $arr)) {
					return $default;
				}

				return $this->getBool($subs[1], $arr[$subs[0]], $default);
			} else {
				return $default;
			}
		}

		if ($arr[$k] === null) {
			return $default;
		}

		if (is_bool($arr[$k])) {
			return $arr[$k];
		}

		if ($arr[$k] === '1') {
			return true;
		}

		if ($arr[$k] === '0') {
			return false;
		}

		return $default;
	}



//
//
//	/**
//	 * @param string $table
//	 * @param array $fields
//	 * @param array $data
//	 *
//	 * @throws Exception
//	 */
//	private function insertInto(string $table, array $fields, array $data) {
//		$insert = $this->connection->getQueryBuilder();
//		$insert->insert($table);
//
//		$datetimeFields = [
//			'creation',
//			'last',
//			'caching',
//			'published_time'
//		];
//
//		$booleanFields = [
//			'local',
//			'public',
//			'accepted',
//			'hidden_on_timeline'
//		];
//
//		foreach ($fields as $field) {
//			$value = $this->get($field, $data, '');
//			if ($field === 'id_prim'
//				&& $value === ''
//				&& $this->get('id', $data, '') !== '') {
//				$value = hash('sha512', $this->get('id', $data, ''));
//			}
//
//			if (in_array($field, $datetimeFields) && $value === '') {
//				$insert->setValue(
//					$field,
//					$insert->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
//				);
//			} else if (in_array($field, $booleanFields) && $value === '') {
//				$insert->setValue(
//					$field, $insert->createNamedParameter('0')
//				);
//			} else {
//				$insert->setValue(
//					$field, $insert->createNamedParameter($value)
//				);
//			}
//		}
//
//		try {
//			$insert->execute();
//		} catch (UniqueConstraintViolationException $e) {
//		}
//	}


	/**
	 * @param string $dest
	 * @param string $prim
	 * @param string $field
	 * @param string $type
	 * @param array $old
	 */
	private function fixMigration(
		string $dest, string $prim, string $field, string $type, array $old
	) {

//		foreach ($fields as $field) {
//			$value = $this->get($field, $data, '');
//			if ($field === 'id_prim'
//				&& $value === ''
//				&& $this->get('id', $data, '') !== '') {
//				$value = hash('sha512', $this->get('id', $data, ''));
//			}

		$current = $this->connection->getQueryBuilder();
		$current->select($field)
				->from($dest);

		$cursor = $current->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return;
		}

		switch ($type) {
			case 'datetime':
				$oldValue = $this->get($field, $old);
				$oldId = $this->get($prim, $old);
				$currValue = $this->get($field, $data, '');
				if ($currValue !== '') {
					return;
				}

				if ($oldValue === '') {
					$oldValue = null;
				}

				$update = $this->connection->getQueryBuilder();
				$update->update($dest);
				$update->set($field, $update->createNamedParameter($oldValue));
				$expr = $update->expr();

				$update->where($expr->eq($prim, $update->createNamedParameter($oldId)));

				try {
					$update->execute();
				} catch (Exception $e) {
				}
				break;

			case 'boolean':
				$oldValue = $this->getBool($field, $old);
				$oldId = $this->get($prim, $old);
				$update = $this->connection->getQueryBuilder();
				$update->update($dest);
				$update->set($field, $update->createNamedParameter(($oldValue) ? '1' : '0'));
				$expr = $update->expr();

				$update->where($expr->eq($prim, $update->createNamedParameter($oldId)));

				$update->execute();


				break;
		}
	}


}

