<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Instance;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

class InstancesRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getInstanceInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_INSTANCE);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getInstanceUpdateSql(): IQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_INSTANCE);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @param int $format
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getInstanceSelectSql(int $format = ACore::FORMAT_ACTIVITYPUB): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->setFormat($format);

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'i.local', 'i.uri', 'i.title', 'i.version', 'i.short_description', 'i.description', 'i.email',
			'i.urls', 'i.stats', 'i.usage', 'i.image', 'i.languages', 'i.contact', 'i.account_prim'
		)
		   ->from(self::TABLE_INSTANCE, 'i');

		$qb->setDefaultSelectAlias('i');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getInstanceDeleteSql(): IQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_INSTANCE);

		return $qb;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Instance
	 * @throws InstanceDoesNotExistException
	 */
	protected function getInstanceFromRequest(SocialQueryBuilder $qb): Instance {
		/** @var Instance $result */
		try {
			$result = $qb->getRow([$this, 'parseInstanceSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new InstanceDoesNotExistException($e->getMessage());
		}

		return $result;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return ACore[]
	 */
	public function getInstancesFromRequest(SocialQueryBuilder $qb): array {
		/** @var ACore[] $result */
		$result = $qb->getRows([$this, 'parseInstanceSelectSql']);

		return $result;
	}


	/**
	 * @param array $data
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Instance
	 */
	public function parseInstanceSelectSql($data, SocialQueryBuilder $qb): Instance {
		$instance = new Instance();
		$instance->importFromDatabase($data);

		try {
			$actor = $qb->parseLeftJoinCacheActors($data, 'cacheactor_');
			$actor->setExportFormat($qb->getFormat());
			try {
				$icon = $qb->parseLeftJoinCacheDocuments($data);
				$actor->setIcon($icon);
			} catch (InvalidResourceException $e) {
			}
			$instance->setContactAccount($actor);
		} catch (InvalidResourceException $e) {
		}

		if ($instance->isLocal() && $instance->getVersion() === '%CURRENT%') {
			$instance->setVersion($this->configService->getAppValue('installed_version'));
		}

		return $instance;
	}
}
