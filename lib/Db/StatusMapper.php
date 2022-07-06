<?php

namespace OCA\Social\Db;

use OCP\AppFramework\Db\QBMapper;

/**
 * @extends QBMapper<Status>
 */
class StatusMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'status', Status::class);
	}
}
