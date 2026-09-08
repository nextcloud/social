<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Search;

use Exception;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Class UnifiedSearchProvider
 *
 * @package OCA\Social\Search
 */
class UnifiedSearchProvider implements IProvider {
	public const PROVIDER_ID = 'social';
	public const ORDER = 12;

	use TArrayTools;

	private IL10N $l10n;
	private IURLGenerator $urlGenerator;
	private StreamService $streamService;
	private FollowService $followService;
	private CacheActorService $cacheActorService;
	private AccountService $accountService;
	private SearchService $searchService;
	private ConfigService $configService;
	private LoggerInterface $logger;

	private ?Person $viewer = null;


	/**
	 * UnifiedSearchProvider constructor.
	 *
	 * @param IL10N $l10n
	 * @param IURLGenerator $urlGenerator
	 * @param StreamService $streamService
	 * @param FollowService $followService
	 * @param CacheActorService $cacheActorService
	 * @param AccountService $accountService
	 * @param SearchService $searchService
	 * @param ConfigService $configService
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		IL10N $l10n,
		IURLGenerator $urlGenerator,
		StreamService $streamService,
		FollowService $followService,
		CacheActorService $cacheActorService,
		AccountService $accountService,
		SearchService $searchService,
		ConfigService $configService,
		LoggerInterface $logger,
	) {
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->streamService = $streamService;
		$this->followService = $followService;
		$this->cacheActorService = $cacheActorService;
		$this->accountService = $accountService;
		$this->searchService = $searchService;
		$this->configService = $configService;
		$this->logger = $logger;
	}


	/**
	 * return unique id of the provider
	 */
	public function getId(): string {
		return self::PROVIDER_ID;
	}


	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->l10n->t('Social');
	}


	/**
	 * @param string $route
	 * @param array $routeParameters
	 *
	 * @return int
	 */
	public function getOrder(string $route, array $routeParameters): int {
		return self::ORDER;
	}


	/**
	 * @param IUser $user
	 * @param ISearchQuery $query
	 *
	 * @return SearchResult
	 * @throws AccountDoesNotExistException
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$this->initViewer();
		$search = trim($query->getTerm());

		$result = array_merge(
			$this->convertAccounts($this->searchService->searchUri($search)),
			$this->convertAccounts($this->searchService->searchAccounts($search)),
			$this->convertHashtags($this->searchService->searchHashtags($search))
		);

		//	$this->searchService->searchStreamContent($search)

		return SearchResult::paginated(
			$this->l10n->t('Social'), $result, ($query->getCursor() ?? 0) + $query->getLimit()
		);
	}


	/**
	 * TODO: switch to SessionService
	 *
	 * @param bool $exception
	 *
	 * @throws AccountDoesNotExistException
	 */
	private function initViewer(bool $exception = false) {
		if (!isset($this->userId)) {
			if ($exception) {
				throw new AccountDoesNotExistException('userId not defined');
			}

			return;
		}

		try {
			$this->viewer = $this->accountService->getActorFromUserId($this->userId, true);

			$this->streamService->setViewer($this->viewer);
			$this->followService->setViewer($this->viewer);
			$this->cacheActorService->setViewer($this->viewer);
		} catch (Exception $e) {
			if ($exception) {
				throw new AccountDoesNotExistException(
					'unable to initViewer - ' . get_class($e) . ' - ' . $e->getMessage()
				);
			}
		}
	}


	/**
	 * @param Person[] $accounts
	 *
	 * @return UnifiedSearchResult[]
	 */
	private function convertAccounts(array $accounts): array {
		$result = [];
		foreach ($accounts as $account) {
			$icon = ($account->hasIcon()) ? $account->getIcon()
				->getUrl() : '';
			$result[] = new UnifiedSearchResult(
				$icon,
				$account->getPreferredUsername(),
				'@' . $account->getAccount(),
				$this->urlGenerator->linkToRoute('social.ActivityPub.actorAlias', ['username' => $account->getAccount()]),
				$icon
			);
		}

		return $result;
	}


	/**
	 * @param array $hashtags
	 *
	 * @return UnifiedSearchResult[]
	 */
	private function convertHashtags(array $hashtags): array {
		$result = [];
		foreach ($hashtags as $hashtag) {
			$tag = $hashtag['hashtag'];
			$result[] = new UnifiedSearchResult(
				'',
				$hashtag['trend']['10d'] . ' posts related to \'' . $tag . '\'',
				'#' . $tag,
				$this->urlGenerator->linkToRouteAbsolute(
					'social.Navigation.timeline', ['path' => 'tags/' . $tag]
				),
				''
			);
		}

		return $result;
	}
}
