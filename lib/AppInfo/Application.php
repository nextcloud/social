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


use OCA\Social\Handlers\WebfingerHandler;
use OCA\Social\Notification\Notifier;
use OCA\Social\Search\UnifiedSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Class Application
 *
 * @package OCA\Social\AppInfo
 */
class Application extends App implements IBootstrap {


	const APP_ID = 'social';
	const APP_NAME = 'Social';
	const APP_REL = 'https://apps.nextcloud.com/apps/social';
	const NEXTCLOUD_SUBJECT = 'http://nextcloud.com/';

	/**
	 * Application constructor.
	 *
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}


	/**
	 * @param IRegistrationContext $context
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(UnifiedSearchProvider::class);
		$context->registerWellKnownHandler(WebfingerHandler::class);
	}


	/**
	 * @param IBootContext $context
	 */
	public function boot(IBootContext $context): void {
		$manager = $context->getServerContainer()
						   ->getNotificationManager();
		$manager->registerNotifierService(Notifier::class);
	}

}

