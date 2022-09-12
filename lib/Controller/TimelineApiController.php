<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Controller;

use OCA\Social\Entity\MediaAttachment;
use OCA\Social\Service\AccountFinder;
use OCA\Social\Service\PostServiceStatus;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\DB\ORM\IEntityManager;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\AppFramework\Controller;
use OCP\Files\IMimeTypeDetector;
use OCP\Image;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

class TimelineApiController extends Controller {

	private IL10N $l10n;
	private IMimeTypeDetector $mimeTypeDetector;
	private IAppData $appData;
	private IUserSession $userSession;
	private AccountFinder $accountFinder;
	private IEntityManager $entityManager;
	private IURLGenerator $generator;
	private LoggerInterface $logger;
	private PostServiceStatus $postServiceStatus;

	public function __construct(
		string $appName,
		IRequest $request,
		IL10N $l10n,
		IUserSession $userSession,
		AccountFinder $accountFinder,
		IEntityManager $entityManager,
		IURLGenerator $generator,
		LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
		$this->l10n = $l10n;
		$this->userSession = $userSession;
		$this->accountFinder = $accountFinder;
		$this->entityManager = $entityManager;
		$this->generator = $generator;
		$this->logger = $logger;
	}

	/**
	 * Public timeline
	 *
	 * @params bool $local Show only local statuses? Defaults to false.
	 * @params bool $remote Show only remote statuses? Defaults to false.
	 * @params bool $only_media Show only statuses with media attached? Defaults to false.
	 * @params string $max_id Return results older than this id
	 * @params string $since_id Return results newer than this id
	 * @params string $min_id Return results immediately newer than this id
	 * @params int $limit Maximum number of results to return. Defaults to 20.
	 */
	public function publicTimeline(
		bool $local = null,
		bool $remote = null,
		bool $only_media = null,
		string $max_id = null,
		string $since_id = null,
		string $min_id = null,
		int $limit = null,
	): DataResponse {
		if ($local === null) {
			$local = false;
		}
		if ($remote === null) {
			$remote = false;
		}
		if ($only_media === null) {
			$only_media = false;
		}
		if ($limit === null || $limit > 100) {
			$limit = 20;
		}

		$statusRepository = $this->entityManager->getRepository(Status::class);
		$statusRepository->createQuery('SELECT s FROM \OCA\Social\Entity\Status s WHERE s.visibility = :visibility');
	}
}
