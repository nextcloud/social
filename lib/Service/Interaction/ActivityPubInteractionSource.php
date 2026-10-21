<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Interaction;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RemoteFetchQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who liked or boosted a post on the fediverse: its `likes` and `shares`
 * collections, where its server lists them — many give only the number,
 * and then there is nobody to name. The accounts not cached here are
 * fetched in the background (`RemoteFetchQueue::resolveActors()`). The
 * fediverse has no collection of the posts quoting one.
 */
class ActivityPubInteractionSource implements InteractionSource {
	private const PAGES = 2;

	public function __construct(
		private CurlService $curl,
		private RemoteFetchQueue $queue,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return !$post->isLocal() && !BlueskyIds::isPostId($post->getId());
	}

	#[\Override]
	public function actors(Stream $post, string $type, int $limit, ?int $timeout = null): array {
		$source = json_decode($post->getSource(), true);
		$collection = is_array($source) ? ($source[$type === Like::TYPE ? 'likes' : 'shares'] ?? null) : null;
		$ids = [];
		$seen = [];
		$requests = 0;
		for ($page = 0; (is_string($collection) || is_array($collection)) && $page < self::PAGES && count($ids) < $limit;) {
			// ActivityPub servers may encode `first` and `next` as link
			// objects (`{"id":"https://…"}`), not only as strings. A
			// page-shaped object is already the page; a link-shaped one has to
			// be dereferenced before its actors can be read.
			if (is_array($collection) && !array_key_exists('orderedItems', $collection) && !array_key_exists('items', $collection)) {
				$reference = $collection['first'] ?? $collection['next'] ?? $collection['id'] ?? $collection['@id'] ?? $collection['href'] ?? null;
				$collection = self::collectionReference($reference);
				continue;
			}
			if (is_string($collection)) {
				if (preg_match('#^https?://#i', $collection) !== 1 || isset($seen[$collection]) || $requests >= self::PAGES) {
					break;
				}
				$seen[$collection] = true;
				$requests++;
				try {
					$collection = $timeout === null
						? $this->curl->retrieveObject($collection)
						: $this->curl->retrieveObject($collection, true, $timeout);
				} catch (Throwable $e) {
					$this->logger->info('A collection of reactions was not read', ['post' => $post->getId(), 'exception' => $e]);
					throw $e;
				}
			}
			$items = $collection['orderedItems'] ?? $collection['items'] ?? [];
			foreach (is_array($items) ? $items : [] as $item) {
				$id = self::actorOf($item, $type);
				if ($id !== '') {
					$ids[] = $id;
				}
			}
			$page++;
			$reference = array_key_exists('orderedItems', $collection) || array_key_exists('items', $collection)
				? ($collection['next'] ?? null)
				: ($collection['first'] ?? $collection['next'] ?? null);
			$collection = self::collectionReference($reference);
		}
		$ids = array_slice(array_values(array_unique($ids)), 0, $limit);
		$this->queue->resolveActors($ids);

		return $ids;
	}

	#[\Override]
	public function quotes(Stream $post, int $limit, ?int $timeout = null): int {
		return 0;
	}

	/**
	 * The account behind one item: a `Like` or an `Announce` names its
	 * actor; a bare address is taken to be the account.
	 */
	private static function actorOf(mixed $item, string $type): string {
		if (is_array($item)) {
			$actor = $item['actor'] ?? (in_array($item['type'] ?? '', ['Person', 'Service', 'Group', 'Organization', 'Application'], true) ? ($item['id'] ?? '') : '');
			if (is_array($actor)) {
				$actor = $actor['id'] ?? '';
			}
			$id = is_string($actor) ? $actor : '';
		} else {
			$id = is_string($item) && in_array($type, [Like::TYPE, Announce::TYPE], true) ? $item : '';
		}

		return preg_match('#^https?://#i', $id) === 1 ? $id : '';
	}

	/** A collection link, or an embedded page; malformed links are ignored. */
	private static function collectionReference(mixed $reference): string|array|null {
		if (is_array($reference)) {
			if (array_key_exists('orderedItems', $reference) || array_key_exists('items', $reference)) {
				return $reference;
			}
			$reference = $reference['id'] ?? $reference['@id'] ?? $reference['href'] ?? null;
		}

		return is_string($reference) && preg_match('#^https?://#i', $reference) === 1 ? $reference : null;
	}
}
