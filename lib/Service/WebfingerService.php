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


use OC\Webfinger\Event\WebfingerEvent;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\WebfingerLink;
use OCP\IURLGenerator;

class WebfingerService {


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

	/** @var MiscService */
	private $miscService;


	/**
	 * WebfingerService constructor.
	 *
	 * @param IURLGenerator $urlGenerator
	 * @param CacheActorsRequest $cacheActorsRequest
	 * @param CacheActorService $cacheActorService
	 * @param FediverseService $fediverseService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IURLGenerator $urlGenerator, CacheActorsRequest $cacheActorsRequest,
		CacheActorService $cacheActorService, FediverseService $fediverseService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->urlGenerator = $urlGenerator;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->cacheActorService = $cacheActorService;
		$this->fediverseService = $fediverseService;
		$this->configService = $configService;
		$this->miscService = $miscService;

	}


	/**
	 * @param WebfingerEvent $event
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws UnauthorizedFediverseException
	 * @throws SocialAppConfigException
	 */
	public function webfinger(WebfingerEvent $event) {
		$this->fediverseService->jailed();

		$subject = $event->getWebfinger()
						 ->getSubject();

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

		$linkPerson = new WebfingerLink();
		$linkPerson->setRel('self');
		$linkPerson->setType('application/activity+json');
		$linkPerson->setHref($href);

		$linkOstatus = new WebfingerLink();
		$linkOstatus->setRel('http://ostatus.org/schema/1.0/subscribe');
		$subscribe = $this->urlGenerator->linkToRouteAbsolute('social.OStatus.subscribe') . '?uri={uri}';
		$linkOstatus->setTemplate($subscribe);

		$event->getWebfinger()
			  ->addLinkSerialized($linkPerson)
			  ->addLinkSerialized($linkOstatus);
	}

}

