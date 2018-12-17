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
use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\ActivityPubFormatException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Model\ActivityPub\ACore;


class ImportService {


	use TArrayTools;


	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ImportService constructor.
	 *
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(ConfigService $configService, MiscService $miscService) {
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $json
	 *
	 * @return ACore
	 * @throws UnknownItemException
	 * @throws SocialAppConfigException
	 * @throws ActivityPubFormatException
	 * @throws RedundancyLimitException
	 */
	public function importFromJson(string $json): ACore {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new ActivityPubFormatException();
		}

		return AP::$activityPub->getItemFromData($data);
	}


//
//	/**
//	 * @param array $data
//	 * @param ACore $root
//	 *
//	 * @return ACore
//	 * @throws UnknownItemException
//	 * @throws UrlCloudException
//	 * @throws SocialAppConfigException
//	 * @throws InvalidResourceEntryException
//	 */
//	private function importFromData(array $data, $root = null): ACore {
//
//		$item = AP::$activityPub->getItemFromData($data);
//		$item->setParent($root);
//
//		$item->setUrlCloud($this->configService->getCloudAddress());
//		$item->setSource(json_encode($data, JSON_UNESCAPED_SLASHES));
//
//		try {
//			$object = $this->importFromData($this->getArray('object', $data, []), $item);
//			$item->setObject($object);
//		} catch (UnknownItemException $e) {
//		}
//
//		try {
//			/** @var Document $icon */
//			$icon = $this->importFromData($this->getArray('icon', $data, []), $item);
//			$item->setIcon($icon);
//		} catch (UnknownItemException $e) {
//		}
//
//		return $item;
//	}


	/**
	 * @param ACore $activity
	 *
	 * @throws UnknownItemException
	 */
	public function parseIncomingRequest(ACore $activity) {
		$interface = AP::$activityPub->getInterfaceForItem($activity);

		try {
			$interface->processIncomingRequest($activity);
		} catch (Exception $e) {
			$this->miscService->log(
				'Cannot parse ' . $activity->getType() . ': ' . $e->getMessage()
			);
		}
	}


}

