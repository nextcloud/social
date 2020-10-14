<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2020, Maxence Lange <maxence@artificial-owl.com>
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

namespace OCA\Social\Search;


use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;


/**
 * Class UnifiedSearchProvider
 *
 * @package OCA\Social\Search
 */
class UnifiedSearchProvider implements IProvider {


	const PROVIDER_ID = 'social';
	const ORDER = 12;


	use TArrayTools;


	/** @var IL10N */
	private $l10n;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var StreamService */
	private $streamService;

	/** @var FollowService */
	private $followService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var AccountService */
	private $accountService;

	/** @var SearchService */
	private $searchService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var Person */
	private $viewer;


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
	 * @param MiscService $miscService
	 */
	public function __construct(
		IL10N $l10n, IURLGenerator $urlGenerator, StreamService $streamService, FollowService $followService,
		CacheActorService $cacheActorService, AccountService $accountService, SearchService $searchService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->streamService = $streamService;
		$this->followService = $followService;
		$this->cacheActorService = $cacheActorService;
		$this->accountService = $accountService;
		$this->searchService = $searchService;
		$this->configService = $configService;
		$this->miscService = $miscService;
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
			$this->convertAccounts($this->searchService->searchAccounts($search)),
			$this->convertHashtags($this->searchService->searchHashtags($search))
		);

//			$this->searchService->searchStreamContent($search)

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
			$icon = ($account->hasIcon()) ? $account->getIcon()->getUrl() : '';
			$result[] = new UnifiedSearchResult(
				$icon,
				$account->getPreferredUsername(),
				'@' . $account->getAccount(),
				$account->getUrl(),
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

