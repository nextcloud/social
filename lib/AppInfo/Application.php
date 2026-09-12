<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AppInfo;

use OCA\Social\Dashboard\SocialBookmarksWidget;
use OCA\Social\Dashboard\SocialDirectWidget;
use OCA\Social\Dashboard\SocialFederationHealthWidget;
use OCA\Social\Dashboard\SocialFollowRequestsWidget;
use OCA\Social\Dashboard\SocialMentionsWidget;
use OCA\Social\Dashboard\SocialReportsWidget;
use OCA\Social\Dashboard\SocialTimelineWidget;
use OCA\Social\Dashboard\SocialTrendingWidget;
use OCA\Social\Dashboard\SocialWidget;
use OCA\Social\Listeners\ProfileSectionListener;
use OCA\Social\Listeners\UserAccountListener;
use OCA\Social\Listeners\UserDeletedListener;
use OCA\Social\Middleware\AccessBlockMiddleware;
use OCA\Social\Notification\Notifier;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\UserMigration\SocialMigrator;
use OCA\Social\WellKnown\WebfingerHandler;
use OCP\Accounts\UserUpdatedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Profile\BeforeTemplateRenderedEvent;
use OCP\User\Events\UserDeletedEvent;

require_once __DIR__ . '/../../vendor/autoload.php';

class Application extends App implements IBootstrap {
	public const APP_ID = 'social';
	public const APP_NAME = 'Social';
	public const APP_SUBJECT = 'http://nextcloud.com/';
	public const APP_REL = 'https://apps.nextcloud.com/apps/social';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// before anything else it registers: an address at `no_access` is
		// refused whatever it was asking for
		$context->registerMiddleware(AccessBlockMiddleware::class);
		$context->registerSearchProvider(UnifiedSearchProvider::class);
		$context->registerWellKnownHandler(WebfingerHandler::class);
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, ProfileSectionListener::class);
		$context->registerEventListener(UserUpdatedEvent::class, UserAccountListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerDashboardWidget(SocialWidget::class);
		$context->registerDashboardWidget(SocialTimelineWidget::class);
		$context->registerDashboardWidget(SocialMentionsWidget::class);
		$context->registerDashboardWidget(SocialDirectWidget::class);
		$context->registerDashboardWidget(SocialBookmarksWidget::class);
		$context->registerDashboardWidget(SocialFollowRequestsWidget::class);
		$context->registerDashboardWidget(SocialTrendingWidget::class);
		$context->registerDashboardWidget(SocialReportsWidget::class);
		$context->registerDashboardWidget(SocialFederationHealthWidget::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerUserMigrator(SocialMigrator::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
