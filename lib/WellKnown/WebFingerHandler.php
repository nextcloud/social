<?php

declare(strict_types=1);

/*
 * @copyright 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Social\WellKnown;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCP\Http\WellKnown\IHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;
use OCP\Http\WellKnown\JrdResponse;
use OCP\IURLGenerator;
use function rtrim;
use function strpos;
use function substr;

class WebFingerHandler implements IHandler {

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var CacheActorsRequest */
	private $cacheActorsRequest;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var FediverseService */
	private $fediverseService;

	/** @var ConfigService */
	private $configService;

	public function __construct(IURLGenerator $urlGenerator,
								CacheActorsRequest $cacheActorsRequest,
								CacheActorService $cacheActorService,
								FediverseService $fediverseService,
								ConfigService $configService) {
		$this->urlGenerator = $urlGenerator;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->cacheActorService = $cacheActorService;
		$this->fediverseService = $fediverseService;
		$this->configService = $configService;
	}

	public function handle(string $service, IRequestContext $context, ?IResponse $previousResponse): ?IResponse {
		if ($service !== 'webfinger') {
			return $previousResponse;
		}

		$this->fediverseService->jailed();

		$subject = $context->getHttpRequest()->getParam('resource', '');

		if (strpos($subject, 'acct:') === 0) {
			$subject = substr($subject, 5);
		}

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($subject);
		} catch (CacheActorDoesNotExistException $e) {
			try {
				$actor = $this->cacheActorsRequest->getFromId($subject);
			} catch (CacheActorDoesNotExistException $e) {
				// Nothing to add, return what was already defined

				return $previousResponse;
			}

			if (!$actor->isLocal()) {
				// Nothing to add, return what was already defined

				return $previousResponse;
			}
		} catch (SocialAppConfigException $e) {
			// Something isn't right, we can't answer the request at the moment

			return $previousResponse;
		}

		$href = rtrim($this->configService->getSocialUrl() . '@' . $actor->getPreferredUsername(), '/');
		$subscribe = $this->urlGenerator->linkToRouteAbsolute('social.OStatus.subscribe') . '?uri={uri}';

		$response = $previousResponse;
		if (!($response instanceof JrdResponse)) {
			// We override null or any other types
			$response = new JrdResponse($subject);
		}

		return $response
			->addLink('self', 'application/activity+json', $href)
			->addLink('http://ostatus.org/schema/1.0/subscribe', null, $subscribe);
	}
}
