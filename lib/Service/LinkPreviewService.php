<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Exceptions\CardNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamCard;
use Psr\Log\LoggerInterface;

/**
 * Link preview cards. A card is what a linked page says about itself
 * (OpenGraph, with the plain HTML title/description as fallback); it is never
 * federated, so every instance reads the page itself, once per post, from a
 * background job.
 *
 * Fetching goes through CurlService, which keeps the guarantees that matter
 * for a URL somebody else wrote: HTTP(S) only on the request and on every
 * redirect, no local addresses, a download size cap, and the instance access
 * list. A strict allow-list therefore also limits where previews come from.
 */
class LinkPreviewService {
	/** Enough for the <head> of any sane page; the fetch stops when it is reached. */
	public const MAX_HTML = 512000;

	private const TIMEOUT = 5;

	public function __construct(
		private StreamCardsRequest $streamCardsRequest,
		private CurlService $curlService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The first link in a post's content, or '' when it carries none. Mentions
	 * and hashtags are links too, so only plain external links count.
	 */
	public function extractUrl(string $content): string {
		if ($content === '') {
			return '';
		}

		// a mention or a hashtag is a link too, and the class that says so sits
		// on the anchor (this app) or on a wrapping span (Mastodon): drop both
		// whole elements before looking for a link worth previewing
		$plain = preg_replace(
			[
				'/<span\b[^>]*class=["\'][^"\']*\b(?:mention|hashtag)\b[^"\']*["\'][^>]*>.*?<\/span>/is',
				'/<a\b[^>]*class=["\'][^"\']*\b(?:mention|hashtag|u-url)\b[^"\']*["\'][^>]*>.*?<\/a>/is',
			],
			' ',
			$content
		) ?? $content;

		// an anchor the composer or a remote server built
		if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $plain, $anchors, PREG_SET_ORDER)) {
			foreach ($anchors as $anchor) {
				$url = html_entity_decode($anchor[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
				if ($this->isPreviewable($url)) {
					return $url;
				}
			}

			return '';
		}

		// plain text (a post written through the API without markup)
		if (preg_match('/https?:\/\/[^\s<>"\']+/i', strip_tags($plain), $match) === 1) {
			$url = rtrim($match[0], '.,;:!?)');

			return $this->isPreviewable($url) ? $url : '';
		}

		return '';
	}

	/**
	 * Reads the page a post links to and stores the card. Returns false when
	 * there is nothing worth showing — no link, an unreadable page, or a page
	 * that says nothing about itself.
	 */
	public function generate(Stream $post): bool {
		$url = $this->extractUrl($post->getContent());
		if ($url === '') {
			return false;
		}

		try {
			$html = $this->fetch($url);
		} catch (Exception $e) {
			$this->logger->debug('could not read the linked page', ['url' => $url, 'exception' => $e]);

			return false;
		}

		$card = $this->parse($post->getId(), $url, $html);
		if ($card->isEmpty()) {
			return false;
		}

		$this->streamCardsRequest->save($card);

		return true;
	}

	/**
	 * Attaches the stored cards to a page of posts in one query.
	 *
	 * @param Stream[] $posts
	 */
	public function attachCards(array $posts): void {
		if ($posts === []) {
			return;
		}

		$ids = [];
		foreach ($posts as $post) {
			if ($post instanceof Stream) {
				$ids[] = $post->getId();
			}
		}

		$cards = $this->streamCardsRequest->getByStreamIds($ids);
		if ($cards === []) {
			return;
		}

		foreach ($posts as $post) {
			if (!$post instanceof Stream) {
				continue;
			}
			$prim = md5($post->getId());
			if (array_key_exists($prim, $cards)) {
				$post->setCard($cards[$prim]);
			}
		}
	}

	public function attachCard(Stream $post): void {
		try {
			$post->setCard($this->streamCardsRequest->getByStreamId($post->getId()));
		} catch (CardNotFoundException $e) {
		}
	}

	public function deleteCard(string $streamId): void {
		$this->streamCardsRequest->deleteByStreamId($streamId);
	}

	/**
	 * A link is previewable when it is a plain http(s) URL to somewhere else.
	 * Everything beyond that — local addresses, redirects to other protocols,
	 * oversized bodies, blocked hosts — is CurlService's job.
	 */
	private function isPreviewable(string $url): bool {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true)) {
			return false;
		}

		return (string)parse_url($url, PHP_URL_HOST) !== '';
	}

	/**
	 * @throws Exception
	 */
	private function fetch(string $url): string {
		return substr(
			$this->curlService->doRequest('get', $url, [
				'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
				'timeout' => self::TIMEOUT,
			]),
			0,
			self::MAX_HTML
		);
	}

	/**
	 * OpenGraph first, then Twitter cards, then the plain HTML title and
	 * meta description. Everything read here was written by the page, so it
	 * is decoded and length-capped, never trusted as markup.
	 */
	private function parse(string $streamId, string $url, string $html): StreamCard {
		$card = new StreamCard($streamId, $url);
		if ($html === '') {
			return $card;
		}

		$meta = $this->metaTags($html);
		$card->setTitle($this->firstOf($meta, ['og:title', 'twitter:title']) ?: $this->htmlTitle($html));
		$card->setDescription($this->firstOf($meta, ['og:description', 'twitter:description', 'description']));
		$card->setProviderName($this->firstOf($meta, ['og:site_name']) ?: (string)parse_url($url, PHP_URL_HOST));

		$image = $this->firstOf($meta, ['og:image', 'og:image:url', 'twitter:image']);
		if ($image !== '') {
			$image = $this->absoluteUrl($image, $url);
			// the client loads this straight from the origin; only plain
			// http(s) may ever reach an <img src>
			if ($this->isPreviewable($image)) {
				$card->setImage($image);
			}
		}

		return $card;
	}

	/**
	 * @return array<string, string> meta name/property => content
	 */
	private function metaTags(string $html): array {
		$tags = [];
		if (preg_match_all('/<meta\s[^>]*>/i', $html, $matches) === false) {
			return $tags;
		}

		foreach ($matches[0] as $tag) {
			if (preg_match('/(?:property|name)\s*=\s*["\']([^"\']+)["\']/i', $tag, $key) !== 1) {
				continue;
			}
			if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $tag, $value) !== 1) {
				continue;
			}
			$name = strtolower(trim($key[1]));
			if (!array_key_exists($name, $tags)) {
				$tags[$name] = html_entity_decode($value[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}

		return $tags;
	}

	/**
	 * @param array<string, string> $meta
	 * @param string[] $names
	 */
	private function firstOf(array $meta, array $names): string {
		foreach ($names as $name) {
			$value = trim($meta[$name] ?? '');
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}

	private function htmlTitle(string $html): string {
		if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match) !== 1) {
			return '';
		}

		return trim(html_entity_decode(
			preg_replace('/\s+/', ' ', strip_tags($match[1])) ?? '',
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		));
	}

	private function absoluteUrl(string $url, string $base): string {
		// anything that carries a scheme of its own is left as it is, so that
		// a data: or javascript: value cannot be laundered into an http one
		if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url) === 1) {
			return $url;
		}

		$parsed = parse_url($base);
		$root = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
		if (str_starts_with($url, '//')) {
			return ($parsed['scheme'] ?? 'https') . ':' . $url;
		}
		if (str_starts_with($url, '/')) {
			return $root . $url;
		}

		$path = $parsed['path'] ?? '/';

		return $root . substr($path, 0, (int)strrpos($path, '/') + 1) . $url;
	}
}
