<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Security\HtmlSanitizer;

/**
 * Turns the plain text somebody typed into the HTML every other implementation
 * publishes: paragraphs, and links for URLs, mentions and hashtags.
 *
 * Outbound content used to be `nl2br(htmlentities($content))`, so a post left
 * this instance as a wall of escaped text. Mastodon, GoToSocial, Pleroma and
 * the rest all send `content` as HTML and render what they are given without
 * looking for anything to linkify, so every link, mention and hashtag written
 * here arrived on every peer as dead text — a mention was not clickable, a
 * hashtag did not reach a tag timeline, and a URL was not a URL.
 *
 * **Escaping.** The text is escaped first and markup is only ever built around
 * the escaped pieces; nothing is un-escaped and no markup is assembled by
 * interpolating raw input. A `<script>` somebody types is `&lt;script&gt;` in
 * the output whatever else is going on around it.
 *
 * **Agreement with `tag`.** The entities are found once, by
 * {@see self::entitiesIn()}, and that same list is what `PostService` extracts
 * recipients and hashtags from and what `StreamService` builds the `tag` array
 * out of. A link is only ever emitted for an entity that has a `tag` entry, so
 * the markup cannot name somebody the `tag` array does not, which is what a
 * receiving instance checks a mention against before it notifies anybody.
 */
class LinkifyService {
	public const TYPE_URL = 'url';
	public const TYPE_MENTION = 'mention';
	public const TYPE_HASHTAG = 'hashtag';

	/**
	 * The one pass over the text.
	 *
	 * URLs come first in the alternation so that everything inside one belongs
	 * to it: `https://example.invalid/#section` is a link and not a link
	 * followed by a hashtag, and `https://example.invalid/@bob` names no
	 * account.
	 *
	 * A mention is not preceded by a word character, which is what keeps
	 * `frank@example.org` an email address rather than a mention of `example`;
	 * a hashtag is not preceded by a word character either (`issue#42`) nor by
	 * `&`, which would make an HTML entity into one.
	 *
	 * Neither handle nor tag may end in punctuation — the domain is spelled out
	 * as labels rather than as "any run of dots and letters" — so a sentence
	 * ending in `@bob@example.invalid.` mentions the right person.
	 */
	private const PATTERN = '~'
		. '(?P<url>\bhttps?://[^\s<>"]+)'
		. '|(?<![\w@])@(?P<mention>[\w\-]+(?:\.[\w\-]+)*(?:@[\w\-]+(?:\.[\w\-]+)+)?)'
		. '|(?<![\w&])\#(?P<hashtag>[\p{L}\p{N}_]+)'
		. '~u';

	/** Trailing characters that end a sentence rather than a URL. */
	private const URL_TRAILING = '.,;:!?\'"';

	/** Closing brackets that belong to the URL only if it opened them. */
	private const URL_BRACKETS = [')' => '(', ']' => '[', '}' => '{'];

	/**
	 * Every URL, mention and hashtag in the text, in the order they appear.
	 *
	 * `text` is the matched substring, `name` is it without its leading sigil,
	 * and `offset` is a byte offset into `$text`.
	 *
	 * @return list<array{type: string, text: string, name: string, offset: int}>
	 */
	public function entitiesIn(string $text): array {
		if (preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
			// invalid UTF-8: no entity is better than a half-parsed one, and
			// the caller still escapes and publishes the text
			return [];
		}

		$entities = [];
		foreach ($matches as $match) {
			foreach ([self::TYPE_URL, self::TYPE_MENTION, self::TYPE_HASHTAG] as $type) {
				if (($match[$type][1] ?? -1) < 0) {
					continue;
				}

				$name = $match[$type][0];
				if ($type === self::TYPE_URL) {
					$name = $this->withoutTrailingPunctuation($name);
				}

				$entities[] = [
					'type' => $type,
					'name' => $name,
					'text' => $type === self::TYPE_URL
						? $name
						: ($type === self::TYPE_MENTION ? '@' : '#') . $name,
					'offset' => $match[0][1],
				];
			}
		}

		return $entities;
	}

	/**
	 * The outbound `content` of a post: the text as HTML, with a link for every
	 * entity the `tag` array vouches for.
	 *
	 * @param array<int, array<string, string>> $tags the note's `tag` array,
	 *                                                as `StreamService` built it
	 */
	public function toHtml(string $text, array $tags): string {
		$hrefs = $this->hrefsByName($tags);

		$html = '';
		$cursor = 0;
		foreach ($this->entitiesIn($text) as $entity) {
			$html .= $this->escape(substr($text, $cursor, $entity['offset'] - $cursor));
			$html .= $this->markup($entity, $hrefs);
			$cursor = $entity['offset'] + strlen($entity['text']);
		}
		$html .= $this->escape(substr($text, $cursor));

		return $this->paragraphs($html);
	}

