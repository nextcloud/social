<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationTopicCatalog;
use PHPUnit\Framework\TestCase;

class RecommendationTopicCatalogTest extends TestCase {
	public function testCatalogAddsSelectableBroadTopicsAndKeepsStableSubtopics(): void {
		$topics = (new RecommendationTopicCatalog())->all();
		$byId = array_column($topics, null, 'id');

		foreach (['software', 'gaming', 'business', 'environment', 'culture', 'sports', 'photography', 'education'] as $id) {
			$this->assertArrayHasKey($id, $byId);
			$this->assertNull($byId[$id]['parentId'], $id . ' is available as a first-level choice');
			$this->assertArrayHasKey('en', $byId[$id]['labels']);
			$this->assertArrayHasKey('de', $byId[$id]['labels']);
		}

		$this->assertSame('technology', $byId['tech.ai']['parentId']);
		$this->assertGreaterThanOrEqual(75, count($topics), 'The catalog should offer a broad set of selectable topics and subtopics.');
		$this->assertSame('food', $byId['food.plantbased']['parentId']);
		$this->assertSame('travel', $byId['travel.outdoors']['parentId']);
		$this->assertSame('transport', $byId['transport.rail']['parentId']);
		$this->assertContains('sensitive-topic', $byId['politics']['policyTags']);
		$this->assertNotContains('sensitive-topic', $byId['environment']['policyTags']);
	}
}
