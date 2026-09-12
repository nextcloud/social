<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Exceptions\CacheItemNotFoundException;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class StreamRequestBuilder
 *
 * @package OCA\Social\Db
 */
class StreamRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM);

		return $qb;
	}

	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM);

		return $qb;
	}

	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @param int $format
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamSelectSql(int $format = Stream::FORMAT_ACTIVITYPUB): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->setFormat($format);

		$qb->selectDistinct('s.id')
			->from(self::TABLE_STREAM, 's');
		foreach (self::$tables[self::TABLE_STREAM] as $field) {
			if ($field === 'id') {
				continue;
			}
			$qb->addSelect('s.' . $field);
		}

		$qb->setDefaultSelectAlias('s');

		return $qb;
	}

	/**
	 * The same query, projecting one column.
	 *
	 * A timeline reads DISTINCT over eighty columns, several of them TEXT,
	 * because joining the recipients and follows tables can return a stream
	 * more than once. The database cannot deduplicate that without building
	 * and sorting the whole matching set first, which is why a timeline used
	 * to cost the same whether twenty rows were asked for or a hundred.
	 *
	 * Deduplicating one integer instead is cheap, so the page is chosen here
	 * and the rows are fetched afterwards by id.
	 */
	protected function getStreamNidsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('s.nid')
			->from(self::TABLE_STREAM, 's');
		$qb->setDefaultSelectAlias('s');

		return $qb;
	}

	/**
	 * @param SocialQueryBuilder $qb a query projecting s.nid
	 *
	 * @return int[] the ids of the page, in the order the query put them
	 */
	protected function getNidsFromRequest(SocialQueryBuilder $qb): array {
		$nids = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$nids[] = (int)$row['nid'];
		}
		$cursor->closeCursor();

		return $nids;
	}

	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function countNotesSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_STREAM, 's');

		$qb->setDefaultSelectAlias('s');

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM);

		return $qb;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 * @param string $alias
	 * @param string $aliasFollow
	 */
	protected function timelineHomeLinkCacheActor(
		SocialQueryBuilder $qb, string $alias = 'ca', string $aliasFollow = 'f',
	) {
		$qb->linkToCacheActors($alias, 's.attributed_to_prim');

		$expr = $qb->expr();

		$follow = $expr->andX(
			$expr->eq($aliasFollow . '.type', $qb->createNamedParameter('Follow'))
		);

		$loopback = $expr->andX(
			$expr->eq($aliasFollow . '.type', $qb->createNamedParameter('Loopback')),
			$expr->eq($alias . '.id_prim', $qb->getDefaultSelectAlias() . '.attributed_to_prim')
		);

		$orX = $expr->orX($follow, $loopback);

		$qb->andWhere($orX);
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	protected function getStreamFromRequest(SocialQueryBuilder $qb): Stream {
		/** @var Stream $result */
		try {
			$result = $qb->getRow([$this, 'parseStreamSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new StreamNotFoundException('stream not found');
		}

		return $result;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Stream[]
	 */
	public function getStreamsFromRequest(SocialQueryBuilder $qb): array {
		/** @var Stream[] $result */
		$result = $qb->getRows([$this, 'parseStreamSelectSql']);

		return $result;
	}

	/**
	 * @param array $data
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Stream
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	public function parseStreamSelectSql(array $data, SocialQueryBuilder $qb): Stream {
		$as = $this->get('type', $data, Stream::TYPE);

		/** @var Stream $item */
		$item = AP::instance()->getItemFromType($as);
		$item->importFromDatabase($data);
		$item->setExportFormat($qb->getFormat());
		$instances = json_decode($this->get('instances', $data, '[]'), true);
		if (is_array($instances)) {
			foreach ($instances as $instance) {
				$instancePath = new InstancePath();
				$instancePath->import($instance);
				$item->addInstancePath($instancePath);
			}
		}

		try {
			$actor = $qb->parseLeftJoinCacheActors($data, 'ca_', $qb->getFormat());
			$actor->setExportFormat($qb->getFormat());
			$item->setCompleteDetails(true);
			$item->setActor($actor);
		} catch (InvalidResourceException $e) {
		}

		try {
			$object = $qb->parseLeftJoinStream($data, 'os_', ACore::FORMAT_LOCAL);
			$item->setObject($object);
		} catch (InvalidResourceException $e) {
		}

		$action = $this->parseStreamActionsLeftJoin($data);
		$item->setAction($action);

		if ($item->hasCache()) {
			$cache = $item->getCache();
			try {
				$cachedItem = $cache->getItem($action->getStreamId());
				$cachedObject = $cachedItem->getObject();
				$cachedObject['action'] = $action;
				$cachedItem->setContent(json_encode($cachedObject));
				$cache->updateItem($cachedItem, false);
			} catch (CacheItemNotFoundException $e) {
			}
		}

		return $item;
	}
}
