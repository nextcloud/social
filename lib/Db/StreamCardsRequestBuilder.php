<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\StreamCard;
use OCA\Social\Tools\Traits\TArrayTools;

class StreamCardsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	protected function getStreamCardsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM_CARDS);

		return $qb;
	}

	protected function getStreamCardsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM_CARDS);

		return $qb;
	}

	protected function getStreamCardsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('sc.stream_id_prim', 'sc.url', 'sc.title', 'sc.description', 'sc.image', 'sc.provider_name', 'sc.creation')
			->from(self::TABLE_STREAM_CARDS, 'sc');

		$this->defaultSelectAlias = 'sc';
		$qb->setDefaultSelectAlias('sc');

		return $qb;
	}

	protected function getStreamCardsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_CARDS);

		return $qb;
	}

	protected function parseStreamCardsSelectSql(array $data): StreamCard {
		$card = new StreamCard();
		$card->setStreamId($this->get('stream_id_prim', $data))
			->setUrl($this->get('url', $data))
			->setTitle($this->get('title', $data))
			->setDescription($this->get('description', $data))
			->setImage($this->get('image', $data))
			->setProviderName($this->get('provider_name', $data));

		$creation = $this->get('creation', $data);
		if ($creation !== '') {
			$card->setCreation((int)strtotime($creation));
		}

		return $card;
	}
}
