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

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity;
use OCA\Social\Model\ActivityPub\Follow;
use OCA\Social\Model\ActivityPub\Note;
use OCA\Social\Model\ActivityPub\Undo;

class ImportService {


	use TArrayTools;


	/** @var MiscService */
	private $miscService;


	/**
	 * ActorService constructor.
	 *
	 * @param MiscService $miscService
	 */
	public function __construct(MiscService $miscService) {
		$this->miscService = $miscService;
	}


	/**
	 * @param string $json
	 *
	 * @return ACore
	 * @throws UnknownItemException
	 */
	public function import(string $json) {
		$data = json_decode($json, true);

		$activity = $this->createItem($data, null);

		return $activity;
	}


	/**
	 * @param array $data
	 * @param ACore $root
	 *
	 * @return ACore
	 * @throws UnknownItemException
	 */
	private function createItem(array $data, $root = null): ACore {

		$isTopLevel = ($root === null);
		switch ($this->get('type', $data)) {
			case 'Create':
				$item = new Activity($isTopLevel);
				break;

			case 'Note':
				$item = new Note($isTopLevel);
				break;

			case 'Follow':
				$item = new Follow($isTopLevel);
				break;

			case 'Undo':
				$item = new Undo($isTopLevel);
				break;

			default:
				throw new UnknownItemException();
		}

		if ($root instanceof ACore) {
			$item->setMetaAll($root->getMetaAll());
		}

		$item->import($data);
		$item->setSource(json_encode($data, JSON_UNESCAPED_SLASHES));

		try {
			$object = $this->createItem($this->getArray('object', $data, []), $item);
			$item->setObject($object);
		} catch (UnknownItemException $e) {
		}

		return $item;
	}

}

