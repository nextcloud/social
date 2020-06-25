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


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class StreamDestRequestBuilder
 *
 * @package OCA\Social\Db
 */
class StreamDestRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamDestInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM_DEST);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamDestUpdateSql(): IQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STREAM_DEST);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getStreamDestSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select('sd.actor_id', 'sd.stream_id', 'sd.type')
		   ->from(self::TABLE_STREAM_DEST, 'sd');

		$this->defaultSelectAlias = 'sd';
		$qb->setDefaultSelectAlias('sd');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamDestDeleteSql(): IQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_DEST);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function countStreamDestSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
		   ->from(self::TABLE_STREAM_DEST, 'sd');

		$this->defaultSelectAlias = 'sd';
		$qb->setDefaultSelectAlias('sd');

		return $qb;
	}


}

