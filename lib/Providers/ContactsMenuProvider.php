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


namespace OCA\Social\Providers;


use Exception;
use OC\User\NoUserException;
use OCA\Social\Service\AccountService;
use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\Contacts\ContactsMenu\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;


/**
 * Class ContactsMenuProvider
 *
 * @package OCA\Social\Providers
 */
class ContactsMenuProvider implements IProvider {


	/** @var IActionFactory */
	private $actionFactory;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var IUserManager */
	private $userManager;

	/** @var IL10N */
	private $l10n;

	/** @var AccountService */
	private $accountService;


	/**
	 * ContactsMenuProvider constructor.
	 *
	 * @param IActionFactory $actionFactory
	 * @param IURLGenerator $urlGenerator
	 * @param IUserManager $userManager
	 * @param IL10N $l10n
	 * @param AccountService $accountService
	 */
	public function __construct(
		IActionFactory $actionFactory, IURLGenerator $urlGenerator, IUserManager $userManager, IL10N $l10n,
		AccountService $accountService
	) {
		$this->actionFactory = $actionFactory;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->accountService = $accountService;
	}


	/**
	 * @param IEntry $entry
	 */
	public function process(IEntry $entry): void {
		try {
			$user = $this->getUserFromEntry($entry);
			$actor = $this->accountService->getActorFromUserId($user->getUID());

			$action = $this->l10n->t('Follow %s on Social', [$user->getDisplayName()]);
			$icon = $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath('social', 'social-dark.svg')
			);
			$link = $this->urlGenerator->linkToRouteAbsolute(
				'social.ActivityPub.actorAlias', ['username' => $actor->getPreferredUsername()]
			);

			$action = $this->actionFactory->newLinkAction($icon, $action, $link);
			$entry->addAction($action);
		} catch (Exception $e) {
			return;
		}

	}


	/**
	 * @param IEntry $entry
	 *
	 * @return IUser
	 * @throws NoUserException
	 */
	private function getUserFromEntry(IEntry $entry): IUser {
		$userId = $entry->getProperty('UID');
		if ($userId === null) {
			throw new NoUserException();
		}

		if ($entry->getProperty('isLocalSystemBook') !== true) {
			throw new NoUserException();
		}

		$user = $this->userManager->get($userId);
		if (!$user instanceof IUser) {
			throw new NoUserException();
		}

		return $user;
	}

}
