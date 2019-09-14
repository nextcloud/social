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


use daita\MySmallPhpTools\Db\ExtendedQueryBuilder;
use DateInterval;
use DateTime;
use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;


/**
 * Class SocialQueryBuilder
 *
 * @package OCA\Push\Db
 */
class SocialQueryBuilder extends ExtendedQueryBuilder {


	/** @var Person */
	private $viewer = null;


	/**
	 * @return bool
	 */
	public function hasViewer(): bool {
		return ($this->viewer !== null);
	}

	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer): void {
		$this->viewer = $viewer;
	}

	/**
	 * @return Person
	 */
	public function getViewer(): Person {
		return $this->viewer;
	}


	/**
	 * @param string $id
	 */
	public function generatePrimaryKey(string $id) {
		$this->setValue('id_prim', $this->createNamedParameter(hash('sha512', $id)));
	}


	/**
	 * Limit the request to the Type
	 *
	 * @param string $type
	 *
	 * @return SocialQueryBuilder
	 */
	public function limitToType(string $type): self {
		$this->limitToDBField('type', $type, false);

		return $this;
	}


	/**
	 * Limit the request to the ActivityId
	 *
	 * @param string $activityId
	 */
	protected function limitToActivityId(string $activityId) {
		$this->limitToDBField('activity_id', $activityId, false);
	}


	/**
	 * Limit the request to the Id (string)
	 *
	 * @param string $id
	 */
	protected function limitToInReplyTo(string $id) {
		$this->limitToDBField('in_reply_to', $id, false);
	}


	/**
	 * Limit the request to the sub-type
	 *
	 * @param string $subType
	 */
	protected function limitToSubType(string $subType) {
		$this->limitToDBField('subtype', $subType);
	}


	/**
	 * @param string $type
	 */
	protected function filterType(string $type) {
		$this->filterDBField('type', $type);
	}


	/**
	 * Limit the request to the Preferred Username
	 *
	 * @param string $username
	 */
	protected function limitToPreferredUsername(string $username) {
		$this->limitToDBField('preferred_username', $username, false);
	}

	/**
	 * search using username
	 *
	 * @param string $username
	 */
	protected function searchInPreferredUsername(string $username) {
		$dbConn = $this->getConnection();
		$this->searchInDBField('preferred_username', $dbConn->escapeLikeParameter($username) . '%');
	}


	/**
	 * Limit the request to the ActorId
	 */
	protected function limitToPublic() {
		$this->limitToDBFieldInt('public', 1);
	}


	/**
	 * Limit the request to the token
	 *
	 * @param string $token
	 */
	protected function limitToToken(string $token) {
		$this->limitToDBField('token', $token);
	}

	/**
	 * Limit the results to a given number
	 *
	 * @param int $limit
	 */
	protected function limitResults(int $limit) {
		$this->setMaxResults($limit);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $hashtag
	 */
	protected function limitToHashtag(string $hashtag) {
		$this->limitToDBField('hashtag', $hashtag, false);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $hashtag
	 * @param bool $all
	 */
	protected function searchInHashtag(string $hashtag, bool $all = false) {
		$dbConn = $this->getConnection();
		$this->searchInDBField('hashtag', (($all) ? '%' : '') . $dbConn->escapeLikeParameter($hashtag) . '%');
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $actorId
	 * @param string $alias
	 */
	protected function limitToActorId(string $actorId, string $alias = '') {
		$this->limitToDBField('actor_id', $actorId, false, $alias);
	}


	/**
	 * Limit the request to the FollowId
	 *
	 * @param string $followId
	 */
	protected function limitToFollowId(string $followId) {
		$this->limitToDBField('follow_id', $followId, false);
	}


	/**
	 * Limit the request to the FollowId
	 *
	 * @param bool $accepted
	 * @param string $alias
	 */
	protected function limitToAccepted(bool $accepted, string $alias = '') {
		$this->limitToDBField('accepted', ($accepted) ? '1' : '0', true, $alias);
	}


	/**
	 * Limit the request to the ServiceId
	 *
	 * @param string $objectId
	 */
	protected function limitToObjectId(string $objectId) {
		$this->limitToDBField('object_id', $objectId, false);
	}


	/**
	 * Limit the request to the account
	 *
	 * @param string $account
	 */
	protected function limitToAccount(string $account) {
		$this->limitToDBField('account', $account, false);
	}


	/**
	 * Limit the request to the account
	 *
	 * @param string $account
	 */
	protected function searchInAccount(string $account) {
		$dbConn = $this->getConnection();
		$this->searchInDBField('account', $dbConn->escapeLikeParameter($account) . '%');
	}


	/**
	 * Limit the request to the creation
	 *
	 * @param int $delay
	 *
	 * @throws Exception
	 */
	protected function limitToCaching(int $delay = 0) {
		$date = new DateTime('now');
		$date->sub(new DateInterval('PT' . $delay . 'M'));

		$this->limitToDBFieldDateTime('caching', $date, true);
	}


	/**
	 * Limit the request to the url
	 *
	 * @param string $url
	 */
	protected function limitToUrl(string $url) {
		$this->limitToDBField('url', $url);
	}


	/**
	 * Limit the request to the url
	 *
	 * @param string $actorId
	 */
	protected function limitToAttributedTo(string $actorId) {
		$this->limitToDBField('attributed_to', $actorId, false);
	}


	/**
	 * Limit the request to the status
	 *
	 * @param int $status
	 */
	protected function limitToStatus(int $status) {
		$this->limitToDBFieldInt('status', $status);
	}


	/**
	 * Limit the request to the instance
	 *
	 * @param string $address
	 */
	protected function limitToAddress(string $address) {
		$this->limitToDBField('address', $address);
	}


	/**
	 * Limit the request to the instance
	 *
	 * @param bool $local
	 */
	protected function limitToLocal(bool $local) {
		$this->limitToDBField('local', ($local) ? '1' : '0');
	}


	/**
	 * Limit the request to the parent_id
	 *
	 * @param string $parentId
	 */
	protected function limitToParentId(string $parentId) {
		$this->limitToDBField('parent_id', $parentId);
	}


}

