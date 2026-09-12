<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Story;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Stories, and who has seen them.
 *
 * Every read here filters on `expires_at`, and the cron job deletes what has
 * expired. Two independent guards on purpose: if the job never runs nothing
 * expired is ever shown, and if a read is ever written without the filter the
 * job has already removed the row. A story whose day is up must not come back
 * because one of the two was forgotten.
 *
 * @package OCA\Social\Db
 */
class StoriesRequest extends CoreRequestBuilder {
	use TArrayTools;

	private function getStoriesInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STORIES);

		return $qb;
	}

	private function getStoriesSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select(
			'st.id', 'st.actor_id', 'st.actor_id_prim', 'st.document_id', 'st.document_id_prim',
			'st.caption', 'st.duration', 'st.creation', 'st.expires_at'
		)
			->from(self::TABLE_STORIES, 'st');

		$this->defaultSelectAlias = 'st';
		$qb->setDefaultSelectAlias('st');

		return $qb;
	}

	private function getStoriesDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STORIES);

		return $qb;
	}

	/** @return Story[] */
	private function getStoriesFromRequest(SocialQueryBuilder $qb): array {
		$stories = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$story = new Story();
			$story->importFromDatabase($data);
			$stories[] = $story;
		}
		$cursor->closeCursor();

		return $stories;
	}

	public function save(Story $story): Story {
		$now = new DateTime('now');
		$expires = (new DateTime('now'))->setTimestamp($now->getTimestamp() + Story::LIFETIME);

		$qb = $this->getStoriesInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($story->getOwnerId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($story->getOwnerId())))
			->setValue('document_id', $qb->createNamedParameter($story->getDocumentId()))
			->setValue('document_id_prim', $qb->createNamedParameter($qb->prim($story->getDocumentId())))
			->setValue('caption', $qb->createNamedParameter($story->getCaption()))
			->setValue('duration', $qb->createNamedParameter($story->getDuration()))
			->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->setValue('expires_at', $qb->createNamedParameter($expires, IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();

		return $story->setId($qb->getLastInsertId())
			->setCreation($now->getTimestamp())
			->setExpiresAt($expires->getTimestamp());
	}

	/**
	 * One live story, whoever owns it.
	 *
	 * @throws ItemNotFoundException when it is unknown or its day is up
	 */
	public function getLiveById(int $id): Story {
		$qb = $this->getStoriesSelectSql();
		$qb->andWhere($qb->expr()->eq('st.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$this->limitToLive($qb);

		$stories = $this->getStoriesFromRequest($qb);
		if ($stories === []) {
			throw new ItemNotFoundException('unknown story');
		}

		return $stories[0];
	}

	/**
	 * The live stories of one account, oldest first -- which is the order a
	 * carousel plays them in.
	 *
	 * @return Story[]
	 */
	public function getLiveByActor(string $actorId): array {
		$qb = $this->getStoriesSelectSql();
		$qb->andWhere($qb->expr()->eq('st.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$this->limitToLive($qb);
		$qb->orderBy('st.id', 'asc');
		$qb->setMaxResults(Story::MAX_PER_ACTOR);

		return $this->getStoriesFromRequest($qb);
	}

	/**
	 * The live stories of a set of accounts, for the carousel.
	 *
	 * @param string[] $actorIds
	 *
	 * @return Story[]
	 */
	public function getLiveByActors(array $actorIds, int $limit = 200): array {
		if ($actorIds === []) {
			return [];
		}

		$qb = $this->getStoriesSelectSql();
		$prims = array_map(static fn (string $id): string => md5($id), $actorIds);
		$qb->andWhere(
			$qb->expr()->in('st.actor_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
		);
		$this->limitToLive($qb);
		$qb->orderBy('st.id', 'asc');
		$qb->setMaxResults($limit);

		return $this->getStoriesFromRequest($qb);
	}

	public function countLiveByActor(string $actorId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'live')
			->from(self::TABLE_STORIES, 'st')
			->where($qb->expr()->eq('st.actor_id_prim', $qb->createNamedParameter(md5($actorId))))
			->andWhere($qb->expr()->gt('st.expires_at', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['live'] ?? 0);
	}

	/** Removes one story of one owner, and the record of who saw it. */
	public function delete(string $actorId, int $id): void {
		$this->deleteViewsOf([$id]);

		$qb = $this->getStoriesDeleteSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($actorId))));

		$qb->executeStatement();
	}

	/** Everything an account owns, for a deletion or a suspension. */
	public function deleteRelatedId(string $actorId): void {
		$ids = [];
		foreach ($this->getLiveByActor($actorId) as $story) {
			$ids[] = $story->getId();
		}
		$this->deleteViewsOf($ids);

		$qb = $this->getStoriesDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($actorId))));
		$qb->executeStatement();
	}

	/**
	 * Deletes what has expired and says how many went.
	 *
	 * Bounded per run: an instance that has not swept in a while has more due
	 * than one cron slot should take, and what is left is the next run's.
	 */
	public function deleteExpired(int $limit = 500): int {
		$qb = $this->getStoriesSelectSql();
		$qb->andWhere(
			$qb->expr()->lte('st.expires_at', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
		);
		$qb->setMaxResults($limit);

		$ids = [];
		foreach ($this->getStoriesFromRequest($qb) as $story) {
			$ids[] = $story->getId();
		}

		if ($ids === []) {
			return 0;
		}

		$this->deleteViewsOf($ids);

		$qb = $this->getStoriesDeleteSql();
		$qb->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return $qb->executeStatement();
	}

	/**
	 * Marks a story seen.
	 *
	 * A repeat is a no-op: the unique index refuses the second row and that is
	 * the whole of the handling, which is what makes the route a client calls
	 * as it scrolls safe to call twice.
	 */
	public function markSeen(int $storyId, string $viewerId): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STORY_VIEWS)
			->setValue('story_id', $qb->createNamedParameter($storyId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($viewerId)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * Which of these stories the viewer has already seen.
	 *
	 * One query for the whole carousel rather than one per story: a carousel
	 * draws dozens and asking per story is how a screen becomes dozens of round
	 * trips.
	 *
	 * @param int[] $storyIds
	 *
	 * @return int[] the ids that have been seen
	 */
	public function seenAmong(array $storyIds, string $viewerId): array {
		if ($storyIds === []) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$qb->select('sv.story_id')
			->from(self::TABLE_STORY_VIEWS, 'sv')
			->where($qb->expr()->in('sv.story_id', $qb->createNamedParameter($storyIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('sv.actor_id_prim', $qb->createNamedParameter(md5($viewerId))));

		$seen = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$seen[] = (int)$data['story_id'];
		}
		$cursor->closeCursor();

		return $seen;
	}

	/** How many accounts have seen each of these stories. */
	public function countViews(int $storyId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'seen')
			->from(self::TABLE_STORY_VIEWS, 'sv')
			->where($qb->expr()->eq('sv.story_id', $qb->createNamedParameter($storyId, IQueryBuilder::PARAM_INT)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['seen'] ?? 0);
	}

	/** @param int[] $storyIds */
	private function deleteViewsOf(array $storyIds): void {
		if ($storyIds === []) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STORY_VIEWS)
			->where($qb->expr()->in('story_id', $qb->createNamedParameter($storyIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}

	/** The filter every read carries: a story whose day is up is not there. */
	private function limitToLive(SocialQueryBuilder $qb): void {
		$qb->andWhere(
			$qb->expr()->gt('st.expires_at', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
		);
	}
}
