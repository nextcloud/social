<?php
declare(strict_types=1);

/**
 * @copyright 2018 Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2022 Carl Schwan <carl@carlschwan.eu>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\WellKnown;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCP\Http\WellKnown\IHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;
use OCP\Http\WellKnown\JrdResponse;
use OCP\IURLGenerator;

class WebfingerHandler implements IHandler {
	private IURLGenerator $urlGenerator;
	private CacheActorsRequest $cacheActorsRequest;
	private CacheActorService $cacheActorService;
	private FediverseService $fediverseService;
	private ConfigService $configService;

	public function __construct(
		IURLGenerator $urlGenerator, CacheActorsRequest $cacheActorsRequest,
		CacheActorService $cacheActorService, FediverseService $fediverseService,
		ConfigService $configService
	) {

		$this->urlGenerator = $urlGenerator;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->cacheActorService = $cacheActorService;
		$this->fediverseService = $fediverseService;
		$this->configService = $configService;
	}

	public function handle(string $service, IRequestContext $context, ?IResponse $previousResponse): ?IResponse {
		// See https://docs.joinmastodon.org/spec/webfinger/

		$this->fediverseService->jailed();
		$subject = $context->getHttpRequest()->getParam('resource');

		if (strpos($subject, 'acct:') === 0) {
			$subject = substr($subject, 5);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($subject);
		} catch (CacheActorDoesNotExistException $e) {
			$actor = $this->cacheActorsRequest->getFromId($subject);
			if (!$actor->isLocal()) {
				throw new CacheActorDoesNotExistException();
			}
		}

		$response = new JrdResponse($subject);

		// ActivityPub profile
		$href = $this->configService->getSocialUrl() . '@' . $actor->getPreferredUsername();
		$href = rtrim($href, '/');
		$response->addAlias($href);
		$response->addLink('self', 'application/activity+json', $href);

		// Nextcloud profile page
		$profilePageUrl =  $this->urlGenerator->linkToRouteAbsolute('core.ProfilePage.index', [
			'targetUserId' => $actor->getPreferredUsername()
		]);
		$response->addAlias($profilePageUrl);
		$response->addLink('http://webfinger.net/rel/profile-page', 'text/html', $profilePageUrl);

		// Ostatus subscribe url
		// JrdResponse doesn't support template
		// $subscribe = $this->urlGenerator->linkToRouteAbsolute('social.OStatus.subscribe') . '?uri={uri}';
		// $response->addLink('http://ostatus.org/schema/1.0/subscribe', $subscribe);

		return $response;
	}
}
