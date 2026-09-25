<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Turning what somebody pasted into the address a feed is actually read from.
 *
 * Nobody knows their own feed's address. What people have is the page: a blog,
 * a YouTube channel, a newsroom. Asking them for `/feeds/videos.xml?channel_id=UC…`
 * is asking them to do the work a browser has done automatically since 2005.
 *
 * Two jobs, in this order:
 *
 *  - **YouTube**, because its feed address cannot be guessed from the page
 *    address for anything but `/channel/UC…`. A handle, a `/c/` name, a `/user/`
 *    name and a video all name a channel without saying which one, and the id
 *    is in the page.
 *  - **Everything else**, by reading the page and taking what it declares —
 *    `<link rel="alternate" type="application/rss+xml">`, which is how a page
 *    has said "my feed is over here" for twenty years.
 *
 * Something that is already a feed is left alone: the cheapest answer is not
 * to fetch anything.
 */
class FeedDiscoveryService {
	/** How long one page fetch may take. */
	private const TIMEOUT = 20;

	/**
	 * How much of a page is read before giving up on finding a link.
	 *
	 * A `<link rel="alternate">` belongs in `<head>`, and a page whose head is
	 * past this is a page that has other problems. Bounded because this is
	 * reached from a request an ordinary reader made.
	 */
	private const MAX_HTML = 2 * 1024 * 1024;

	/** What a feed's own content type says, when the server is honest. */
	private const FEED_TYPES = [
		'application/rss+xml',
		'application/atom+xml',
		'application/xml',
		'text/xml',
		'application/rdf+xml',
		'application/json',
		'application/feed+json',
	];

	public function __construct(
		private IClientService $clientService,
	) {
	}

	/**
	 * The feed address behind what somebody pasted.
	 *
	 * @param string $input a feed, a page, or a YouTube channel
	 *
	 * @return string the address to read entries from
	 *
	 * @throws InvalidArgumentException it names nothing this can read
	 */
	public function discover(string $input): string {
		$url = $this->normalize($input);

		$youtube = $this->youtubeFeed($url);
		if ($youtube !== '') {
			return $youtube;
		}

		[$body, $contentType] = $this->fetch($url);
		if ($this->looksLikeFeed($body, $contentType)) {
			return $url;
		}

		$declared = $this->declaredFeed($body, $url);
		if ($declared !== '') {
			return $declared;
		}

		throw new InvalidArgumentException('that page does not say where its feed is');
	}

	/**
	 * A YouTube channel's feed, or '' where this is not YouTube.
	 *
	 * `/channel/UC…` carries the id, so it needs nothing fetched. Every other
	 * form — `@handle`, `/c/name`, `/user/name`, a video, a playlist — names a
	 * channel without saying which, and the id is in the page: YouTube puts it
	 * in a `<link rel="alternate" type="application/rss+xml">` of its own,
	 * which is the same thing this reads from any other site.
	 */
	public function youtubeFeed(string $url): string {
		$host = strtolower((string)parse_url($url, PHP_URL_HOST));
		if (!in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'], true)) {
			return '';
		}

		$path = (string)parse_url($url, PHP_URL_PATH);
		if (preg_match('#^/channel/(UC[A-Za-z0-9_-]{20,})#', $path, $matches) === 1) {
			return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $matches[1];
		}

		// a playlist is a feed of its own, and its id is in the address
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
		$list = (string)($query['list'] ?? '');
		if ($list !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $list) === 1) {
			return 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $list;
		}

		[$body] = $this->fetch($url);
		$declared = $this->declaredFeed($body, $url);
		if ($declared !== '') {
			return $declared;
		}

		// some pages carry the id without the link — a video page, above all
		if (preg_match('#"channelId":"(UC[A-Za-z0-9_-]{20,})"#', $body, $matches) === 1
			|| preg_match('#/channel/(UC[A-Za-z0-9_-]{20,})#', $body, $matches) === 1) {
			return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $matches[1];
		}

