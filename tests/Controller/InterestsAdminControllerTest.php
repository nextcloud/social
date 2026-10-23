<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\InterestsAdminController;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\RecommendationDiscoveryHealthService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class InterestsAdminControllerTest extends TestCase {
	public function testAdminSwitchesAreReturnedAndPartialUpdateIsForwarded(): void {
		$settings = ['enabled' => true, 'remoteDiscoveryPaused' => false, 'learningPaused' => true, 'forceLegacyFeed' => false];
		$preferences = $this->createMock(RecommendationPreferenceService::class);
		$preferences->expects($this->once())->method('adminSettings')->willReturn($settings);
		$preferences->expects($this->once())->method('saveAdminSettings')
			->with(null, true, null, null)->willReturn($settings);
		$controller = new InterestsAdminController(
			$this->createStub(IRequest::class),
			$this->createStub(InterestService::class),
			$preferences,
			$this->createStub(RecommendationDiscoveryHealthService::class),
		);

		$this->assertSame($settings, $controller->recommendationSettings()->getData());
		$this->assertSame($settings, $controller->saveRecommendationSettings(null, true)->getData());
	}

	public function testDiscoveryHealthUsesTheRequestedBoundedWindow(): void {
		$summary = ['window_hours' => 12, 'sources' => []];
		$health = $this->createMock(RecommendationDiscoveryHealthService::class);
		$health->expects($this->once())->method('summary')->with(12)->willReturn($summary);
		$controller = new InterestsAdminController(
			$this->createStub(IRequest::class),
			$this->createStub(InterestService::class),
			$this->createStub(RecommendationPreferenceService::class),
			$health,
		);

		$response = $controller->recommendationDiscoveryHealth(12);

		$this->assertSame($summary, $response->getData());
	}
}
