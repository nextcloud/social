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

use OCA\Social\Dashboard\SocialWidget;
use OCA\Social\Listeners\DeprecatedListener;
use OCA\Social\Listeners\ProfileSectionListener;
use OCA\Social\Notification\Notifier;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\WellKnown\WebfingerHandler;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\Profile\BeforeTemplateRenderedEvent;
use Symfony\Component\EventDispatcher\GenericEvent;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Class Application
 *
 * @package OCA\Social\AppInfo
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'social';
	public const APP_NAME = 'Social';
	public const APP_SUBJECT = 'http://nextcloud.com/';
	public const APP_REL = 'https://apps.nextcloud.com/apps/social';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(UnifiedSearchProvider::class);
		$context->registerWellKnownHandler(WebfingerHandler::class);
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, ProfileSectionListener::class);
		$context->registerDashboardWidget(SocialWidget::class);

		$this->registerDeprecatedListener();
	}

	public function boot(IBootContext $context): void {
		$manager = $context->getServerContainer()
						   ->getNotificationManager();
		$manager->registerNotifierService(Notifier::class);
	}


	public function registerDeprecatedListener(): void {
		$dispatcher = \OC::$server->getEventDispatcher();
		$dispatcher->addListener('OC\AccountManager::userUpdated', function (GenericEvent $event) {
			/** @var IUser $user */
			$user = $event->getSubject();
			/** @var DeprecatedListener $deprecatedListener */
			$deprecatedListener = \OC::$server->get(DeprecatedListener::class);
			$deprecatedListener->userAccountUpdated($user);
		});
	}
}
