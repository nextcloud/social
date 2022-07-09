<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service\Feed;

use OC\RedisFactory;
use OCA\Social\Entity\Status;
use OCA\Social\Service\FeedManager;
use OCA\Social\Service\IFeedProvider;

class RedisFeedProvider implements IFeedProvider {
	private \Redis $redis;

	public function __construct(RedisFactory $redisFactory) {
		$this->redis = $redisFactory->getInstance();
	}

	private function key(string $feedName, string $accountId, ?string $subType = null) {
		if ($subType === null) {
			return 'feed:' . $feedName . ':' . $accountId;
		}
		return 'feed:' . $feedName . ':' . $accountId . ':' . $subType;
	}

	public function addToFeed(string $timelineType, string $accountId, Status $status, bool $aggregateReblog = true): bool {
		$timelineKey = $this->key($timelineType, $accountId);
		$reblogKey = $this->key($timelineType, $accountId, 'reblogs');

		if ($status->isReblog() !== null && $aggregateReblog) {
			$rank = $this->redis->zRevRank($timelineKey, $status->getReblogOf()->getId());
			if ($rank !== null && $rank < FeedManager::REBLOG_FALLOFF) {
				return false;
			}

			if ($this->redis->zAdd($reblogKey, ['NX'], $status->getId(), $status->getReblogOf()->getId())) {
				$this->redis->zAdd($timelineKey, $status->getId(), $status->getReblogOf()->getId());
			} else {
				$reblogSetKey = $this->key($timelineType, $accountId, 'reblogs:' . $status->getReblogOf()->getId());
				$this->redis->sAdd($reblogSetKey, $status->getId());
				return false;
			}
		} else {
			if ($this->redis->zScore($reblogKey, $status->getId()) === false) {
				return false;
			}
			$this->redis->zAdd($timelineKey, $status->getId(), $status->getId());
		}

		return true;
	}

	public function removeFromFeed(string $timelineType, string $accountId, Status $status, bool $aggregateReblog = true): bool {
		return false;
	}
}