		throw new InvalidArgumentException('that YouTube page does not name a channel');
	}

	/**
	 * The feed a page declares, resolved against the page's own address.
	 *
	 * @param string $html the page
	 * @param string $base where it was read from
	 *
	 * @return string the feed address, or '' where the page declares none
	 */
	public function declaredFeed(string $html, string $base): string {
		if (preg_match_all('#<link\b[^>]*>#i', $html, $tags) === 0) {
			return '';
		}

		$candidates = [];
		foreach ($tags[0] as $tag) {
			$rel = $this->attribute($tag, 'rel');
			if ($rel === '' || !in_array('alternate', preg_split('/\s+/', strtolower($rel)) ?: [], true)) {
				continue;
			}

			$type = strtolower($this->attribute($tag, 'type'));
			if (!in_array($type, ['application/rss+xml', 'application/atom+xml', 'application/feed+json'], true)) {
				continue;
			}

			$href = $this->attribute($tag, 'href');
			if ($href === '') {
				continue;
			}

			// RSS before Atom before JSON only because a reader that offers
			// several usually offers the same entries in each, and the first
			// is the one it lists first
			$candidates[] = $this->absolute($href, $base);
		}

		return $candidates[0] ?? '';
	}

	/**
	 * What somebody typed, as an address worth fetching.
	 *
	 * A bare `example.org/blog` is what people paste; without a scheme it is
	 * not a URL at all and every check below would refuse it.
	 */
	public function normalize(string $input): string {
		$url = trim($input);
		if ($url === '') {
			throw new InvalidArgumentException('nothing to follow');
		}

		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
			$url = 'https://' . $url;
		}

		$parts = parse_url($url);
		if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
			throw new InvalidArgumentException('a feed is read over HTTP');
		}

		$host = strtolower($parts['host'] ?? '');
		// a name with a dot in it, and not an address: a feed lives on the
		// internet, and the two shapes that do not are the two somebody would
		// use to have this server read something only it can reach
		if ($host === '' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
			throw new InvalidArgumentException('that is not a host on the internet');
		}

		return $url;
	}

	/**
	 * @param string $url what to read
	 *
	 * @return array{0: string, 1: string} the body and the content type
	 */
	private function fetch(string $url): array {
		try {
			// the server's own client, which refuses addresses inside this
			// network unless an administrator has allowed them: this fetches
			// what a reader typed, and a reader could otherwise type the
			// address of something only this machine can reach
			$response = $this->clientService->newClient()->get($url, [
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/html;q=0.8, */*;q=0.5',
				],
				// only the head of a page is worth reading, and a body read
				// whole before cutting it is a body of any size in memory
				'stream' => true,
			]);
		} catch (Throwable $e) {
			throw new InvalidArgumentException('that address could not be read');
		}

		$body = substr(CurlService::readAtMost($response, self::MAX_HTML), 0, self::MAX_HTML);
		$contentType = $response->getHeader('Content-Type');

		return [$body, $contentType];
	}

	/**
	 * Whether what came back is already the entries.
	 *
	 * The content type first, because a server that says so is right; the body
	 * second, because plenty of servers hand a feed over as `text/plain`.
	 */
	private function looksLikeFeed(string $body, string $contentType): bool {
		$type = strtolower(trim(explode(';', $contentType)[0]));
		$head = ltrim(substr($body, 0, 1024));

		if (in_array($type, self::FEED_TYPES, true) && $type !== 'application/json') {
			return str_contains($head, '<rss') || str_contains($head, '<feed') || str_contains($head, '<rdf:RDF');
		}

		return preg_match('/^<\?xml|^<rss\b|^<feed\b|^<rdf:RDF\b/i', $head) === 1
			&& (str_contains($head, '<rss') || str_contains($head, '<feed') || str_contains($head, '<rdf:RDF'));
	}

	/** One attribute of one tag, however it was quoted. */
	private function attribute(string $tag, string $name): string {
		$pattern = '#\b' . preg_quote($name, '#') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i';
		if (preg_match($pattern, $tag, $matches) !== 1) {
			return '';
		}

		return html_entity_decode($matches[2] ?: ($matches[3] ?: ($matches[4] ?? '')), ENT_QUOTES | ENT_HTML5);
	}

	/** A href as a whole address, however the page wrote it. */
	private function absolute(string $href, string $base): string {
		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) === 1) {
			return $href;
		}

		$parts = parse_url($base);
		$scheme = $parts['scheme'] ?? 'https';
		$host = $parts['host'] ?? '';
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		$root = $scheme . '://' . $host . $port;

		if (str_starts_with($href, '//')) {
			return $scheme . ':' . $href;
		}

		if (str_starts_with($href, '/')) {
			return $root . $href;
		}

		$path = $parts['path'] ?? '/';
		$dir = substr($path, 0, (int)strrpos($path, '/') + 1);

		return $root . ($dir === '' ? '/' : $dir) . $href;
	}
}
