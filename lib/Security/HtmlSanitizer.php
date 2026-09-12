<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Security;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allowlist sanitizer for HTML received from remote instances.
 *
 * Mastodon and its relatives ship post bodies and profile bios as HTML. That
 * HTML ends up in the timeline of every local reader, so it has to be treated
 * as hostile: anything not explicitly permitted is removed.
 *
 * The allowlist mirrors what Mastodon itself accepts on ingest, so posts keep
 * their lists, quotes and code blocks instead of being flattened to text, while
 * everything with script potential — event handler attributes, `javascript:`
 * URLs, `<img>`, `<style>`, `<form>` — is dropped.
 *
 * Unknown elements are unwrapped rather than deleted: `<div>hello</div>` still
 * yields "hello". Only the elements whose *content* is itself dangerous or
 * meaningless without the element (`<script>`, `<style>`, …) are removed with
 * their children.
 *
 * Implemented on DOMDocument rather than with regular expressions: a parser
 * sees the same tree the browser will, which is what makes an allowlist
 * trustworthy against malformed or deliberately confusing markup.
 */
final class HtmlSanitizer {
	/**
	 * Elements that survive, mapped to the attributes each may keep.
	 *
	 * @var array<string, list<string>>
	 */
	private const ALLOWED_ELEMENTS = [
		'p' => [],
		'br' => [],
		'span' => ['class', 'translate'],
		'a' => ['href', 'rel', 'class', 'translate'],
		'del' => [],
		's' => [],
		'pre' => [],
		'blockquote' => ['cite'],
		'code' => [],
		'b' => [],
		'strong' => [],
		'u' => [],
		'i' => [],
		'em' => [],
		'ul' => [],
		'ol' => ['start', 'reversed'],
		'li' => ['value'],
		'h1' => [],
		'h2' => [],
		'h3' => [],
		'h4' => [],
		'h5' => [],
		'h6' => [],
	];

	/**
	 * Elements removed together with everything inside them.
	 *
	 * Their text content is not prose, so unwrapping would leak script source or
	 * stylesheet rules into the post body.
	 *
	 * @var list<string>
	 */
	private const DROPPED_WITH_CONTENT = [
		'script',
		'style',
		'iframe',
		'object',
		'embed',
		'noscript',
		'template',
		'svg',
		'math',
		'head',
		'title',
	];

