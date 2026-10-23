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
		['id' => 'tech.ai', 'parentId' => 'technology', 'labels' => ['en' => 'Artificial intelligence', 'de' => 'Künstliche Intelligenz'], 'aliases' => ['artificial intelligence', 'machine learning', 'generative ai', 'künstliche intelligenz', 'maschinelles lernen', 'generative ki']],
		['id' => 'oss', 'parentId' => null, 'labels' => ['en' => 'Open source', 'de' => 'Open Source'], 'aliases' => ['open source', 'open-source', 'opensource']],
		['id' => 'oss.nextcloud', 'parentId' => 'oss', 'labels' => ['en' => 'Nextcloud', 'de' => 'Nextcloud'], 'aliases' => ['nextcloud']],
		['id' => 'software', 'parentId' => null, 'labels' => ['en' => 'Software development', 'de' => 'Softwareentwicklung'], 'aliases' => ['software development', 'software engineering', 'programming', 'coding', 'softwareentwicklung', 'programmierung']],
		['id' => 'gaming', 'parentId' => null, 'labels' => ['en' => 'Gaming', 'de' => 'Gaming'], 'aliases' => ['gaming', 'video games', 'videogames', 'videospiele', 'computerspiele']],
		['id' => 'business', 'parentId' => null, 'labels' => ['en' => 'Business and economics', 'de' => 'Wirtschaft'], 'aliases' => ['business', 'economics', 'economy', 'startups', 'unternehmen', 'wirtschaft', 'ökonomie']],
		['id' => 'environment', 'parentId' => null, 'labels' => ['en' => 'Environment and climate', 'de' => 'Umwelt und Klima'], 'aliases' => ['environment', 'climate change', 'climate', 'umwelt', 'klimawandel', 'klima']],
		['id' => 'culture', 'parentId' => null, 'labels' => ['en' => 'Culture and arts', 'de' => 'Kultur und Kunst'], 'aliases' => ['culture', 'arts', 'art history', 'kultur', 'kunst']],
		['id' => 'sports', 'parentId' => null, 'labels' => ['en' => 'Sports', 'de' => 'Sport'], 'aliases' => ['sports', 'sport', 'football', 'soccer', 'basketball', 'tennis', 'fußball']],
		['id' => 'photography', 'parentId' => null, 'labels' => ['en' => 'Photography', 'de' => 'Fotografie'], 'aliases' => ['photography', 'photographer', 'fotografie', 'fotograf']],
		['id' => 'education', 'parentId' => null, 'labels' => ['en' => 'Education', 'de' => 'Bildung'], 'aliases' => ['education', 'teaching', 'learning', 'bildung', 'lehre', 'lernen']],
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
