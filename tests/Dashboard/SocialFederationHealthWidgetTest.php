<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use Exception;
use OCA\Social\Dashboard\SocialFederationHealthWidget;
use OCA\Social\Service\FederationHealthService;
use OCP\Dashboard\IConditionalWidget;
use OCP\IDateTimeFormatter;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SocialFederationHealthWidgetTest extends TestCase {
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var IUserSession&MockObject */
	private $userSession;
	/** @var IGroupManager&MockObject */
	private $groupManager;
	/** @var FederationHealthService&MockObject */
	private $federationHealthService;
	private SocialFederationHealthWidget $widget;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count): string
				=> str_replace('%n', (string)$count, $count === 1 ? $one : $many)
		);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->federationHealthService = $this->createMock(FederationHealthService::class);
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatTimeSpan')->willReturn('3 days ago');

		$this->widget = new SocialFederationHealthWidget(
			$l10n,
			$this->urlGenerator,
			$this->userSession,
			$this->groupManager,
			$formatter,
			$this->federationHealthService,
			new NullLogger()
		);
	}

	private function signedInAs(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($admin);
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IConditionalWidget::class, $this->widget);
		$this->assertSame('social_federation_health', $this->widget->getId());
		$this->assertSame('Social federation health', $this->widget->getTitle());
		$this->assertSame(21, $this->widget->getOrder());
	}

	public function testOnlyAnAdminIsOfferedTheWidget(): void {
		$this->signedInAs('alice', true);
		$this->assertTrue($this->widget->isEnabled());
	}

	public function testANonAdminIsNotOfferedTheWidget(): void {
		$this->signedInAs('bob', false);
		$this->assertFalse($this->widget->isEnabled());
	}

	public function testAHealthyQueueSaysSoRatherThanShowingRows(): void {
		$this->federationHealthService->method('summary')->willReturn([
			'waiting' => 4, 'running' => 0, 'failing' => 0, 'atRisk' => 0,
			'maxTries' => 15, 'truncated' => false, 'instances' => [],
		]);

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Everything is being delivered', $items->getEmptyContentMessage());
	}

	public function testTheTotalsLeadSoTroubleIsVisibleEvenWithoutANamedHost(): void {
		$this->federationHealthService->method('summary')->willReturn([
			'waiting' => 30, 'running' => 1, 'failing' => 12, 'atRisk' => 3,
			'maxTries' => 15, 'truncated' => false, 'instances' => [],
		]);
		$this->urlGenerator->method('linkToRoute')->willReturn('/settings/admin/social');

		$list = $this->widget->getItemsV2('alice')->getItems();

		$this->assertCount(1, $list, 'failures with no attributable host still have to show');
		$this->assertSame('12 deliveries are failing', $list[0]->getTitle());
		$this->assertSame('3 at risk of being dropped after 15 tries', $list[0]->getSubtitle());
		$this->assertSame('/settings/admin/social', $list[0]->getLink());
	}

	public function testEachFailingInstanceGetsARow(): void {
		$this->federationHealthService->method('summary')->willReturn([
			'waiting' => 30, 'running' => 0, 'failing' => 5, 'atRisk' => 1,
			'maxTries' => 15, 'truncated' => false,
			'instances' => [
				['host' => 'dead.example', 'requests' => 4, 'tries' => 14, 'last' => 1714564800],
				['host' => 'slow.example', 'requests' => 1, 'tries' => 1, 'last' => 0],
			],
		]);
		$this->urlGenerator->method('linkToRoute')->willReturn('/settings/admin/social');

		$list = $this->widget->getItemsV2('alice')->getItems();

		$this->assertCount(3, $list, 'the summary row plus one per host');
		$this->assertSame('dead.example', $list[1]->getTitle());
		$this->assertSame('4 deliveries, 14 tries, last tried 3 days ago', $list[1]->getSubtitle());
		$this->assertSame('slow.example', $list[2]->getTitle());
		$this->assertSame(
			'1 delivery, 1 try',
			$list[2]->getSubtitle(),
			'a host never tried has no last attempt to report'
		);
	}

	public function testFailureLeavesTheTileEmptyWithAMessage(): void {
		$this->federationHealthService->method('summary')->willThrowException(new Exception('nope'));

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Could not load federation health', $items->getEmptyContentMessage());
	}
}
