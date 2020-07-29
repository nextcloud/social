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


use OC\DB\SchemaWrapper;
use OC\Webfinger\Event\WebfingerEvent;
use OC\Webfinger\Model\WebfingerObject;
use OCA\Social\Notification\Notifier;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\UpdateService;
use OCA\Social\Service\WebfingerService;
use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\QueryException;
use OCP\EventDispatcher\IEventDispatcher;


/**
 * Class Application
 *
 * @package OCA\Social\AppInfo
 */
class Application extends App {


	const APP_NAME = 'social';


	/** @var ConfigService */
	private $configService;

	/** @var UpdateService */
	private $updateService;

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

		$manager = $this->container->getServer()
								   ->getNotificationManager();
		$manager->registerNotifierService(Notifier::class);
	}


	/**
	 *
	 */
	public function registerWebfinger() {
		/** @var IEventDispatcher $eventDispatcher */
		$eventDispatcher = \OC::$server->query(IEventDispatcher::class);
		$eventDispatcher->addListener(
			'\OC\Webfinger::onRequest',
			function(WebfingerEvent $e) {
				/** @var WebfingerService $webfingerService */
				$webfingerService = $this->container->query(WebfingerService::class);
				try {
					$webfingerService->webfinger($e);
				} catch (\Exception $e) {
				}
			}
		);
	}


	/**
	 *
	 */
	public function checkUpgradeStatus() {
		$upgradeChecked = $this->container->getServer()
										  ->getConfig()
										  ->getAppValue(Application::APP_NAME, 'update_checked', '');

		if ($upgradeChecked === '0.3') {
			return;
		}

		try {
			$this->configService = $this->container->query(ConfigService::class);
			$this->updateService = $this->container->query(UpdateService::class);
		} catch (QueryException $e) {
			return;
		}

		$server = $this->container->getServer();
		$schema = new SchemaWrapper($server->getDatabaseConnection());
		if ($schema->hasTable('social_a2_stream')) {
			$this->updateService->checkUpdateStatus();
		}

		$this->configService->setAppValue('update_checked', '0.3');
	}

}

