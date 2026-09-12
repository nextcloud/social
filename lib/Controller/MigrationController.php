<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Service\MigrationArchiveService;
use OCA\Social\Service\MigrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Taking your account's data out, and putting it back.
 *
 * These are the Migration page's three buttons. They are session routes rather
 * than client-API ones on purpose: they are for the person sitting in front of
 * the browser, not for a Mastodon client, and an archive of everything an
 * account ever wrote is not something a third-party token should be able to
 * ask for.
 *
 * The work is `MigrationArchiveService`, which drives the same
 * `SocialMigrator` that Nextcloud's whole-account export uses — so what
 * travels, and what deliberately does not (the private key, above all), is
 * decided in one place for both.
 */
class MigrationController extends Controller {
	/** A refused upload says which limit it hit rather than "failed". */
	private const IMPORT_MAX_SIZE = 100 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private MigrationArchiveService $archiveService,
		private MigrationService $migrationService,
		private LoggerInterface $logger,
	) {
		parent::__construct('social', $request);
	}

	/** The account's own Social data, as a zip file. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 6, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/export')]
	public function export(): Response {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$path = $this->archiveService->export($this->userId);
			$contents = file_get_contents($path);
			if ($contents === false) {
				throw new \RuntimeException('the finished archive could not be read back');
			}

			// Read into memory and handed over rather than streamed from disk:
			// the temp file is cleaned up at the end of the request, and a
			// stream response outlives that.
			//
			// `DataDisplayResponse` with the disposition written out, rather
			// than `DataDownloadResponse`, which builds that header through
			// Symfony's HeaderUtils — a class the server has and this app does
			// not depend on, so the download would work on a server and be
			// unreachable from a test. The name is already reduced to
			// `[A-Za-z0-9._-]` by `filename()`, so there is nothing in it that
			// could break out of the quotes.
			$response = new DataDisplayResponse($contents, Http::STATUS_OK, [
				'Content-Type' => 'application/zip',
			]);
			// after construction, not as a constructor header: the constructor
			// sets `inline; filename=""` itself, and does it last
			$response->addHeader(
				'Content-Disposition',
				'attachment; filename="' . $this->archiveService->filename($this->userId) . '"'
			);

			return $response;
		} catch (Throwable $e) {
			$this->logger->error('Social export failed', ['userId' => $this->userId, 'exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Reads an archive from the export button back into this account.
	 *
	 * Additive: it restores the profile, the follows, the blocks, the mutes
	 * and the marks, and reports what the outbox holds rather than re-posting
	 * it — see `SocialMigrator::import()`. Nothing here deletes anything.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 6, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/import')]
	public function import(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$file = $_FILES['file'] ?? [];
		if ($file === [] || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'no archive was uploaded'], Http::STATUS_BAD_REQUEST);
		}

		if (($file['size'] ?? 0) > self::IMPORT_MAX_SIZE) {
			return new DataResponse(
				['error' => 'this archive is larger than ' . (self::IMPORT_MAX_SIZE / 1024 / 1024) . ' MB'],
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		}

		try {
			$log = $this->archiveService->import($this->userId, $file['tmp_name']);

			return new DataResponse(['imported' => true, 'log' => $log], Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->warning('Social import failed', ['userId' => $this->userId, 'exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Follows from another server's export.
	 *
	 * Mastodon, Pixelfed, GoToSocial and Akkoma all export the people you
	 * follow as `following_accounts.csv`, and this reads that file — which is
	 * also the one in the archive the export button produces. The follows are
	 * the only part of an account that cannot be carried in a file: a follow
	 * is a relationship two servers have to agree on, so each one is requested
	 * again from here.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 4, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/follows')]
	public function importFollows(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$file = $_FILES['file'] ?? [];
		if ($file === [] || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'no file was uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$csv = file_get_contents($file['tmp_name']);
		if ($csv === false) {
			return new DataResponse(['error' => 'the uploaded file could not be read'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new DataResponse($this->migrationService->importFollows($this->userId, $csv), Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->warning('importing follows failed', ['userId' => $this->userId, 'exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
