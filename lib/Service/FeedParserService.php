<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use SimpleXMLElement;

/**
 * A feed document, as the entries it lists.
 *
 * RSS 2.0, Atom and RDF, which is every feed anybody publishes. They disagree
 * about the name of everything — `item`/`entry`, `pubDate`/`published`,
 * `description`/`summary` — and agree that each entry has a link, a title, a
 * date and an id, so that is what comes out.
 *
 * Read with `SimpleXML` and entity loading off. A feed is a document from
 * somebody else's server, and an XML parser that resolves external entities
 * will fetch whatever the document tells it to, including files off this disk.
 */
class FeedParserService {
	/** Namespaces a feed uses for the things RSS never had. */
	private const NS_MEDIA = 'http://search.yahoo.com/mrss/';
	private const NS_ATOM = 'http://www.w3.org/2005/Atom';
	private const NS_DC = 'http://purl.org/dc/elements/1.1/';

	/** RSS 1.0 — the elements of an RDF feed live here, not beside `rdf:RDF`. */
	private const NS_RSS1 = 'http://purl.org/rss/1.0/';

	/** How many entries one read takes from one feed. */
	public const MAX_ITEMS = 100;

	/** How long a summary is kept. A feed reader is a list, not a reader. */
	private const SUMMARY = 500;

