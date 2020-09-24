<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2020, Maxence Lange <maxence@artificial-owl.com>
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


namespace OCA\Social\Listeners;


use OC\WellKnown\Event\WellKnownEvent;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Service\WellKnownService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\WellKnown\IWellKnownManager;


/**
 * Class WellKnownListener
 *
 * @package OCA\Social\Listeners
 */
class WellKnownListener implements IEventListener {


	private $wellKnownService;


	/**
	 * WellKnownListener constructor.
	 *
	 * @param WellKnownService $wellKnownService
	 */
	public function __construct(WellKnownService $wellKnownService) {
		$this->wellKnownService = $wellKnownService;
	}


	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$event instanceof WellKnownEvent) {
			return;
		}

		$wellKnown = $event->getWellKnown();
		if ($wellKnown->getService() === IWellKnownManager::WEBFINGER) {
			try {
				$this->wellKnownService->webfinger($wellKnown);
			} catch (CacheActorDoesNotExistException | SocialAppConfigException | UnauthorizedFediverseException $e) {
			}
		}
	}

}

