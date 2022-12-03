<?php

declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Db;

use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Model\StreamAction;

/**
 * Class StreamActionsRequestBuilder
 *
 * @package OCA\Social\Db
 */
class StreamActionsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamActionInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM_ACTIONS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamActionUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM_ACTIONS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamActionSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select('sa.id', 'sa.actor_id', 'sa.stream_id', 'sa.values')
		   ->from(self::TABLE_STREAM_ACTIONS, 'sa');

		$this->defaultSelectAlias = 'sa';
		$qb->setDefaultSelectAlias('sa');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamActionDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_ACTIONS);

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return StreamAction
	 */
	public function parseStreamActionsSelectSql($data): StreamAction {
		$action = new StreamAction();
		$action->importFromDatabase($data);

		return $action;
	}
}
