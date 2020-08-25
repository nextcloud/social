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

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Traits\TStringTools;
use OCA\Social\Db\ClientAppRequest;
use OCA\Social\Exceptions\ClientAppDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityStream\ClientApp;


/**
 * Class ClientService
 *
 * @package OCA\Social\Service
 */
class ClientService {


	use TStringTools;


	/** @var ClientAppRequest */
	private $clientAppRequest;

	/** @var MiscService */
	private $miscService;


	/**
	 * BoostService constructor.
	 *
	 * @param ClientAppRequest $clientAppRequest
	 * @param MiscService $miscService
	 */
	public function __construct(ClientAppRequest $clientAppRequest, MiscService $miscService) {
		$this->clientAppRequest = $clientAppRequest;
		$this->miscService = $miscService;
	}


	/**
	 * @param ClientApp $clientApp
	 */
	public function createClient(ClientApp $clientApp): void {
		$clientApp->setClientId($this->token(40));
		$clientApp->setClientSecret($this->token(40));

		$this->clientAppRequest->save($clientApp);
	}


	/**
	 * @param string $clientId
	 * @param Person $account
	 */
	public function assignAccount(string $clientId, Person $account) {
		$this->clientAppRequest->assignAccount($clientId, $account->getPreferredUsername());
	}


	/**
	 * @param string $clientId
	 *
	 * @return ClientApp
	 * @throws ClientAppDoesNotExistException
	 */
	public function getByClientId(string $clientId): ClientApp {
		return $this->clientAppRequest->getByClientId($clientId);
	}

}

