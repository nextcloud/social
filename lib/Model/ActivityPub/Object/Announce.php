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


namespace OCA\Social\Model\ActivityPub\Object;


use Exception;
use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;


/**
 * Class Follow
 *
 * @package OCA\Social\Model\ActivityPub\Object
 */
class Announce extends Stream implements JsonSerializable {


	const TYPE = 'Announce';


	/**
	 * Follow constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}


	/**
	 * @param array $data
	 *
	 * @throws Exception
	 */
	public function import(array $data) {
		parent::import($data);

		// Might be better to create 'actor_id' field in the 'server_streams' table.
		$this->setAttributedTo($this->getActorId());
	}


	/**
	 * @param array $data
	 *
	 * @throws Exception
	 */
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$result = parent::jsonSerialize();
		//$result['actor'] = $this->getAttributedTo();

		return $result;
	}

}

