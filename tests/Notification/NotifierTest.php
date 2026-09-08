<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Notification;

use OCA\Social\Notification\Notifier;
use OCP\Contacts\IManager;
use OCP\Federation\ICloudIdManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	/** @var IL10N&MockObject */
	private $l10n;
	/** @var IFactory&MockObject */
	private $factory;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	private Notifier $notifier;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->factory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);

		$this->notifier = new Notifier(
			$this->l10n,
			$this->factory,
			$this->createMock(IManager::class),
			$this->urlGenerator,
			$this->createMock(ICloudIdManager::class)
		);
	}

	/** @return INotification&MockObject */
	private function notification(string $app, string $subject, array $actions = []): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn([]);
		$notification->method('getActions')->willReturn($actions);

		return $notification;
	}

	/** @return IAction&MockObject */
	private function action(string $label): IAction {
		$action = $this->createMock(IAction::class);
		$action->method('getLabel')->willReturn($label);
		$action->method('setParsedLabel')->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();

		return $action;
	}

	/** @return IL10N&MockObject */
	private function translationsFor(string $language): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $text, $params = []): string => '[' . $language . '] '
				. ((array)$params === [] ? $text : vsprintf($text, (array)$params))
		);
		$this->factory->method('get')->with('social', $language)->willReturn($l10n);

		return $l10n;
	}

	public function testIdIsTheAppId(): void {
		$this->assertSame('social', $this->notifier->getID());
	}

	public function testNameIsTranslated(): void {
		$this->l10n->method('t')->with('Social')->willReturn('Sozial');

		$this->assertSame('Sozial', $this->notifier->getName());
	}

	public function testNotificationsOfOtherAppsAreRejected(): void {
		$notification = $this->notification('spreed', 'update_alpha3');
		$this->factory->expects($this->never())->method('get');

		$this->expectException(\InvalidArgumentException::class);

		$this->notifier->prepare($notification, 'en');
	}

	public function testUnknownSubjectsAreRejected(): void {
		$this->translationsFor('en');
		$notification = $this->notification('social', 'new_follower');

		$this->expectException(\InvalidArgumentException::class);

		$this->notifier->prepare($notification, 'en');
	}

	public function testUpdateAlpha3IsParsedInTheRequestedLanguage(): void {
		$this->translationsFor('de');
		$this->urlGenerator->method('imagePath')->with('social', 'social_dark.svg')->willReturn('/apps/social/img/social_dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->with('/apps/social/img/social_dark.svg')->willReturn('https://cloud.example/apps/social/img/social_dark.svg');

		$notification = $this->notification('social', 'update_alpha3');
		$notification->expects($this->once())->method('setIcon')->with('https://cloud.example/apps/social/img/social_dark.svg');
		$notification->expects($this->once())->method('setParsedSubject')->with('The Social App has been updated to alpha3.');
		$notification->expects($this->once())->method('setParsedMessage')
			->with($this->stringStartsWith('[de] Please note that the data from alpha2 can only be migrated manually.'));

		$this->assertSame($notification, $this->notifier->prepare($notification, 'de'));
	}

	public function testHelpActionBecomesThePrimaryParsedAction(): void {
		$this->translationsFor('en');
		$help = $this->action('help');
		$help->expects($this->once())->method('setParsedLabel')->with('[en] Help');
		$help->expects($this->once())->method('setPrimary')->with(true);

		$notification = $this->notification('social', 'update_alpha3', [$help]);
		$notification->expects($this->once())->method('addParsedAction')->with($help);

		$this->notifier->prepare($notification, 'en');
	}

	public function testUnknownActionsAreForwardedUnparsed(): void {
		$this->translationsFor('en');
		$other = $this->action('dismiss');
		$other->expects($this->never())->method('setParsedLabel');
		$other->expects($this->never())->method('setPrimary');

		$notification = $this->notification('social', 'update_alpha3', [$other]);
		$notification->expects($this->once())->method('addParsedAction')->with($other);

		$this->notifier->prepare($notification, 'en');
	}

	/** @return INotification&MockObject */
	private function reportNotification(array $params): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('social');
		$notification->method('getSubject')->willReturn('report_new');
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('getActions')->willReturn([]);

		return $notification;
	}

	public function testANewLocalReportLinksToTheAdminSettings(): void {
		$this->translationsFor('en');
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('settings.AdminSettings.index', ['section' => 'social'])
			->willReturn('https://cloud.example/settings/admin/social');

		$notification = $this->reportNotification([
			'reporter' => 'https://cloud.example/apps/social/@alice',
			'account' => 'https://cloud.example/apps/social/@bob',
			'local' => true,
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('[en] New report about https://cloud.example/apps/social/@bob');
		$notification->expects($this->once())->method('setParsedMessage')
			->with($this->stringContains('administration settings'));
		$notification->expects($this->once())->method('setLink')
			->with('https://cloud.example/settings/admin/social');

		$this->assertSame($notification, $this->notifier->prepare($notification, 'en'));
	}

	public function testANewRemoteReportSaysSo(): void {
		$this->translationsFor('en');

		$notification = $this->reportNotification([
			'reporter' => 'https://mastodon.social/actor',
			'account' => 'https://cloud.example/apps/social/@bob',
			'local' => false,
		]);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('[en] New report about https://cloud.example/apps/social/@bob from another instance');

		$this->notifier->prepare($notification, 'en');
	}
}
