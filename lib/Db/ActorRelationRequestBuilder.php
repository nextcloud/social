<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActorRelation;
use OCA\Social\Tools\Traits\TArrayTools;

class ActorRelationRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getActorRelationInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ACTOR_RELATION);

		return $qb;
	}

	protected function getActorRelationSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('ar.id', 'ar.actor_id_prim', 'ar.object_id', 'ar.object_id_prim', 'ar.type', 'ar.notifications', 'ar.creation')
			->from(self::TABLE_ACTOR_RELATION, 'ar');

		$this->defaultSelectAlias = 'ar';
		$qb->setDefaultSelectAlias('ar');

		return $qb;
	}

	protected function getActorRelationDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ACTOR_RELATION);

		return $qb;
	}

	protected function parseActorRelationSelectSql(array $data): ActorRelation {
		$relation = new ActorRelation();
		$relation->setId($this->getInt('id', $data))
			->setActorIdPrim($this->get('actor_id_prim', $data))
			->setObjectId($this->get('object_id', $data))
			->setObjectIdPrim($this->get('object_id_prim', $data))
			->setType($this->get('type', $data))
			->setNotifications($this->getBool('notifications', $data, true));

		$creation = $this->get('creation', $data);
		if ($creation !== '') {
			$relation->setCreation((int)strtotime($creation));
		}

		return $relation;
	}
}
