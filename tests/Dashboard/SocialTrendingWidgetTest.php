<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use Exception;
use OCA\Social\Dashboard\SocialTrendingWidget;
use OCA\Social\Service\HashtagService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SocialTrendingWidgetTest extends TestCase {
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var HashtagService&MockObject */
	private $hashtagService;
	private SocialTrendingWidget $widget;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count): string
				=> str_replace('%n', (string)$count, $count === 1 ? $one : $many)
		);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->hashtagService = $this->createMock(HashtagService::class);

		$this->widget = new SocialTrendingWidget(
			$l10n,
			$this->urlGenerator,
			$this->hashtagService,
			new NullLogger()
		);
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IAPIWidgetV2::class, $this->widget);
		$this->assertSame('social_trending', $this->widget->getId());
		$this->assertSame('Social trending hashtags', $this->widget->getTitle());
		$this->assertSame(16, $this->widget->getOrder());
		$this->assertSame(900, $this->widget->getReloadInterval(), 'trends move in hours');
	}

	public function testRowsLinkToTheHashtagTimeline(): void {
		$this->hashtagService->method('getTrending')->willReturn([
			['hashtag' => 'nextcloud', 'trend' => ['1d' => 12, '1h' => 2]],
			['hashtag' => 'fediverse', 'trend' => ['1d' => 1]],
		]);
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $args = []): string => '/apps/social/timeline/' . ($args['path'] ?? '')
		);

		$list = $this->widget->getItemsV2('alice')->getItems();

		$this->assertCount(2, $list);
		$this->assertSame('#nextcloud', $list[0]->getTitle());
		$this->assertSame('12 posts', $list[0]->getSubtitle(), 'the count is the one for the period shown');
		$this->assertSame('/apps/social/timeline/tags/nextcloud', $list[0]->getLink());
		$this->assertSame('1 post', $list[1]->getSubtitle());
	}

	public function testTheLimitIsPassedToTheServiceAndCapped(): void {
		$seen = [];
		$this->hashtagService->method('getTrending')->willReturnCallback(
			function (int $limit, string $period) use (&$seen): array {
				$seen[] = [$limit, $period];

				return [];
			}
		);

		$this->widget->getItemsV2('alice', null, 50);
		$this->widget->getItemsV2('alice', null, 3);

		$this->assertSame([[10, '1d'], [3, '1d']], $seen);
	}

	public function testAHashtagWithoutANameIsSkipped(): void {
		$this->hashtagService->method('getTrending')->willReturn([
			['hashtag' => '', 'trend' => ['1d' => 5]],
		]);

		$this->assertSame([], $this->widget->getItemsV2('alice')->getItems());
	}

	public function testFailureLeavesTheTileEmptyWithAMessage(): void {
		$this->hashtagService->method('getTrending')->willThrowException(new Exception('nope'));

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Could not load trending hashtags', $items->getEmptyContentMessage());
	}
}