	/**
	 * @param string $body the feed document
	 *
	 * @return array{title: string, site_url: string, items: array<array<string, string>>}
	 *
	 * @throws InvalidArgumentException it is not a feed
	 */
	public function parse(string $body): array {
		$previous = libxml_use_internal_errors(true);
		try {
			// LIBXML_NONET and no entity substitution: a feed must not be able
			// to make this server fetch anything, or read anything off it
			$xml = simplexml_load_string(trim($body), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if (!$xml instanceof SimpleXMLElement) {
			throw new InvalidArgumentException('that is not a feed this can read');
		}

		$name = strtolower($xml->getName());

		// RDF before the `channel` test, not after it: an RDF feed has a
		// channel too, and read as RSS its entries are invisible — they are
		// siblings of that channel rather than children of it.
		return match (true) {
			$name === 'feed' => $this->atom($xml),
			$name === 'rdf' => $this->rdf($xml),
			isset($xml->channel) => $this->rss($xml),
			default => throw new InvalidArgumentException('that is not a feed this can read'),
		};
	}

	/** @return array{title: string, site_url: string, items: array<array<string, string>>} */
	private function rss(SimpleXMLElement $xml): array {
		$channel = $xml->channel;
		$items = [];
		foreach ($channel->item ?? [] as $item) {
			$link = $this->text($item->link ?? null);
			$guid = $this->text($item->guid ?? null);
			$items[] = [
				'guid' => $guid !== '' ? $guid : $link,
				'link' => $link,
				'title' => $this->text($item->title ?? null),
				'summary' => $this->summary(
					$this->text($item->description ?? null) ?: $this->mediaDescription($item)
				),
				'thumbnail' => $this->thumbnail($item),
				'published' => $this->date(
					$this->text($item->pubDate ?? null)
					?: $this->text(($item->children(self::NS_DC)->date ?? null))
				),
			];

			if (count($items) >= self::MAX_ITEMS) {
				break;
			}
		}

		return [
			'title' => $this->text($channel->title ?? null),
			'site_url' => $this->text($channel->link ?? null),
			'items' => $items,
		];
	}

	/** @return array{title: string, site_url: string, items: array<array<string, string>>} */
	private function atom(SimpleXMLElement $xml): array {
		$items = [];
		foreach ($xml->entry ?? [] as $entry) {
			$items[] = [
				'guid' => $this->text($entry->id ?? null) ?: $this->link($entry),
				'link' => $this->link($entry),
				'title' => $this->text($entry->title ?? null),
				'summary' => $this->summary(
					$this->text($entry->summary ?? null)
					?: $this->text($entry->content ?? null)
					?: $this->mediaDescription($entry)
				),
				'thumbnail' => $this->thumbnail($entry),
				'published' => $this->date(
					$this->text($entry->published ?? null) ?: $this->text($entry->updated ?? null)
				),
			];

			if (count($items) >= self::MAX_ITEMS) {
				break;
			}
		}

		return [
			'title' => $this->text($xml->title ?? null),
			'site_url' => $this->link($xml),
			'items' => $items,
		];
	}

	/** @return array{title: string, site_url: string, items: array<array<string, string>>} */
	private function rdf(SimpleXMLElement $xml): array {
		// The root is `rdf:RDF` and everything under it is RSS 1.0, so the
		// children have to be asked for by namespace: `$xml->item` looks in
		// the root's own and finds nothing at all.
		$rss = $xml->children(self::NS_RSS1);
		$channel = $rss->channel ?? null;
		$entries = (isset($rss->item) && count($rss->item) > 0) ? $rss->item : ($xml->item ?? []);

		$items = [];
		foreach ($entries as $item) {
			$link = $this->text($item->link ?? null);
			$items[] = [
				'guid' => $link,
				'link' => $link,
				'title' => $this->text($item->title ?? null),
				'summary' => $this->summary($this->text($item->description ?? null)),
				'thumbnail' => '',
				'published' => $this->date($this->text(($item->children(self::NS_DC)->date ?? null))),
			];

			if (count($items) >= self::MAX_ITEMS) {
				break;
			}
		}

		return [
			'title' => $this->text($channel->title ?? $xml->channel->title ?? null),
			'site_url' => $this->text($channel->link ?? $xml->channel->link ?? null),
			'items' => $items,
		];
	}

	/**
	 * An Atom entry's link.
	 *
	 * Atom writes the address in an attribute rather than as text, and a feed
	 * may carry several: the one to open is `rel="alternate"`, or the first
	 * one with no `rel` at all, which is what that defaults to.
	 */
	private function link(SimpleXMLElement $node): string {
		$fallback = '';
		foreach ($node->link ?? [] as $link) {
			$rel = (string)($link['rel'] ?? '');
			$href = (string)($link['href'] ?? '');
			if ($href === '') {
				continue;
			}

			if ($rel === 'alternate' || $rel === '') {
				return $href;
			}

			if ($fallback === '') {
				$fallback = $href;
			}
		}

		return $fallback;
	}

	/**
	 * A picture for the entry, where the feed offers one.
	 *
	 * `media:thumbnail` is what YouTube publishes and what most video feeds
	 * use; `media:content` is the other half of the same namespace. The
	 * address is an attribute, and `children()` carries the namespace into the
	 * attribute lookup — so the attribute is read off the element itself.
	 */
	private function thumbnail(SimpleXMLElement $node): string {
		foreach ($this->mediaRoots($node) as $media) {
			foreach ($media->thumbnail ?? [] as $thumbnail) {
				$url = (string)($thumbnail->attributes()['url'] ?? '');
				if ($url !== '') {
					return $url;
				}
			}

			// `media:content` is whatever the entry is *made of*, which is not
			// necessarily a picture: YouTube puts a Flash player URL there,
			// and handing that to an <img> draws a broken image
			foreach ($media->content ?? [] as $content) {
				$attributes = $content->attributes();
				$url = (string)($attributes['url'] ?? '');
				if ($url !== '' && str_starts_with((string)($attributes['type'] ?? ''), 'image/')) {
					return $url;
				}
			}
		}

		// an RSS enclosure that happens to be a picture
		foreach ($node->enclosure ?? [] as $enclosure) {
			$type = (string)($enclosure['type'] ?? '');
			$url = (string)($enclosure['url'] ?? '');
			if ($url !== '' && str_starts_with($type, 'image/')) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Where an entry's media namespace elements are.
	 *
	 * Beside the entry in most feeds, and inside a `media:group` in YouTube's
	 * — which is the feed most people following a channel will have. Both, in
	 * that order, because a feed that offers an entry-level thumbnail and a
	 * grouped one means the first.
	 *
	 * @return SimpleXMLElement[]
	 */
	private function mediaRoots(SimpleXMLElement $node): array {
		$media = $node->children(self::NS_MEDIA);
		$roots = ($media === null) ? [] : [$media];

		foreach ($media->group ?? [] as $group) {
			$grouped = $group->children(self::NS_MEDIA);
			if ($grouped !== null) {
				$roots[] = $grouped;
			}
		}

		return $roots;
	}

	/** What a media group says the entry is about, where the feed says nothing else. */
	private function mediaDescription(SimpleXMLElement $node): string {
		foreach ($this->mediaRoots($node) as $media) {
			$description = $this->text($media->description ?? null);
			if ($description !== '') {
				return $description;
			}
		}

		return '';
	}

	/**
	 * One element as text.
	 *
	 * A SimpleXML element one level deep that does not exist is an empty
	 * element rather than null, and two levels deep it is null — so every read
	 * of a child of a child goes through here rather than being cast.
	 */
	private function text(?SimpleXMLElement $node): string {
		return ($node === null) ? '' : trim((string)$node);
	}

	/** A date a feed wrote, as one this stores, or '' where it wrote none. */
	private function date(string $value): string {
		if ($value === '') {
			return '';
		}

		$stamp = strtotime($value);

		return ($stamp === false) ? '' : gmdate('Y-m-d H:i:s', $stamp);
	}

	/** The first words of a description, without its markup. */
	private function summary(string $html): string {
		$text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$text = (string)preg_replace('/\s+/u', ' ', $text);

		return (mb_strlen($text) > self::SUMMARY) ? mb_substr($text, 0, self::SUMMARY) . '…' : $text;
	}
}
