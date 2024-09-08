<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AppInfo;

use OCA\Social\Dashboard\SocialWidget;
use OCA\Social\Listeners\ProfileSectionListener;
use OCA\Social\Listeners\UserAccountListener;
use OCA\Social\Notification\Notifier;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\WellKnown\WebfingerHandler;
use OCP\Accounts\UserUpdatedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Profile\BeforeTemplateRenderedEvent;

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
		$context->registerEventListener(UserUpdatedEvent::class, UserAccountListener::class);

		$context->registerDashboardWidget(SocialWidget::class);
	}

	public function boot(IBootContext $context): void {
		$manager = $context->getServerContainer()
			->getNotificationManager();
		$manager->registerNotifierService(Notifier::class);
	}
}