	/**
	 * URL schemes an `<a href>` may use.
	 *
	 * Matches Mastodon's list. Scheme-less (relative or protocol-relative) URLs
	 * are allowed as well, because a remote instance may link to its own paths.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_SCHEMES = [
		'http',
		'https',
		'dat',
		'dweb',
		'ipfs',
		'ipns',
		'ssb',
		'gopher',
		'xmpp',
		'magnet',
		'gemini',
	];

	/**
	 * Class names a `<span>` or `<a>` may carry.
	 *
	 * Mastodon marks mentions, hashtags and shortened links this way, and the
	 * frontend renders them accordingly. Anything else is stripped so remote
	 * markup cannot borrow the look of local UI.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_CLASSES = [
		'mention',
		'hashtag',
		'ellipsis',
		'invisible',
		'h-card',
		'u-url',
		'p-name',
		'p-author',
		'quote-inline',
	];

	/**
	 * Reduce untrusted HTML to the allowed subset.
	 *
	 * Returns a fragment (no `<html>`/`<body>` wrapper) suitable for storing and
	 * for handing to the client as-is.
	 */
	public static function sanitize(string $html): string {
		if (trim($html) === '') {
			return '';
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			// The meta tag pins the charset so multi-byte text is not mangled;
			// the wrapper gives every top-level node a common parent to walk
			$document->loadHTML(
				'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="social-sanitizer-root">'
					. $html
					. '</div></body></html>',
				LIBXML_NONET,
			);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		$root = $document->getElementById('social-sanitizer-root');
		if ($root === null) {
			return '';
		}

		self::sanitizeChildren($root);

		$result = '';
		foreach ($root->childNodes as $child) {
			$result .= $document->saveHTML($child);
		}

		return $result;
	}

	/**
	 * Walk the children of a node, removing, unwrapping or cleaning each.
	 *
	 * Iterates over a snapshot because the live NodeList changes underneath us
	 * as nodes are replaced.
	 */
	private static function sanitizeChildren(DOMNode $parent): void {
		$children = [];
		foreach ($parent->childNodes as $child) {
			$children[] = $child;
		}

		foreach ($children as $child) {
			if ($child instanceof DOMElement) {
				self::sanitizeNode($child);
			} elseif (!($child instanceof DOMText)) {
				// Comments, processing instructions, CDATA: nothing a post needs
				$parent->removeChild($child);
			}
		}
	}

	/**
	 * Remove, unwrap or clean one element that hangs off an already clean parent.
	 */
	private static function sanitizeNode(DOMElement $element): void {
		$parent = $element->parentNode;
		if ($parent === null) {
			return;
		}

		$name = strtolower($element->tagName);

		if (in_array($name, self::DROPPED_WITH_CONTENT, true)) {
			$parent->removeChild($element);
			return;
		}

		if (!array_key_exists($name, self::ALLOWED_ELEMENTS)) {
			self::unwrap($element);
			return;
		}

		self::sanitizeAttributes($element, self::ALLOWED_ELEMENTS[$name]);
		self::sanitizeChildren($element);
	}

	/**
	 * Replace an element by its own children, then sanitize those in place.
	 *
	 * The lifted children were not part of the caller's snapshot, so they are
	 * checked here rather than being skipped.
	 */
	private static function unwrap(DOMElement $element): void {
		$parent = $element->parentNode;
		if ($parent === null) {
			return;
		}

		$children = [];
		foreach ($element->childNodes as $child) {
			$children[] = $child;
		}

		foreach ($children as $child) {
			$parent->insertBefore($child, $element);
		}
		$parent->removeChild($element);

		foreach ($children as $child) {
			if ($child instanceof DOMElement) {
				self::sanitizeNode($child);
			} elseif (!($child instanceof DOMText)) {
				$parent->removeChild($child);
			}
		}
	}

	/**
	 * Drop every attribute not in the allowlist, and validate the ones kept.
	 *
	 * @param list<string> $allowed
	 */
	private static function sanitizeAttributes(DOMElement $element, array $allowed): void {
		$names = [];
		foreach ($element->attributes as $attribute) {
			$names[] = $attribute->nodeName;
		}

		foreach ($names as $attributeName) {
			$lower = strtolower($attributeName);
			if (!in_array($lower, $allowed, true)) {
				$element->removeAttribute($attributeName);
				continue;
			}

			$value = $element->getAttribute($attributeName);
			switch ($lower) {
				case 'href':
				case 'cite':
					if (!self::isAllowedUrl($value)) {
						$element->removeAttribute($attributeName);
					}
					break;
				case 'class':
					$classes = array_filter(
						preg_split('/\s+/', trim($value)) ?: [],
						static fn (string $class): bool => in_array($class, self::ALLOWED_CLASSES, true),
					);
					if ($classes === []) {
						$element->removeAttribute($attributeName);
					} else {
						$element->setAttribute($attributeName, implode(' ', $classes));
					}
					break;
				case 'start':
				case 'value':
					if (!preg_match('/^-?\d+$/', $value)) {
						$element->removeAttribute($attributeName);
					}
					break;
				case 'translate':
					if (!in_array(strtolower($value), ['yes', 'no'], true)) {
						$element->removeAttribute($attributeName);
					}
					break;
			}
		}

		if (strtolower($element->tagName) === 'a' && $element->hasAttribute('href')) {
			// Whatever the remote sent, a link out of a timeline must neither
			// pass referrers nor hand over window.opener
			$element->setAttribute('rel', 'nofollow noopener noreferrer');
			$element->setAttribute('target', '_blank');
		}
	}

	/**
	 * Whether a URL uses an allowed scheme, or none at all.
	 *
	 * Control characters and whitespace are stripped before looking at the
	 * scheme, because browsers ignore them — `java\tscript:` is still executed.
	 */
	public static function isAllowedUrl(string $url): bool {
		$normalized = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';
		if ($normalized === '') {
			return false;
		}

		if (!preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalized, $match)) {
			// No scheme: relative, protocol-relative or fragment link
			return true;
		}

		return in_array(strtolower($match[1]), self::ALLOWED_SCHEMES, true);
	}
}
