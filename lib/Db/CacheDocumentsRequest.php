<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Exception;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheDocumentsRequest extends CacheDocumentsRequestBuilder {
	public const CACHING_TIMEOUT = 5; // 5 min

	/**
	 * How many uncached documents one pass may take on. Each one is an
	 * outbound HTTP request, so this is a bound on the cron slot rather than on
	 * the work: what is left over is the next run's.
	 */
	public const CACHE_BATCH = 50;

	public function save(Document $document): void {
		$qb = $this->getCacheDocumentsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($document->getId()))
			->setValue('id_prim', $qb->createNamedParameter($qb->prim($document->getId())))
			->setValue('account', $qb->createNamedParameter($document->getAccount()))
			->setValue('type', $qb->createNamedParameter($document->getType()))
			->setValue('url', $qb->createNamedParameter($document->getUrl()))
			->setValue('media_type', $qb->createNamedParameter($document->getMediaType()))
			->setValue('mime_type', $qb->createNamedParameter($document->getMimeType()))
			->setValue('error', $qb->createNamedParameter($document->getError()))
			->setValue('local_copy', $qb->createNamedParameter($document->getLocalCopy()))
			->setValue('resized_copy', $qb->createNamedParameter($document->getResizedCopy()))
			->setValue('blurhash', $qb->createNamedParameter($document->getBlurHash()))
			->setValue('description', $qb->createNamedParameter($document->getDescription()))
			->setValue('parent_id', $qb->createNamedParameter($document->getParentId()))
			->setValue('parent_id_prim', $qb->createNamedParameter($qb->prim($document->getParentId())))
			->setValue('public', $qb->createNamedParameter(($document->isPublic()) ? '1' : '0'));

		// generate Meta
		$document->convertToMediaAttachment();
		if ($document->getMeta() !== null) {
			$qb->setValue('meta', $qb->createNamedParameter(json_encode($document->getMeta())));
		}

		try {
			$qb->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$qb->executeStatement();
		$document->setNid($qb->getLastInsertId());
	}

	/**
	 * Insert cache about an Actor in database.
	 */
	public function update(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->set('type', $qb->createNamedParameter($document->getType()))
			->set('url', $qb->createNamedParameter($document->getUrl()))
			->set('media_type', $qb->createNamedParameter($document->getMediaType()))
			->set('mime_type', $qb->createNamedParameter($document->getMimeType()))
			->set('error', $qb->createNamedParameter($document->getError()))
			->set('local_copy', $qb->createNamedParameter($document->getLocalCopy()))
			->set('resized_copy', $qb->createNamedParameter($document->getResizedCopy()))
			->set('blurhash', $qb->createNamedParameter($document->getBlurHash()))
			->set('description', $qb->createNamedParameter($document->getDescription()))
			->set('parent_id', $qb->createNamedParameter($document->getParentId()))
			->set('parent_id_prim', $qb->createNamedParameter($qb->prim($document->getParentId())))
			->set('public', $qb->createNamedParameter(($document->isPublic()) ? '1' : '0'));

		try {
			$qb->set(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$qb->limitToIdPrim($qb->prim($document->getId()));
		$qb->executeStatement();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function updateDescription(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$this->limitToIdString($qb, $document->getId());
		$qb->set('description', $qb->createNamedParameter($document->getDescription()));

		$qb->executeStatement();
	}

	public function initCaching(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$this->limitToIdString($qb, $document->getId());

		try {
			$qb->set(
				'caching', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$qb->executeStatement();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function endCaching(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$this->limitToIdString($qb, $document->getId());
		$qb->set('local_copy', $qb->createNamedParameter($document->getLocalCopy()));
		$qb->set('resized_copy', $qb->createNamedParameter($document->getResizedCopy()));
		$qb->set('blurhash', $qb->createNamedParameter($document->getBlurHash()));
		$qb->set('description', $qb->createNamedParameter($document->getDescription()));
		$qb->set('error', $qb->createNamedParameter($document->getError()));
		// The mime type is sniffed from the downloaded bytes and this is the only
		// write that runs afterwards; without it the row keeps the empty type it
		// was created with and the copy is served with no Content-Type at all.
		$qb->set('mime_type', $qb->createNamedParameter($document->getMimeType()));
		$qb->set('media_type', $qb->createNamedParameter($document->getMediaType()));

		$qb->executeStatement();
	}

	/**
	 * @param string $url
	 *
	 * @return Document
	 * @throws CacheDocumentDoesNotExistException
	 */
	/**
	 * The document row behind a served copy, by the uuid `/media/{uuid}` exposes.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getByLocalCopy(string $uuid): Document {
		$qb = $this->getCacheDocumentsSelectSql();
		$this->limitToDBField($qb, 'local_copy', $uuid, false);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * The document a media uuid belongs to, whether the uuid names its full
	 * copy or its resized one.
	 *
	 * A preview link carries the resized uuid, so a lookup that knew only
	 * about `local_copy` could never serve one.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getByCopy(string $uuid): Document {
		$qb = $this->getCacheDocumentsSelectSql();

		$expr = $qb->expr();
		$qb->andWhere(
			$expr->orX(
				$expr->eq('cd.local_copy', $qb->createNamedParameter($uuid)),
				$expr->eq('cd.resized_copy', $qb->createNamedParameter($uuid))
			)
		);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	public function getByUrl(string $url) {
		$qb = $this->getCacheDocumentsSelectSql();
		$this->limitToUrl($qb, $url);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * @param array $mediaIds
	 * @param string $account - limit to account
	 *
	 * @return Document[]
	 */
	public function getFromArray(array $mediaIds, string $account = ''): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToDBFieldArray('nid', $mediaIds);
		$qb->limitToAccount($account);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * The document row for an id.
	 *
	 * Unless `$public` is set this is an unscoped lookup: a document id names any
	 * row in the table, so anything that takes the id from a request must decide
	 * for itself whether the caller may see the result — see
	 * `DocumentService::getFromCacheAsViewer()`.
	 *
	 * @return Document
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getById(string $id, bool $public = false) {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToIdPrim($qb->prim($id));

		if ($public === true) {
			$this->limitToPublic($qb);
		}

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * @param Document $item
	 *
	 * @return bool
	 */
	public function isDuplicate(Document $item): bool {
		$qb = $this->getCacheDocumentsSelectSql();
		$this->limitToUrl($qb, $item->getUrl());
		$this->limitToParentId($qb, $item->getParentId());

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data !== false);
	}

	/**
	 * @return Document[]
	 * @throws Exception
	 */
	/**
	 * The documents waiting to be fetched, at most `CACHE_BATCH` of them.
	 *
	 * Capped because the caller does one outbound HTTP request per row, inside
	 * a cron slot: an instance that was offline for a day, or that has just
	 * followed a busy account, came back to a backlog it tried to fetch in a
	 * single pass. Its two siblings — `CacheActorsRequest::getRemoteActorsToUpdateDetails()`
	 * and `StreamQueueRequest::getStandby()` — were capped long ago and this one
	 * was missed. The rest of the backlog is picked up on the next run, which is
	 * what the other two do.
	 */
	public function getNotCachedDocuments(int $limit = self::CACHE_BATCH) {
		$qb = $this->getCacheDocumentsSelectSql();
		$this->limitToDBFieldEmpty($qb, 'local_copy');
		$this->limitToCaching($qb, self::CACHING_TIMEOUT);
		$this->limitToDBFieldInt($qb, 'error', 0);
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * @param string $url
	 */
	public function deleteByUrl(string $url) {
		$qb = $this->getCacheDocumentsDeleteSql();
		$this->limitToUrl($qb, $url);

		$qb->executeStatement();
	}

	/**
	 * @param string $id
	 */
	public function deleteById(string $id) {
		$qb = $this->getCacheDocumentsDeleteSql();
		$this->limitToIdString($qb, $id);

		$qb->executeStatement();
	}

	public function deleteByParent(string $parentId): void {
		$qb = $this->getCacheDocumentsDeleteSql();
		$qb->limitToDBField('parent_id_prim', $qb->prim($parentId));

		$qb->executeStatement();
	}

	public function moveAccount(string $actorId, string $newId): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->set('parent_id', $qb->createNamedParameter($newId))
			->set('parent_id_prim', $qb->createNamedParameter($qb->prim($newId)));

		$qb->limitToDBField('parent_id_prim', $qb->prim($actorId));

		$qb->executeStatement();
	}
}
