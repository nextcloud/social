<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\ACore;

/** Builds short-lived local fingerprints for near-duplicate story detection. */
final class RecommendationStorySimilarity {
	private const MAX_TERMS = 200;
	private const MAX_CONTENT_CHARS = 20000;
	private const MIN_SHARED_TERMS = 4;
	private const MIN_OVERLAP = 0.35;
	private const STOP_WORDS = [
		'a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'but', 'by', 'for', 'from', 'has', 'have', 'he', 'her', 'his', 'i', 'if', 'in', 'is', 'it', 'its', 'of', 'on', 'or', 'our', 'she', 'so', 'that', 'the', 'their', 'them', 'there', 'they', 'this', 'to', 'was', 'we', 'were', 'will', 'with', 'you', 'your',
		'ab', 'aber', 'als', 'am', 'an', 'auch', 'auf', 'aus', 'bei', 'bin', 'bis', 'das', 'dass', 'dem', 'den', 'der', 'des', 'die', 'ein', 'eine', 'einer', 'eines', 'er', 'es', 'für', 'hat', 'haben', 'ich', 'im', 'in', 'ist', 'mit', 'nach', 'nicht', 'oder', 'sein', 'sie', 'sind', 'so', 'und', 'von', 'war', 'werden', 'wie', 'wir', 'zu', 'zum', 'zur',
	];

	/** @return list<string> */
	public static function terms(string $html): array {
		$html = mb_substr($html, 0, self::MAX_CONTENT_CHARS, 'UTF-8');
		$html = preg_replace('#```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\r\n]*`#u', ' ', $html) ?? $html;
		$html = preg_replace('/^[\t ]{0,3}>.*(?:\R|$)/mu', ' ', $html) ?? $html;
		$html = preg_replace('#<(blockquote|pre|code)\b[^>]*>.*?</\1\s*>#isu', ' ', $html) ?? $html;
		$text = ACore::withoutMarkup(str_replace('<', ' <', $html));
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
		$text = mb_strtolower(mb_scrub($text, 'UTF-8'), 'UTF-8');
		$rawTerms = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		$terms = [];
		foreach (($rawTerms === false) ? [] : $rawTerms as $term) {
			if (mb_strlen($term, 'UTF-8') < 3 || in_array($term, self::STOP_WORDS, true)) {
				continue;
			}
			$terms[$term] = true;
			if (count($terms) >= self::MAX_TERMS) {
				break;
			}
		}

		return array_keys($terms);
	}

	/** @param list<string> $left @param list<string> $right */
	public static function areSimilar(array $left, array $right): bool {
		$left = array_fill_keys($left, true);
		$right = array_fill_keys($right, true);
		$smaller = min(count($left), count($right));
		if ($smaller === 0) {
			return false;
		}

		$shared = count(array_intersect_key($left, $right));

		return $shared >= self::MIN_SHARED_TERMS && $shared / $smaller >= self::MIN_OVERLAP;
	}
}
