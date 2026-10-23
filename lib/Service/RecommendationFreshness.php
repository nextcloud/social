<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;

/** Conservative, local-only freshness rules for recommendation candidates. */
final class RecommendationFreshness {
	public const LOOKBACK_DAYS = 180;
	public const RECENT_WINDOW_DAYS = 30;

	private const HISTORICAL_SHARE = 0.20;
	private const HALF_LIFE_HOURS = [
		'breaking' => 6.0,
		'security' => 24.0 * 14.0,
		'evergreen' => 24.0 * 90.0,
		'announcement' => 24.0 * 7.0,
		'default' => 24.0,
	];

	public static function recentCandidateLimit(int $total): int {
		$total = max(1, $total);
		$historical = min($total - 1, max(1, (int)floor((float)$total * self::HISTORICAL_SHARE)));

		return $total - $historical;
	}

	public static function historicalCandidateLimit(int $total): int {
		return max(0, $total - self::recentCandidateLimit($total));
	}

	public static function score(Stream $post, int $now, string $format = ''): float {
		$publishedAt = $post->getPublishedTime();
		if ($publishedAt <= 0 || $now <= 0) {
			return 0.5;
		}

		$ageHours = max(0, $now - $publishedAt) / 3600;
		return self::scoreAgeHours($ageHours, self::contentClass($post->getContent()), $format, $post->isVideo());
	}

	public static function scoreAgeHours(float $ageHours, string $contentClass = 'default', string $format = '', bool $isVideo = false): float {
		if (!is_finite($ageHours)) {
			return 0.5;
		}
		$ageHours = max(0.0, $ageHours);
		if (!isset(self::HALF_LIFE_HOURS[$contentClass])) {
			$contentClass = 'default';
		}
		$halfLife = $contentClass === 'default' && ($format === 'short' || $format === 'video' || $isVideo)
			? 12.0
			: self::HALF_LIFE_HOURS[$contentClass];

		return exp(-0.6931471805599453 * $ageHours / $halfLife);
	}

	public static function contentClass(string $content): string {
		$plain = html_entity_decode(ACore::withoutMarkup(mb_substr($content, 0, 8000, 'UTF-8')), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
		$plain = mb_strtolower(mb_scrub($plain, 'UTF-8'), 'UTF-8');

		if (preg_match('/(*UCP)\b(breaking|developing story|live updates?|live blog|liveblog|eilmeldung|live[- ]?ticker)\b/u', $plain) === 1) {
			return 'breaking';
		}
		if (preg_match('/(*UCP)\b(security advisory|security update|vulnerabilit(?:y|ies)|cve-\d{4}-\d{3,}|sicherheitswarnung|sicherheitsl(?:ücke|uecke)|sicherheitsupdate|schwachstelle)\b/u', $plain) === 1) {
			return 'security';
		}
		if (preg_match('/(*UCP)\b(tutorials?|how[- ]?to|documentation|docs|guides?|troubleshooting|migration guide|anleitung(?:en)?|handbuch|handbücher|dokumentation(?:en)?|fehlerbehebung(?:en)?|migrationsleitfaden)\b/u', $plain) === 1) {
			return 'evergreen';
		}
		if (preg_match('/(*UCP)\b(releases?|release notes|launch(?:es|ed)?|announcements?|announces|announced|new version|veröffentlich(?:t|ung(?:en)?)|ankündigung(?:en)?|kündigt an)\b/u', $plain) === 1) {
			return 'announcement';
		}

		return 'default';
	}

}
