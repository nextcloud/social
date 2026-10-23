<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/**
 * Stable, local topic identifiers and labels for the first For You release.
 * This catalog is deliberately data-only: resolving a topic never performs
 * network access or infers anything about the reader.
 */
final class RecommendationTopicCatalog {
	private const TOPICS = [
		['id' => 'technology', 'parentId' => null, 'labels' => ['en' => 'Technology', 'de' => 'Technologie'], 'aliases' => ['technology', 'technologie', 'tech']],
		['id' => 'tech.apple', 'parentId' => 'technology', 'labels' => ['en' => 'Apple', 'de' => 'Apple'], 'aliases' => ['apple', 'iphone', 'ipad', 'mac']],
		['id' => 'oss', 'parentId' => null, 'labels' => ['en' => 'Open source', 'de' => 'Open Source'], 'aliases' => ['open source', 'open-source', 'opensource']],
		['id' => 'oss.nextcloud', 'parentId' => 'oss', 'labels' => ['en' => 'Nextcloud', 'de' => 'Nextcloud'], 'aliases' => ['nextcloud']],
		['id' => 'politics', 'parentId' => null, 'labels' => ['en' => 'Politics', 'de' => 'Politik'], 'aliases' => ['politics', 'politik']],
		['id' => 'politics.international', 'parentId' => 'politics', 'labels' => ['en' => 'International politics', 'de' => 'Internationale Politik'], 'aliases' => ['international politics', 'internationale politik']],
		['id' => 'science', 'parentId' => null, 'labels' => ['en' => 'Science', 'de' => 'Wissenschaft'], 'aliases' => ['science', 'wissenschaft']],
		['id' => 'science.physics', 'parentId' => 'science', 'labels' => ['en' => 'Physics', 'de' => 'Physik'], 'aliases' => ['physics', 'physik']],
	];

	/** @return list<array{id: string, parentId: ?string, labels: array{en: string, de: string}, aliases: list<string>, policyTags: list<string>, languageHints: list<string>, version: int, enabled: bool}> */
	public function all(): array {
		return array_map(static fn (array $topic): array => $topic + [
			'policyTags' => str_starts_with($topic['id'], 'politics') ? ['sensitive-topic'] : [],
			'languageHints' => ['en', 'de'],
			'version' => 1,
			'enabled' => true,
		], self::TOPICS);
	}

	public function has(string $topicId): bool {
		foreach (self::TOPICS as $topic) {
			if ($topic['id'] === $topicId) {
				return true;
			}
		}

		return false;
	}

	/** @return array{id: string, parentId: ?string, labels: array{en: string, de: string}, aliases: list<string>, policyTags: list<string>, languageHints: list<string>, version: int, enabled: bool}|null */
	public function find(string $topicId): ?array {
		foreach ($this->all() as $topic) {
			if ($topic['id'] === $topicId) {
				return $topic;
			}
		}
		return null;
	}

	public function parentOf(string $topicId): ?string {
		foreach (self::TOPICS as $topic) {
			if ($topic['id'] === $topicId) {
				return $topic['parentId'];
			}
		}

		return null;
	}
}
