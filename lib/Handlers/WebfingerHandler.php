<?php


declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2021, Maxence Lange <maxence@artificial-owl.com>
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


namespace OCA\Social\Handlers;


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCP\Http\WellKnown\IHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;
use OCP\Http\WellKnown\JrdResponse;
use OCP\IURLGenerator;


/**
 * Class WebfingerHandler
 *
 * @package OCA\Social\Handlers
 */
class WebfingerHandler implements IHandler {


	use TArrayTools;


	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var CacheActorsRequest */
	private $cacheActorsRequest;

	/** @var FediverseService */
	private $fediverseService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var ConfigService */
	private $configService;


	/**
	 * WebfingerHandler constructor.
	 *
	 * @param IURLGenerator $urlGenerator
	 * @param CacheActorsRequest $cacheActorsRequest
	 * @param FediverseService $fediverseService
	 * @param CacheActorService $cacheActorService
	 * @param ConfigService $configService
	 */
	public function __construct(
		IURLGenerator $urlGenerator, CacheActorsRequest $cacheActorsRequest,
		FediverseService $fediverseService, CacheActorService $cacheActorService, ConfigService $configService
	) {
		$this->urlGenerator = $urlGenerator;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->fediverseService = $fediverseService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
	}


	/**
	 * @param string $service
	 * @param IRequestContext $context
	 * @param IResponse|null $response
	 *
	 * @return IResponse|null
	 * @throws CacheActorDoesNotExistException
	 * @throws UnauthorizedFediverseException
	 * @throws SocialAppConfigException
	 */
	public function handle(string $service, IRequestContext $context, ?IResponse $response): ?IResponse {
		$this->fediverseService->jailed();
		if ($service !== 'webfinger') {
			return $response;
		}

		$subject = $this->get('resource', $context->getHttpRequest()->getParams());
		if (!($response instanceof JrdResponse)) {
			$response = new JrdResponse($subject);
		}

		if ($subject === Application::NEXTCLOUD_SUBJECT) {
			$this->manageNextcloudSubject($response);

			return $response;
		}

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

		$href = $this->configService->getSocialUrl() . '@' . $actor->getPreferredUsername();
		$href = rtrim($href, '/');

		$response->addLink('self', 'application/activity+json', $href, []);

// not supported ?
//		$subscribe = $this->urlGenerator->linkToRouteAbsolute('social.OStatus.subscribe') . '?uri={uri}';
//		$response->addLink(
//			'http://ostatus.org/schema/1.0/subscribe',
//			'',
//			'',
//			null,
//			null,
//			['template' => $subscribe]
//		);

		return $response;
	}


	/**
	 * @param JrdResponse $response
	 */
	private function manageNextcloudSubject(JrdResponse $response) {
		$info = [
			'app'     => Application::APP_ID,
			'name'    => Application::APP_NAME,
			'version' => $this->configService->getAppValue('installed_version')
		];

		$response->addLink(Application::APP_REL, '', '', [], $info);
	}

}

