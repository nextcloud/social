<?php
/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Traits\TArrayTools;
use daita\MySmallPhpTools\Traits\TStringTools;
use Exception;
use GuzzleHttp\Exception\ClientException;
use OC\User\NoUserException;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;


/**
 * Class CheckService
 *
 * @package OCA\Social\Service
 */
class CheckService {


	use TArrayTools;
	use TStringTools;


	const CACHE_PREFIX = 'social_check_';

	/** @var IUserManager */
	private $userManager;

	/** @var ICache */
	private $cache;

	/** @var IConfig */
	private $config;

	/** @var IClientService */
	private $clientService;

	/** @var IRequest */
	private $request;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var FollowsRequest */
	private $followRequest;

	/** @var CacheActorsRequest */
	private $cacheActorsRequest;

	/** @var StreamDestRequest */
	private $streamDestRequest;

	/** @var StreamRequest */
	private $streamRequest;

	/** @var AccountService */
	private $accountService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * CheckService constructor.
	 *
	 * @param IUserManager $userManager
	 * @param ICache $cache
	 * @param IConfig $config
	 * @param IClientService $clientService
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param FollowsRequest $followRequest
	 * @param CacheActorsRequest $cacheActorsRequest
	 * @param StreamDestRequest $streamDestRequest
	 * @param StreamRequest $streamRequest
	 * @param AccountService $accountService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IUserManager $userManager, ICache $cache, IConfig $config, IClientService $clientService,
		IRequest $request, IURLGenerator $urlGenerator, FollowsRequest $followRequest,
		CacheActorsRequest $cacheActorsRequest, StreamDestRequest $streamDestRequest,
		StreamRequest $streamRequest, AccountService $accountService, ConfigService $configService,
		MiscService $miscService
	) {
		$this->userManager = $userManager;
		$this->cache = $cache;
		$this->config = $config;
		$this->clientService = $clientService;
		$this->request = $request;
		$this->urlGenerator = $urlGenerator;
		$this->followRequest = $followRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->streamDestRequest = $streamDestRequest;
		$this->streamRequest = $streamRequest;
		$this->accountService = $accountService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @return array
	 */
	public function checkDefault(): array {
		$checks = [];
		$checks['wellknown'] = $this->checkWellKnown();

		$success = true;
		foreach ($checks as $check) {
			if (!$check) {
				$success = false;
			}
		}

		return [
			'success' => $success,
			'checks'  => $checks
		];
	}


	/**
	 * @return bool
	 */
	public function checkWellKnown(): bool {
		$state = (bool)($this->cache->get(self::CACHE_PREFIX . 'wellknown') === 'true');
		if ($state === true) {
			return true;
		}

		$address = $this->config->getAppValue('social', 'address', '');

		if ($address !== '' && $this->requestWellKnown($address)) {
			return true;
		}

		if ($this->requestWellKnown(
			$this->request->getServerProtocol() . '://' . $this->request->getServerHost()
		)) {
			return true;
		}

		if ($this->requestWellKnown($this->urlGenerator->getBaseUrl())) {
			return true;
		}

		return false;
	}


	/**
	 * @param bool $light
	 *
	 * @return array
	 */
	public function checkInstallationStatus(bool $light = false): array {
		$this->configService->setCoreValue('public_webfinger', 'social/lib/webfinger.php');
		$this->configService->setCoreValue('public_host-meta', 'social/lib/hostmeta.php');

		$result = [];
		if (!$light) {
			$result = [
				'invalidFollows' => $this->removeInvalidFollows(),
				'invalidNotes'   => $this->removeInvalidNotes()
			];
		}

//		$this->checkStatusTableFollows();
//		$this->checkStatusTableStreamDest();
		try {
			$this->checkLocalAccountFollowingItself();
		} catch (Exception $e) {
		}

		return $result;
	}


	/**
	 * create a fake follow entry. Mandatory to have Home Stream working.
	 */
	public function checkStatusTableFollows() {
		if ($this->followRequest->countFollows() > 0) {
			return;
		}

		$follow = new Follow();
		$follow->setId($this->uuid());
		$follow->setType('Unknown');
		$follow->setActorId($this->uuid());
		$follow->setObjectId($this->uuid());
		$follow->setFollowId($this->uuid());

		$this->followRequest->save($follow);
	}


	/**
	 * create entries in follows so that user follows itself.
	 *
	 * @throws AccountAlreadyExistsException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function checkLocalAccountFollowingItself() {
		$users = $this->userManager->search('');

		foreach ($users as $user) {
			try {
				$actor = $this->accountService->getActorFromUserId($user->getUID());
			} catch (ActorDoesNotExistException $e) {
				continue;
			}

			$this->followRequest->generateLoopbackAccount($actor);
		}
	}


	/**
	 * @return int
	 */
	public function removeInvalidFollows(): int {
		$count = 0;
		$follows = $this->followRequest->getAll();
		foreach ($follows as $follow) {
			try {
				$this->cacheActorsRequest->getFromId($follow->getActorId());
				$this->cacheActorsRequest->getFromId($follow->getObjectId());
			} catch (CacheActorDoesNotExistException $e) {
				$this->followRequest->deleteById($follow->getId());
				$count++;
			}
		}

		$this->miscService->log('removeInvalidFollows removed ' . $count . ' entries', 1);

		return $count;
	}


	/**
	 * @return int
	 */
	public function removeInvalidNotes(): int {
		$count = 0;
		$streams = $this->streamRequest->getAll(Note::TYPE);
		foreach ($streams as $stream) {
			try {
				// Check if it's enough for Note, Announce, ...
				$this->cacheActorsRequest->getFromId($stream->getAttributedTo());
			} catch (CacheActorDoesNotExistException $e) {
				$this->streamRequest->deleteById($stream->getId(), Note::TYPE);
				$count++;
			}
		}

		$this->miscService->log('removeInvalidNotes removed ' . $count . ' entries', 1);

		return $count;
	}


	/**
	 * @param string $base
	 *
	 * @return bool
	 */
	private function requestWellKnown(string $base) {
		try {
			$url = $base . '/.well-known/webfinger';
			$options['nextcloud']['allow_local_address'] = true;
			$response = $this->clientService->newClient()
											->get($url, $options);
			if ($response->getStatusCode() === Http::STATUS_OK) {
				$this->cache->set(self::CACHE_PREFIX . 'wellknown', 'true', 3600);

				return true;
			}
		} catch (ClientException $e) {
		} catch (Exception $e) {
		}

		return false;
	}

}
