<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Providers;

use Exception;
use OC\User\NoUserException;
use OCA\Social\AppInfo\Application;
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
	private IActionFactory $actionFactory;

	private IURLGenerator $urlGenerator;

	private IUserManager $userManager;

	private IL10N $l10n;

	private AccountService $accountService;


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

			$action = $this->actionFactory->newLinkAction($icon, $action, $link, Application::APP_ID);
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
