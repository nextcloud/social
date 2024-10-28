<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\Traits\TArrayTools;

class ActorsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getActorsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getActorsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getActorsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'a.id', 'a.id_prim', 'a.user_id', 'a.preferred_username', 'a.name', 'a.summary',
			'a.public_key', 'a.avatar_version', 'a.private_key', 'a.creation', 'a.deleted'
		)
			->from(self::TABLE_ACTORS, 'a');

		$this->defaultSelectAlias = 'a';
		$qb->setDefaultSelectAlias('a');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getActorsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ACTORS);

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return Person
	 * @throws SocialAppConfigException
	 */
	public function parseActorsSelectSql($data): Person {
		$root = $this->configService->getSocialUrl();

		$actor = new Person();
		$actor->importFromDatabase($data);
		$actor->setType('Person');
		$actor->setInbox($actor->getId() . '/inbox')
			->setOutbox($actor->getId() . '/outbox')
			->setUserId($this->get('user_id', $data, ''))
			->setFollowers($actor->getId() . '/followers')
			->setFollowing($actor->getId() . '/following')
			->setSharedInbox($root . 'inbox')
			->setLocal(true)
			->setAvatarVersion($this->getInt('avatar_version', $data, -1))
			->setAccount(
				$actor->getPreferredUsername() . '@' . $this->configService->getSocialAddress()
			);
		$actor->setUrlSocial($root)
			->setUrl($actor->getId());

		return $actor;
	}
}
