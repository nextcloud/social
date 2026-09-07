<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AppInfo;

use OCA\Social\AppInfo\Application;
use OCA\Social\Dashboard\SocialTimelineWidget;
use OCA\Social\Dashboard\SocialWidget;
use OCA\Social\Listeners\ProfileSectionListener;
use OCA\Social\Listeners\UserAccountListener;
use OCA\Social\Notification\Notifier;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\WellKnown\WebfingerHandler;
use OCP\Accounts\UserUpdatedEvent;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Profile\BeforeTemplateRenderedEvent;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {
	/**
	 * App::__construct() needs the server container, so the bootstrap methods
	 * are exercised on an instance created without it.
	 */
	private function application(): Application {
		return (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
	}

	public function testIsABootstrappedAppNamedSocial(): void {
		$this->assertInstanceOf(IBootstrap::class, $this->application());
		$this->assertSame('social', Application::APP_ID);
		$this->assertSame('Social', Application::APP_NAME);
	}

	public function testRegisterWiresUpEveryIntegrationPoint(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerSearchProvider')->with(UnifiedSearchProvider::class);
		$context->expects($this->once())->method('registerWellKnownHandler')->with(WebfingerHandler::class);
		$context->expects($this->once())->method('registerNotifierService')->with(Notifier::class);

		$listeners = [];
		$context->expects($this->exactly(2))->method('registerEventListener')
			->willReturnCallback(function (string $event, string $listener) use (&$listeners): void {
				$listeners[$event] = $listener;
			});
		$widgets = [];
		$context->expects($this->exactly(2))->method('registerDashboardWidget')
			->willReturnCallback(function (string $widget) use (&$widgets): void {
				$widgets[] = $widget;
			});

		$this->application()->register($context);

		$this->assertSame([
			BeforeTemplateRenderedEvent::class => ProfileSectionListener::class,
			UserUpdatedEvent::class => UserAccountListener::class,
		], $listeners);
		$this->assertSame([SocialWidget::class, SocialTimelineWidget::class], $widgets);
	}

	public function testRegisterDoesNotTouchOtherRegistrationApis(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->never())->method('registerMiddleware');
		$context->expects($this->never())->method('registerService');
		$context->expects($this->never())->method('registerCapability');

		$this->application()->register($context);
	}

	public function testBootDoesNothing(): void {
		$context = $this->createMock(IBootContext::class);
		$context->expects($this->never())->method($this->anything());

		$this->application()->boot($context);
	}
}
