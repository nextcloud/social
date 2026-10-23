<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/** Normalizes For You media filters and applies them consistently to candidates. */
final class RecommendationMediaFilter {
	private const FILTERS = ['', 'photos', 'videos', 'shorts', 'media'];

	public static function normalize(string $media): string {
		return in_array($media, self::FILTERS, true) ? $media : '';
	}

	public static function matches(string $format, string $media): bool {
		return match ($media) {
			'photos' => $format === 'image',
			'videos' => in_array($format, ['video', 'short'], true),
			'shorts' => $format === 'short',
			'media' => in_array($format, ['image', 'video', 'short'], true),
			default => true,
		};
	}
}
