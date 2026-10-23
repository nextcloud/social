<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Db\RecommendationPreferenceRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationTopicCatalog;
use PHPUnit\Framework\TestCase;

class RecommendationPreferenceServiceTest extends TestCase {
	private RecommendationPreferenceRequest $request;
	private ConfigService $config;
	private RecommendationPreferenceService $service;

	protected function setUp(): void {
		$this->request = $this->createMock(RecommendationPreferenceRequest::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->service = new RecommendationPreferenceService($this->request, new RecommendationTopicCatalog(), $this->config);
	}

	public function testFeatureFlagIsOffUnlessTheInstanceEnablesIt(): void {
		$this->config->expects($this->once())->method('getAppValueBool')->with(ConfigService::REC_V4_ENABLED)->willReturn(false);
		$this->assertFalse($this->service->isEnabled());
	}

	public function testAdminOperationalSettingsDefaultOffAndSaveOnlyNamedFlags(): void {
		$this->config->method('getAppValueBool')->willReturn(false);
		$this->config->expects($this->once())->method('setAppValue')
			->with(ConfigService::REC_LEARNING_PAUSED, '1');

		$this->assertSame([
			'enabled' => false,
			'remoteDiscoveryPaused' => false,
			'learningPaused' => false,
			'forceLegacyFeed' => false,
		], $this->service->adminSettings());

		$this->assertSame([
			'enabled' => false,
			'remoteDiscoveryPaused' => false,
			'learningPaused' => false,
			'forceLegacyFeed' => false,
		], $this->service->saveAdminSettings(null, null, true, null));
	}

	public function testCatalogHasStableUniqueIdsAndBilingualLabelsWithoutNetworkAccess(): void {
		$catalog = $this->service->catalog();
		$ids = array_column($catalog, 'id');
		$this->assertCount(count(array_unique($ids)), $ids);
		$this->assertContains('tech.apple', $ids);
		$this->assertSame(['en' => 'Technology', 'de' => 'Technologie'], $catalog[0]['labels']);
	}

	public function testParentHideAlwaysWinsAndChildOverridesSoftParentMode(): void {
		$this->request->method('get')->willReturn([
			'revision' => 4,
			'generation' => 1,
			'learning_mode' => 'off',
			'topics' => [
				['topic_id' => 'technology', 'mode' => 'LESS'],
				['topic_id' => 'tech.apple', 'mode' => 'INTERESTED'],
				['topic_id' => 'oss', 'mode' => 'HIDE'],
			],
		]);
		$effective = $this->service->get('alice')['effective_topics'];
		$this->assertSame('INTERESTED', $effective['tech.apple']);
		$this->assertSame('LESS', $effective['technology']);
		$this->assertSame('HIDE', $effective['oss.nextcloud']);
	}

	public function testUnknownAndDuplicateTopicsAreRejectedBeforeWriting(): void {
		$this->request->expects($this->never())->method('replace');
		$this->expectException(InvalidArgumentException::class);
		$this->service->replace('alice', 0, [
			['topic_id' => 'not.in.catalog', 'mode' => 'INTERESTED'],
		]);
	}

	public function testInvalidModeIsRejectedBeforeWriting(): void {
		$this->request->expects($this->never())->method('replace');
		$this->expectException(InvalidArgumentException::class);
		$this->service->replace('alice', 0, [
			['topic_id' => 'tech.apple', 'mode' => 'FOLLOW'],
		]);
	}

	public function testMalformedPreferenceEntryIsRejectedAsClientInput(): void {
		$this->request->expects($this->never())->method('replace');
		$this->expectException(InvalidArgumentException::class);
		$this->service->replace('alice', 0, ['tech.apple']);
	}

	public function testInvalidOnboardingStateAndContentBalanceAreRejectedBeforeWriting(): void {
		$this->request->expects($this->never())->method('replace');
		try {
			$this->service->replace('alice', 0, [], 'unbounded');
			$this->fail('Invalid content balance was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Unknown content balance', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->service->replace('alice', 0, [], null, 5, false);
	}
}
