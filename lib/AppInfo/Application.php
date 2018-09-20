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

namespace OCA\Social\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;

class Application extends App {

	const APP_NAME = 'social';

	/** @var IAppContainer */
	private $container;


	/**
	 * Application constructor.
	 *
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		parent::__construct(self::APP_NAME, $params);

		$this->container = $this->getContainer();
	}

//
//	/**
//	 * Register Navigation Tab
//	 */
//	public function registerNavigation() {
//
//		$urlGen = \OC::$server->getURLGenerator();
//		$navName = \OC::$server->getL10N(self::APP_NAME)
//							   ->t('Social');
//
//		$social = [
//			'id'    => self::APP_NAME,
//			'order' => 5,
//			'href'  => $urlGen->linkToRoute('social.Navigation.navigate'),
//			'icon'  => $urlGen->imagePath(self::APP_NAME, 'social.svg'),
//			'name'  => $navName
//		];
//
//		$this->container->getServer()
//						->getNavigationManager()
//						->add($social);
//	}

}

