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

class StatusApiController extends Controller {

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
		PostServiceStatus $postServiceStatus
	) {
		parent::__construct($appName, $request);
		$this->l10n = $l10n;
		$this->userSession = $userSession;
		$this->accountFinder = $accountFinder;
		$this->entityManager = $entityManager;
		$this->generator = $generator;
		$this->logger = $logger;
		$this->postServiceStatus = $postServiceStatus;
	}

	/**
	 * Publish new status
	 * @NoAdminRequired
	 */
	public function publishStatus(
		?string $status,
		array $media_ids,
		?bool $sensitive,
		?string $spoiler_text
	): DataResponse {
		if ($sensitive === null) {
			$sensitive = false;
		}
		$account = $this->accountFinder->getCurrentAccount($this->userSession->getUser());
		$status = $this->postServiceStatus->create($account, [
			'text' => $status,
			'spoilerText' => $spoiler_text,
			'sensitive' => $sensitive,
		]);
		return new DataResponse($status->toMastodonApi());
	}

	/**
	 * View specific status
	 * @NoAdminRequired
	 */
	public function getStatus(string $id): DataResponse {
		$statusRepository = $this->entityManager->getRepository(Status::class);
		$status = $statusRepository->findOneBy([
			'id' => $id,
		]);
		if ($status === null) {
			return new DataResponse(["error" => "Record not found"]);
		}

		$account = $this->accountFinder->getCurrentAccount($this->userSession->getUser());
		if (!$this->canRead($account, $status)) {
			return new DataResponse(["error" => "Record not found"]);
		}

		return new DataResponse($status->toMastodonApi());
	}

	/**
	 * Delete specific status
	 * @NoAdminRequired
	 */
	public function deleteStatus(string $id): DataResponse {
		$statusRepository = $this->entityManager->getRepository(Status::class);
		$status = $statusRepository->findOneBy([
			'id' => $id,
		]);
		if ($status === null) {
			return new DataResponse(["error" => "Record not found"]);
		}
		$account = $this->accountFinder->getCurrentAccount($this->userSession->getUser());

		if ($status->getAccount()->getId() !== $account->getId()) {
			return new DataResponse(["error" => "Record not found"]);
		}
		$this->entityManager->delete($status);
		$this->entityManager->flush();

		return new DataResponse($status->toMastodonApi());
	}

	/**
	 * Context of a specific status
	 * @NoAdminRequired
	 */
	public function contextStatus(string $id): DataResponse {
		$statusRepository = $this->entityManager->getRepository(Status::class);
		$status = $statusRepository->findOneBy([
			'id' => $id,
		]);
		if ($status === null) {
			return new DataResponse(["error" => "Record not found"]);
		}

		$account = $this->accountFinder->getCurrentAccount($this->userSession->getUser());
		if (!$this->canRead($account, $status)) {
			return new DataResponse(["error" => "Record not found"]);
		}

		return new DataResponse([
			'ancestors' => [],
			'descendants' => [],
		]);
	}

	public function reblogedBy(string $id): DataResponse {
		$statusRepository = $this->entityManager->getRepository(Status::class);
		$status = $statusRepository->findOneBy([
			'id' => $id,
		]);
		if ($status === null) {
			return new DataResponse(["error" => "Record not found"]);
		}

		$account = $this->accountFinder->getCurrentAccount($this->userSession->getUser());
		if (!$this->canRead($account, $status)) {
			return new DataResponse(["error" => "Record not found"]);
		}

		return new DataResponse([]);
	}

	private function canRead(Account $accout, Status $status): bool {
		return true;
	}
}