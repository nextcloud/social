<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Counts;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Http;

/**
 * The counts of a post on the fediverse, from the document its own server
 * serves at the post's `id`: the `totalItems` of its `likes`, `shares` and
 * `replies` collections, where it states them (`Stream::statedCount()`).
 *
 * Nothing federates a count — a `Like` travels to the post's server and is
 * totalled there — so this instance hears of no change to a remote post's
 * counts unless it asks. An answer that names some other document (a
 * redirect to a page, a post that moved) or none at all leaves what is
 * stored as it was. A post its server says is not there (404) or gone (410)
 * is not asked about again for a long while (`CountWriter::stampGone()`):
 * the answer will not change, and each ask is a signed request.
 */
class ActivityPubCountSource implements CountSource {
	/** Documents asked for in one round of requests. */
	public const PARALLEL = 20;
	private const ORIGIN_TIMEOUT = 5;
	private const COUNT_KEYS = [
		'likes' => 'likes',
		'shares' => 'boosts',
		'replies' => 'replies',
		'quotes' => 'quotes',
	];

	public function __construct(
		private CurlService $curl,
		private CountWriter $writer,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return !$post->isLocal()
			&& BlueskyIds::didOf($post->getId()) === ''
			&& preg_match('#^https?://#i', $post->getId()) === 1;
	}

	#[\Override]
	public function refresh(array $posts, ?int $timeout = null): int {
		$answered = 0;
		foreach (array_chunk($posts, self::PARALLEL) as $chunk) {
			$statuses = [];
			$answers = $this->curl->retrieveObjectsMany(
				array_map(static fn (Stream $post): string => $post->getId(), $chunk), ['timeout' => $timeout ?? self::ORIGIN_TIMEOUT], $statuses
			);
			$counts = [];
			$collections = [];
			foreach ($chunk as $post) {
				$data = $answers[$post->getId()] ?? null;
				if (!is_array($data) || ($data['id'] ?? '') !== $post->getId()) {
					continue;
				}
				foreach (self::COUNT_KEYS as $property => $counter) {
					$counts[$post->getId()][$counter] = self::count($data, $property);
					if ($counts[$post->getId()][$counter] === null) {
						$url = self::collectionUrl($data[$property] ?? null);
						if ($url !== '') {
							$collections[$url][$post->getId()][$counter] = true;
						}
					}
				}
			}

			// Some servers embed totalItems, while others only give the
			// collection URL. Resolve those URLs together so the visible-post
			// refresh does not make one sequential request per counter.
			if ($collections !== []) {
				$collectionAnswers = $this->curl->retrieveObjectsMany(array_keys($collections), ['timeout' => $timeout ?? self::ORIGIN_TIMEOUT]);
				foreach ($collections as $url => $refs) {
					$collection = $collectionAnswers[$url] ?? null;
					if (!is_array($collection) || ($collection['id'] ?? $collection['@id'] ?? '') !== $url) {
						continue;
					}
					foreach ($refs as $postId => $counters) {
						foreach (array_keys($counters) as $counter) {
							$property = array_search($counter, self::COUNT_KEYS, true);
							if (is_string($property)) {
								$counts[$postId][$counter] = Stream::statedCount([$property => $collection], $property);
							}
						}
					}
				}
			}

			foreach ($chunk as $post) {
				if (in_array($statuses[$post->getId()] ?? 0, [Http::STATUS_NOT_FOUND, Http::STATUS_GONE], true)) {
					$this->writer->stampGone($post->getId());
					continue;
				}
				$data = $answers[$post->getId()] ?? null;
				if (!is_array($data) || ($data['id'] ?? '') !== $post->getId()) {
					if ($timeout === null) {
						$this->writer->stamp($post->getId());
					}
					continue;
				}
				if (!array_filter($counts[$post->getId()] ?? [], static fn (?int $count): bool => $count !== null)) {
					if ($timeout === null) {
						$this->writer->stamp($post->getId());
					}
					continue;
				}
				if ($this->writer->write(
					$post->getId(),
					$counts[$post->getId()]['likes'] ?? null,
					$counts[$post->getId()]['boosts'] ?? null,
					$counts[$post->getId()]['replies'] ?? null,
					quotes: $counts[$post->getId()]['quotes'] ?? null,
				)) {
					$answered++;
				}
			}
		}

		return $answered;
	}

	/** A count from an embedded collection or Mastodon's quote extension. */
	private static function count(array $data, string $property): ?int {
		if ($property === 'quotes') {
			$quoteCount = $data['quoteCount'] ?? null;
			if (is_int($quoteCount) || (is_string($quoteCount) && ctype_digit($quoteCount))) {
				$quoteCount = (int)$quoteCount;
				if ($quoteCount >= 0 && $quoteCount <= Stream::REMOTE_COUNT_CEILING) {
					return $quoteCount;
				}
			}
		}

		return Stream::statedCount($data, $property);
	}

	/** The URL of a collection that names no total inline. */
	private static function collectionUrl(mixed $collection): string {
		if (is_string($collection)) {
			$url = $collection;
		} elseif (is_array($collection)) {
			$url = $collection['id'] ?? $collection['@id'] ?? '';
		} else {
			return '';
		}

		if (!is_string($url) || preg_match('#^https?://#i', $url) !== 1) {
			return '';
		}

		return $url;
	}
}
