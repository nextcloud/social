<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Controller;

use OCA\Social\Entity\Account;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCA\Social\AppInfo\Application as App;
use OCP\DB\ORM\IEntityManager;
use OCP\DB\ORM\IEntityRepository;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Controller responsible to set up social
 */
class SetupController extends Controller {
	private IUserSession $userSession;
	private IEntityManager $entityManager;
	private IEntityRepository $accountRepository;
	private IURLGenerator $generator;

	public function __construct(IRequest $request, IUserSession $userSession, IEntityManager $entityManager, IURLGenerator $generator) {
		parent::__construct(App::APP_NAME, $request);
		$this->userSession = $userSession;
		$this->entityManager = $entityManager;
		$this->accountRepository = $entityManager->getRepository(Account::class);
		$this->generator = $generator;
	}

	/**
	 * Display the account creation page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function setupUser(): Response {
		$account = $this->accountRepository->findOneBy([
			'userId' => $this->userSession->getUser()->getUID(),
		]);
		if ($account !== null) {
			return new RedirectResponse($this->generator->linkToRoute('social.Navigation.timeline'));
		}
		return new TemplateResponse(App::APP_NAME, 'setup-user');
	}

	/**
	 * @NoAdminRequired
	 */
	public function createAccount(string $userName): DataResponse {

	}
}
