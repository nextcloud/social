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
	public function testCatalogProvidesBroadAutomaticSignalsWithStableHierarchy(): void {
		$topics = (new RecommendationTopicCatalog())->all();
		$byId = array_column($topics, null, 'id');

		foreach (['software', 'gaming', 'business', 'environment', 'culture', 'sports', 'photography', 'education', 'science', 'health'] as $id) {
			$this->assertArrayHasKey($id, $byId);
			$this->assertNull($byId[$id]['parentId'], $id . ' is a first-level classifier group');
			$this->assertArrayHasKey('en', $byId[$id]['labels']);
			$this->assertArrayHasKey('de', $byId[$id]['labels']);
		}

		$this->assertSame('technology', $byId['tech.ai']['parentId']);
		$this->assertGreaterThanOrEqual(125, count($topics), 'Automatic classification needs broad coverage across common subjects.');
		$this->assertSame('food', $byId['food.plantbased']['parentId']);
		$this->assertSame('travel', $byId['travel.outdoors']['parentId']);
		$this->assertSame('transport', $byId['transport.rail']['parentId']);
		$this->assertContains('sensitive-topic', $byId['politics']['policyTags']);
		$this->assertContains('sensitive-topic', $byId['health']['policyTags']);
		$this->assertContains('sensitive-topic', $byId['identity.lgbtq']['policyTags']);
		$this->assertContains('sensitive-topic', $byId['religion.history']['policyTags']);
		$this->assertNotContains('sensitive-topic', $byId['environment']['policyTags']);
		$this->assertSame(RecommendationTopicCatalog::VERSION, $byId['science.climate']['version']);
		$this->assertSame(count($topics), count($byId), 'Topic identifiers must be unique.');

		foreach ($topics as $topic) {
			if ($topic['parentId'] !== null) {
				$this->assertArrayHasKey($topic['parentId'], $byId, $topic['id'] . ' must have a known parent');
			}
			$this->assertNotEmpty($topic['aliases'], $topic['id'] . ' must be matchable from content');
		}
	}

	public function testTopicLookupUsesStableIdsAndReturnsNullForUnknownIds(): void {
		$catalog = new RecommendationTopicCatalog();
		$this->assertTrue($catalog->has('science.climate'));
		$this->assertSame('science', $catalog->parentOf('science.climate'));
		$this->assertNull($catalog->find('unknown.topic'));
		$this->assertFalse($catalog->has('unknown.topic'));
	}
}
