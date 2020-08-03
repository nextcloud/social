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


use daita\MySmallPhpTools\Exceptions\ArrayNotFoundException;
use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Model\SimpleDataStore;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\HostMetaException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Test;


/**
 * Class TestService
 *
 * @package OCA\Social\Service
 */
class TestService {


	use TArrayTools;


	/** @var CurlService */
	private $curlService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * PostService constructor.
	 *
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		CurlService $curlService, ConfigService $configService, MiscService $miscService
	) {
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	public function testWebfinger(SimpleDataStore $tests) {
		$account = ltrim($tests->g('account'), '@');

		if (!filter_var($account, FILTER_VALIDATE_EMAIL)) {
			throw new InvalidResourceException('account format is not valid');
		}

		list($username, $host) = explode('@', $account);
		if ($username === null || $host === null) {
			throw new InvalidResourceException('account format should be valid');
		}

		$testHostMeta = new Test('host-meta', Test::SEVERITY_OPTIONAL);
		$protocols = ['https', 'http'];
		try {
			$path = $this->curlService->hostMeta($host, $protocols);
			$testHostMeta->s('host', $host);
			$testHostMeta->s('path', $path);
			$testHostMeta->sArray('protocol', $protocols);
			$testHostMeta->setSuccess(true);
		} catch (HostMetaException $e) {
			$testHostMeta->addMessage($e->getMessage());
			$path = '/.well-known/webfinger';
		}
		$tests->aObj('tests', $testHostMeta);


		$request = new Request($path);
		$request->addData('resource', 'acct:' . $account);
		$request->setAddress($host);
		$request->setProtocols($protocols);

		$testWebfinger = new Test('webfinger', Test::SEVERITY_MANDATORY);
		$testWebfinger->sObj('request', $request);
		$result = [];
		try {
			$result = $this->curlService->retrieveJson($request);
			$testWebfinger->sArray('result', $result);
			$testWebfinger->setSuccess(true);
		} catch (Exception $e) {
			$testWebfinger->addMessage(get_class($e));
			$testWebfinger->addMessage($e->getMessage());
		}
		$tests->aObj('tests', $testWebfinger);

		$testActorLink = new Test('actor-link', Test::SEVERITY_MANDATORY);
		$link = [];
		try {
			$links = $this->getArray('links', $result);
			$link = $this->extractArray('rel', 'self', $links);
			$testActorLink->aArray('link', $link);
			$testActorLink->setSuccess(true);
		} catch (ArrayNotFoundException $e) {
			$testActorLink->addMessage(get_class($e));
			$testActorLink->addMessage('cannot find actor-link in links');
			$testActorLink->aArray('links', $links);
		}

		$tests->aObj('tests', $testActorLink);


		$id = $this->get('href', $link, '');

		$testActorData = new Test('actor-data', Test::SEVERITY_MANDATORY);
		$testActorData->a('id', $id);
		$data = [];
		try {
			$data = $this->curlService->retrieveObject($id);
			$testActorData->setSuccess(true);
			$testActorData->sArray('data', $data);
		} catch (Exception $e) {
			$testActorData->addMessage(get_class($e));
			$testActorData->addMessage($e->getMessage());
		}

		$tests->aObj('tests', $testActorData);


		$testActor = new Test('actor', Test::SEVERITY_MANDATORY);
		try {
			/** @var Person $actor */
			$actor = AP::$activityPub->getItemFromData($data);
			if (!AP::$activityPub->isActor($actor)) {
				throw new ItemUnknownException('Actor is not an Actor');
			}

			if (strtolower($actor->getId()) !== strtolower($id)) {
				throw new InvalidOriginException(
					'CurlService::retrieveAccount - id: ' . $id . ' - actorId: ' . $actor->getId()
				);
			}

			$testActor->setSuccess(true);
			$testActor->sObj('actor', $actor);
		} catch (Exception $e) {
			$testActor->addMessage(get_class($e));
			$testActor->addMessage($e->getMessage());
		}

		$tests->aObj('tests', $testActor);

	}

}

