<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\AppFramework\Http;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Class CacheActorService
 *
 * @package OCA\Social\Service
 */
class CacheActorService {
	use TArrayTools;

	private IURLGenerator $urlGenerator;
	private ActorsRequest $actorsRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private CurlService $curlService;
	private FediverseService $fediverseService;
	private ConfigService $configService;
	private LoggerInterface $logger;

	/**
	 * CacheActorService constructor.
	 */
	public function __construct(
		IUrlGenerator $urlGenerator,
		ActorsRequest $actorsRequest,
		CacheActorsRequest $cacheActorsRequest,
		CurlService $curlService,
		FediverseService $fediverseService,
		ConfigService $configService,
		LoggerInterface $logger,
	) {
		$this->urlGenerator = $urlGenerator;
		$this->actorsRequest = $actorsRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->curlService = $curlService;
		$this->fediverseService = $fediverseService;
		$this->configService = $configService;
		$this->logger = $logger;
	}


	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		$this->cacheActorsRequest->setViewer($viewer);
	}


	/**
	 * @param string $id
	 *
	 * @param bool $refresh
	 *
	 * @return Person
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function getFromId(string $id, bool $refresh = false): Person {
		$posAnchor = strpos($id, '#');
		if ($posAnchor !== false) {
			$id = substr($id, 0, $posAnchor);
		}

		try {
			if ($refresh) {
				throw new CacheActorDoesNotExistException();
			}

			$actor = $this->cacheActorsRequest->getFromId($id);
		} catch (CacheActorDoesNotExistException $e) {
			try {
				$object = $this->curlService->retrieveObject($id);
			} catch (RequestContentException $e) {
				// in case of refresh but remote tells us that actor is gone, we delete it.
				if ($refresh && $e->getCode() === Http::STATUS_GONE) {
					$this->delete($this->cacheActorsRequest->getFromId($id));
				}

				throw $e;
			}

			$this->logger->debug('object retrieved', ['id' => $id, 'object' => $object]);

			/** @var Person $actor */
			$actor = AP::$activityPub->getItemFromData($object);
			if (!AP::$activityPub->isActor($actor)) {
				throw new InvalidResourceException();
			}

			if (parse_url($id, PHP_URL_HOST) !== parse_url($actor->getId(), PHP_URL_HOST)) {
				throw new InvalidOriginException(
					'CacheActorService::getFromId - id: ' . $id . ' - actorId: ' . $actor->getId()
				);
			}

			$actor->setAccount($actor->getPreferredUsername() . '@' . $this->get('_host', $object));
			try {
				$this->save($actor);
			} catch (Exception $e) {
				throw new InvalidResourceException($e->getMessage());
			}
		}

		return $actor;
	}


	/**
	 * @param string $account
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws ActorDoesNotExistException
	 */
	public function getFromLocalAccount(string $account): Person {
		$instance = '';
		$account = ltrim($account, '@');
		if (strrpos($account, '@')) {
			[$account, $instance] = explode('@', $account, 2);
		}

		if ($instance !== ''
			&& $this->configService->getCloudHost() !== $instance
			&& $this->configService->getSocialAddress() !== $instance) {
			throw new CacheActorDoesNotExistException('Address is not local');
		}

		$actor = $this->actorsRequest->getFromUsername($account);
		if ($actor->getDeleted() > 0) {
			throw new CacheActorDoesNotExistException('Account is deleted');
		}

		return $this->cacheActorsRequest->getFromLocalAccount($account);
	}

	/**
	 * @param string $account
	 *
	 * @param bool $retrieve
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RetrieveAccountFormatException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function getFromAccount(string $account, bool $retrieve = true): Person {
		try {
			return $this->getFromLocalAccount($account);
		} catch (CacheActorDoesNotExistException $e) {
		}

		$this->logger->debug('getFromAccount', ['account' => $account, 'retrieve' => $retrieve]);

		try {
			$actor = $this->cacheActorsRequest->getFromAccount($account);

			$this->logger->debug('Found Actor', ['account' => $account, 'actor' => $actor->getSource()]);
		} catch (CacheActorDoesNotExistException $e) {
			$this->logger->debug('Actor not found', ['account' => $account]);

			if (!$retrieve) {
				throw new CacheActorDoesNotExistException();
			}

			$actor = $this->curlService->retrieveAccount($account);
			$actor->setAccount($account);
			try {
				$this->logger->debug('Saving Actor', ['actor' => $actor->getSource()]);

				$this->save($actor);
			} catch (Exception $e) {
				throw new InvalidResourceException($e->getMessage());
			}
		}

		return $actor;
	}


	/**
	 * @param string $search
	 *
	 * @return Person[]
	 */
	public function searchCachedAccounts(string $search): array {
		return $this->cacheActorsRequest->searchAccounts($search);
	}


	/**
	 * @return int
	 * @throws Exception
	 */
	public function missingCacheRemoteActors(): int {
		// TODO - looking for missing cache remote actors...
		$missing = [];

		foreach ($missing as $item) {
			try {
				$this->getFromId($item->getId());
			} catch (Exception $e) {
			}
		}

		return sizeof($missing);
	}


	/**
	 * @return int
	 * @throws Exception
	 */
	public function manageCacheRemoteActors(bool $force = false): int {
		$update = $this->cacheActorsRequest->getRemoteActorsToUpdate($force);

		foreach ($update as $item) {
			try {
				$this->getFromId($item->getId(), true);
			} catch (Exception $e) {
			}
		}

		return sizeof($update);
	}


	/**
	 * @return int
	 * @throws Exception
	 */
	public function manageDetailsRemoteActors(bool $force = false): int {
		$update = $this->cacheActorsRequest->getRemoteActorsToUpdateDetails($force);

		// WARNING: risk of race condition if something else update details on remote actor.
		// Any details update on remote cache-actor must be managed from here.
		foreach ($update as $item) {
			try {
				$this->addRemoteActorDetailCount($item);
				$this->cacheActorsRequest->updateDetails($item);
			} catch (Exception $e) {
			}
		}

		return sizeof($update);
	}


	public function addRemoteActorDetailCount(Person $actor): void {
		try {
			$followers = $this->getCollectionFromId($actor->getFollowers());
			$following = $this->getCollectionFromId($actor->getFollowing());
			$outbox = $this->getCollectionFromId($actor->getOutbox());
		} catch (InvalidResourceException $e) {
			return;
		}

		$count = [
			'followers' => $followers->getTotalItems(),
			'following' => $following->getTotalItems(),
			'post' => $outbox->getTotalItems()
		];
		$actor->setDetailArray('count', $count);
	}


	/**
	 * @param string $id
	 *
	 * @return OrderedCollection
	 * @throws InvalidResourceException
	 */
	private function getCollectionFromId(string $id): OrderedCollection {
		try {
			$object = $this->curlService->retrieveObject($id);
			/** @var OrderedCollection $collection */
			$collection = AP::$activityPub->getItemFromData($object);
		} catch (Exception $e) {
			throw new InvalidResourceException();
		}

		if ($collection->getType() !== OrderedCollection::TYPE) {
			throw new InvalidResourceException();
		}

		return $collection;
	}


	/**
	 * @param Person $actor
	 *
	 * @throws ItemAlreadyExistsException
	 */
	private function save(Person $actor) {
		try {
			$interface = AP::$activityPub->getInterfaceFromType($actor->getType());
			$interface->save($actor);
		} catch (ItemUnknownException $e) {
		}
	}

	/**
	 * @param Person $actor
	 *
	 * @return void
	 */
	private function delete(Person $actor): void {
		try {
			$interface = AP::$activityPub->getInterfaceFromType($actor->getType());
			$interface->delete($actor);
		} catch (ItemUnknownException $e) {
		}
	}


	/**
	 * @param ProbeOptions $options
	 *
	 * @return Person[]
	 */
	public function probeActors(ProbeOptions $options): array {
		return $this->cacheActorsRequest->probeActors($options);
	}


	public function getFromNids(array $ids): array {
		return $this->cacheActorsRequest->getFromNids($ids);
	}
}
