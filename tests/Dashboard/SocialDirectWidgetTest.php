<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use OCA\Social\Dashboard\SocialDirectWidget;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SocialDirectWidgetTest extends TestCase {
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var StreamService&MockObject */
	private $streamService;
	private SocialDirectWidget $widget;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->streamService = $this->createMock(StreamService::class);

		$this->widget = new SocialDirectWidget(
			$l10n,
			$this->urlGenerator,
			$this->accountService,
			$this->cacheActorService,
			$this->streamService,
			new NullLogger()
		);
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IAPIWidgetV2::class, $this->widget);
		$this->assertSame('social_direct', $this->widget->getId());
		$this->assertSame('Social direct messages', $this->widget->getTitle());
		$this->assertSame(13, $this->widget->getOrder());
	}

	public function testItReadsTheDirectProbeAndOpensThatTimeline(): void {
		$account = $this->createMock(Person::class);
		$account->method('getPreferredUsername')->willReturn('alice');
		$this->accountService->method('getActorFromUserId')->willReturn($account);
		$this->cacheActorService->method('getFromLocalAccount')->willReturn($this->createMock(Person::class));
		$this->urlGenerator->method('linkToRoute')
			->with('social.Navigation.timeline', ['path' => 'direct'])
			->willReturn('/apps/social/timeline/direct');
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [];
		});

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame(ProbeOptions::DIRECT, $options->getProbe());
		$this->assertSame('/apps/social/timeline/direct', $this->widget->getUrl());
		$this->assertSame('No direct messages', $items->getEmptyContentMessage());
	}
}
