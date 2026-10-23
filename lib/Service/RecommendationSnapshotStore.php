<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\RecommendationFeedSnapshot;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;

/** Shared-cache storage and signed paging for immutable recommendation order. */
class RecommendationSnapshotStore {
	private const CACHE_NAMESPACE = 'social.recommendation.snapshots';
	private const PAGE_SIZE = 20;
	private const TTL = 3600;
	private ?ICache $cache = null;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private IConfig $config,
	) {
	}

	/**
	 * @param list<string> $rankedNids
	 * @param list<string> $explorationNids
	 * @return array{session_id: string, nids: list<string>, exploration_nids: list<string>, next_cursor: ?string}|null
	 */
	public function create(
		string $viewerId,
		string $mode,
		string $mediaKind,
		int $profileVersion,
		int $generation,
		int $now,
		array $rankedNids,
		int $pageSize = self::PAGE_SIZE,
		array $explorationNids = [],
	): ?array {
		$secret = $this->secret();
		if ($viewerId === '' || $mode === '' || $now <= 0 || strlen($secret) < 32) {
			return null;
		}
		$snapshot = RecommendationFeedSnapshot::create(
			$viewerId, $mode, $mediaKind, $profileVersion, $generation, $now, $rankedNids, self::TTL, $explorationNids
		);
		if (!$this->cache()->set($this->key($snapshot->id), $snapshot->toArray(), self::TTL)) {
			return null;
		}

		return $this->pageResponse($snapshot, 0, $pageSize, $secret);
	}

	/**
	 * A valid signature is not sufficient: the cached snapshot and current
	 * viewer, mode, media kind, profile revision and reset generation must agree.
	 * The caller must recheck visibility before hydrating the returned NIDs.
	 *
	 * @return array{session_id: string, nids: list<string>, exploration_nids: list<string>, next_cursor: ?string}|null
	 */
	public function nextPage(
		string $cursor,
		string $viewerId,
		string $mode,
		string $mediaKind,
		int $profileVersion,
		int $generation,
		int $now,
		int $pageSize = self::PAGE_SIZE,
	): ?array {
		$secret = $this->secret();
		$verified = RecommendationFeedSnapshot::verifyCursor(
			$cursor, $secret, $viewerId, $mode, $mediaKind, $profileVersion, $generation, $now
		);
		if ($verified === null) {
			return null;
		}
		$data = $this->cache()->get($this->key($verified['id']));
		if (!is_array($data)) {
			return null;
		}
		$snapshot = RecommendationFeedSnapshot::fromArray($data);
		if ($snapshot === null
			|| $snapshot->id !== $verified['id']
			|| $snapshot->algorithmVersion !== RecommendationFeedSnapshot::ALGORITHM_VERSION
			|| !hash_equals($snapshot->viewerKey, hash('sha256', $viewerId))
			|| $snapshot->mode !== $mode
			|| $snapshot->mediaKind !== $mediaKind
			|| $snapshot->profileVersion !== $profileVersion
			|| $snapshot->generation !== $generation
			|| $snapshot->expiresAt <= $now
			|| $verified['position'] > count($snapshot->rankedNids)) {
			return null;
		}

		return $this->pageResponse($snapshot, $verified['position'], $pageSize, $secret);
	}

	/** @return array{session_id: string, nids: list<string>, exploration_nids: list<string>, next_cursor: ?string} */
	private function pageResponse(RecommendationFeedSnapshot $snapshot, int $position, int $pageSize, string $secret): array {
		$pageSize = max(1, min(50, $pageSize));
		$nids = $snapshot->page($position, $pageSize);
		$nextPosition = $position + count($nids);

		return [
			'session_id' => $snapshot->id,
			'nids' => $nids,
			'exploration_nids' => $snapshot->explorationNids,
			'next_cursor' => $nextPosition < count($snapshot->rankedNids) ? $snapshot->cursor($nextPosition, $secret) : null,
		];
	}

	private function secret(): string {
		return $this->config->getSystemValueString('secret', '');
	}

	private function cache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed(self::CACHE_NAMESPACE);
	}

	private function key(string $id): string {
		return 'snapshot/' . $id;
	}
}
