<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Portfolio;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * One page of work per account.
 *
 * Every read is by the account, which is the unique index; there is no listing
 * of portfolios anywhere, deliberately — a directory of everybody's portfolio
 * is a thing nobody asked for and a thing somebody would have to moderate.
 *
 * @package OCA\Social\Db
 */
class PortfoliosRequest extends CoreRequestBuilder {
	use TArrayTools;

	private function getPortfolioSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select(
			'pf.id', 'pf.actor_id', 'pf.actor_id_prim', 'pf.active', 'pf.title', 'pf.intro',
			'pf.layout', 'pf.source', 'pf.collection_id', 'pf.show_captions', 'pf.show_places',
			'pf.show_dates', 'pf.show_avatar', 'pf.creation'
		)
			->from(self::TABLE_PORTFOLIOS, 'pf');

		$this->defaultSelectAlias = 'pf';
		$qb->setDefaultSelectAlias('pf');

		return $qb;
	}

	/**
	 * The portfolio of one account.
	 *
	 * @throws ItemNotFoundException when there is none
	 */
	public function getByActor(string $actorId): Portfolio {
		$qb = $this->getPortfolioSelectSql();
		$qb->andWhere($qb->expr()->eq('pf.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('no portfolio');
		}

		$portfolio = new Portfolio();
		$portfolio->importFromDatabase($data);

		return $portfolio;
	}

	/**
	 * Writes one, whether or not there was one before.
	 *
	 * An update where there is a row and an insert where there is not, rather
	 * than two calls a caller has to choose between: a portfolio is a thing an
	 * account has one of, and "create it if this is the first time they opened
	 * the editor" is bookkeeping rather than a decision.
	 */
	public function save(Portfolio $portfolio): Portfolio {
		try {
			$existing = $this->getByActor($portfolio->getActorId());
			$portfolio->setId($existing->getId());
			$portfolio->setCreation($existing->getCreation());
			$this->update($portfolio);

			return $portfolio;
		} catch (ItemNotFoundException $e) {
			// the first time, which is the ordinary case exactly once
		}

		$now = new DateTime('now');
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_PORTFOLIOS)
			->setValue('actor_id', $qb->createNamedParameter($portfolio->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($portfolio->getActorId())))
			->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));
		$this->setFields($qb, $portfolio);

		$qb->executeStatement();

		return $portfolio->setId($qb->getLastInsertId())->setCreation($now->getTimestamp());
	}

	private function update(Portfolio $portfolio): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_PORTFOLIOS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($portfolio->getId(), IQueryBuilder::PARAM_INT)));
		$this->setFields($qb, $portfolio);

		$qb->executeStatement();
	}

	/**
	 * The fields an insert and an update both write, in one place.
	 *
	 * Apart they would drift, and the way that drift shows is a setting that
	 * saves the first time and is ignored afterwards.
	 */
	private function setFields(SocialQueryBuilder $qb, Portfolio $portfolio): void {
		$fields = [
			'active' => [$portfolio->isActive(), IQueryBuilder::PARAM_BOOL],
			'title' => [$portfolio->getTitle(), IQueryBuilder::PARAM_STR],
			'intro' => [$portfolio->getIntro(), IQueryBuilder::PARAM_STR],
			'layout' => [$portfolio->getLayout(), IQueryBuilder::PARAM_STR],
			'source' => [$portfolio->getSource(), IQueryBuilder::PARAM_STR],
			'collection_id' => [$portfolio->getCollectionId(), IQueryBuilder::PARAM_INT],
			'show_captions' => [$portfolio->showsCaptions(), IQueryBuilder::PARAM_BOOL],
			'show_places' => [$portfolio->showsPlaces(), IQueryBuilder::PARAM_BOOL],
			'show_dates' => [$portfolio->showsDates(), IQueryBuilder::PARAM_BOOL],
			'show_avatar' => [$portfolio->showsAvatar(), IQueryBuilder::PARAM_BOOL],
		];

		$insert = ($qb->getType() === IQueryBuilder::INSERT);
		foreach ($fields as $field => [$value, $type]) {
			$parameter = $qb->createNamedParameter($value, $type);
			$insert ? $qb->setValue($field, $parameter) : $qb->set($field, $parameter);
		}
	}

	/** An account's page goes with the account. */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_PORTFOLIOS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}
}
