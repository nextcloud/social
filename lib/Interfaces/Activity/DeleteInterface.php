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


namespace OCA\Social\Interfaces\Activity;

use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;

class DeleteInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	/**
	 * @throws InvalidOriginException
	 */
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getObjectId());

		if (!$item->hasObject()) {
			$types = ['Note', 'Person'];
			foreach ($types as $type) {
				try {
					$interface = AP::$activityPub->getInterfaceFromType($type);
					$object = $interface->getItemById($item->getObjectId());
					$interface->delete($object);

					return;
				} catch (ItemNotFoundException $e) {
				} catch (ItemUnknownException $e) {
				}
			}

			return;
		}

		$object = $item->getObject();
		try {
			$interface = AP::$activityPub->getInterfaceForItem($object);
			$interface->activity($item, $object);
		} catch (ItemUnknownException $e) {
		}
	}
}
