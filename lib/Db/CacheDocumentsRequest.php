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


use DateTime;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Document;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheDocumentsRequest extends CacheDocumentsRequestBuilder {


	/**
	 * insert cache about an Actor in database.
	 *
	 * @param Document $document
	 */
	public function save(Document $document) {
		$qb = $this->getCacheDocumentsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($document->getId()))
		   ->setValue('type', $qb->createNamedParameter($document->getType()))
		   ->setValue('url', $qb->createNamedParameter($document->getUrl()))
		   ->setValue('local_copy', $qb->createNamedParameter($document->getLocalCopy()))
		   ->setValue(
			   'creation',
			   $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
		   );
		$qb->execute();
	}


	/**
	 * @param string $url
	 *
	 * @return Document
	 * @throws CacheDocumentDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	public function getFromSource(string $url) {
		$qb = $this->getCacheDocumentsSelectSql();
		$this->limitToUrl($qb, $url);


		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

}