	/**
	 * The href each entity may be linked to, keyed by lower-cased name.
	 *
	 * An entity with no entry gets no link: a mention of somebody who could not
	 * be resolved has no `tag` entry either, and a link to an actor this
	 * instance never found would be a link to nowhere.
	 *
	 * @param array<int, array<string, string>> $tags
	 * @return array{mention: array<string, string>, hashtag: array<string, string>}
	 */
	private function hrefsByName(array $tags): array {
		$hrefs = [self::TYPE_MENTION => [], self::TYPE_HASHTAG => []];

		foreach ($tags as $tag) {
			$type = match ((string)($tag['type'] ?? '')) {
				'Mention' => self::TYPE_MENTION,
				'Hashtag' => self::TYPE_HASHTAG,
				default => '',
			};
			$name = ltrim((string)($tag['name'] ?? ''), '@#');
			$href = (string)($tag['href'] ?? '');

			// a tag whose href this app would refuse to render from a peer is
			// not one it may emit either
			if ($type === '' || $name === '' || $href === '' || !HtmlSanitizer::isAllowedUrl($href)) {
				continue;
			}

			$hrefs[$type][mb_strtolower($name)] = $href;
		}

		return $hrefs;
	}

	/**
	 * @param array{type: string, text: string, name: string, offset: int} $entity
	 * @param array{mention: array<string, string>, hashtag: array<string, string>} $hrefs
	 */
	private function markup(array $entity, array $hrefs): string {
		if ($entity['type'] === self::TYPE_URL) {
			return $this->anchor($entity['name'], $entity['text'], '');
		}

		$href = $hrefs[$entity['type']][mb_strtolower($entity['name'])] ?? '';
		if ($href === '') {
			return $this->escape($entity['text']);
		}

		// Mastodon's own classes: `u-url mention` is what marks an h-card link
		// to an actor, `mention hashtag` with `rel="tag"` what marks a tag. The
		// frontend reads the same two, so a post written here renders the way
		// one from a peer does.
		//
		// The label is the whole handle as it was typed rather than Mastodon's
		// shortened `@bob`: the composer rebuilds its text from this HTML when
		// a post is edited, and a label without the domain would turn every
		// remote mention into a local one on the first edit.
		return $entity['type'] === self::TYPE_MENTION
			? $this->anchor($href, $entity['text'], 'u-url mention')
			: $this->anchor($href, $entity['text'], 'mention hashtag', 'tag');
	}

	private function anchor(string $href, string $label, string $class, string $rel = ''): string {
		if (!HtmlSanitizer::isAllowedUrl($href)) {
			return $this->escape($label);
		}

		$attributes = ' href="' . $this->escape($href) . '"';
		if ($class !== '') {
			$attributes .= ' class="' . $this->escape($class) . '"';
		}
		// a bare link out of a post must neither pass a referrer nor hand over
		// window.opener, which is the rule HtmlSanitizer applies to an incoming
		// one
		$attributes .= ' rel="' . $this->escape($rel !== '' ? $rel : 'nofollow noopener noreferrer') . '"';

		return '<a' . $attributes . '>' . $this->escape($label) . '</a>';
	}

	/**
	 * Blank-line-separated blocks become paragraphs and every other newline a
	 * break, which is the shape Mastodon publishes and the only one a peer
	 * renders as written — a bare newline in HTML is whitespace.
	 */
	private function paragraphs(string $html): string {
		$blocks = preg_split('~\R[ \t]*\R\s*~u', trim($html, "\r\n")) ?: [];

		$paragraphs = '';
		foreach ($blocks as $block) {
			$block = trim($block, "\r\n");
			if (trim($block) === '') {
				continue;
			}

			$paragraphs .= '<p>' . preg_replace('~\R~u', '<br />', $block) . '</p>';
		}

		return $paragraphs;
	}

	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);
	}

	/**
	 * A URL runs to the end of the word, and the end of a word is where a
	 * sentence's punctuation begins. A closing bracket is kept only when the
	 * URL opened it, so `(see https://example.invalid/a_(b))` keeps its inner
	 * pair and loses the outer one.
	 */
	private function withoutTrailingPunctuation(string $url): string {
		while ($url !== '') {
			$last = substr($url, -1);

			if (str_contains(self::URL_TRAILING, $last)) {
				$url = substr($url, 0, -1);
				continue;
			}

			if (isset(self::URL_BRACKETS[$last])
				&& substr_count($url, self::URL_BRACKETS[$last]) < substr_count($url, $last)) {
				$url = substr($url, 0, -1);
				continue;
			}

			break;
		}

		return $url;
	}
}
