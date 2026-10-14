<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Generator;
use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Identity\DidControlService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\BlueskyArchiveService;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Response\StreamedPiecesResponse;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a person needs to keep their Bluesky account without this server:
 * who controls its DID, and the account as files to download.
 */
class AtprotoRecoveryController extends Controller {
	/** how much of the archive is read and sent at a time */
	private const CHUNK = 1024 * 1024;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AtprotoConfig $config,
		private AccountService $accountService,
		private IdentityService $identities,
		private BlueskyArchiveService $archive,
		private DidControlService $control,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `GET /api/v1/social/bluesky/control`: who controls the viewer's DID,
	 * as the PLC directory has it now — its rotation keys by role, the
	 * latest operation, and until when it can still be undone and by
	 * which keys. Read-only; the directory's answer is kept a minute.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/control')]
	public function control(): DataResponse {
		$identity = $this->ownIdentity();
		if ($identity === null || !BlueskyArchiveService::exportable($identity)) {
			return new DataResponse(['error' => 'No Bluesky account for this account', 'code' => 'no-identity'], Http::STATUS_NOT_FOUND);
		}
		try {
			return new DataResponse($this->control->forIdentity($identity));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage(), 'code' => 'did-not-found'], Http::STATUS_NOT_FOUND);
		} catch (AtprotoException $e) {
			$this->logger->info('PLC directory not reached for the DID\'s keys', ['did' => $identity->did, 'exception' => $e]);

			return new DataResponse(['error' => 'The PLC directory could not be reached', 'code' => 'directory-unreachable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * `GET /api/v1/social/bluesky/archive`: the viewer's Bluesky account as
	 * a zip file — repository, blobs, the DID's log and document, the
	 * offline recovery script. A download the browser navigates to, so no
	 * request token; the password is confirmed first, and an account gets
	 * a few an hour, as each reads the whole repository.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 3, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/archive')]
	public function archive(): Response {
		$identity = $this->ownIdentity();
		if ($identity === null || !BlueskyArchiveService::exportable($identity)) {
			return new DataResponse(['error' => 'No Bluesky account for this account', 'code' => 'no-identity'], Http::STATUS_NOT_FOUND);
		}
		try {
			$path = $this->archive->zip($identity);
		} catch (Throwable $e) {
			$this->logger->error('Bluesky export failed', ['did' => $identity->did, 'exception' => $e]);

			return new DataResponse(['error' => 'The archive could not be made', 'code' => 'export-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new StreamedPiecesResponse(self::pieces($path), Http::STATUS_OK, [
			'Content-Type' => 'application/zip',
			'Content-Length' => (string)filesize($path),
			// the name is reduced to [A-Za-z0-9._-] by filename()
			'Content-Disposition' => 'attachment; filename="' . $this->archive->filename($identity) . '"',
		]);
	}

	/**
	 * The finished archive in chunks, removed once it is sent.
	 *
	 * @return Generator<int, string>
	 */
	private static function pieces(string $path): Generator {
		$file = fopen($path, 'rb');
		try {
			while ($file !== false && !feof($file)) {
				$chunk = fread($file, self::CHUNK);
				if ($chunk === false || $chunk === '') {
					break;
				}
				yield $chunk;
			}
		} finally {
			if ($file !== false) {
				fclose($file);
			}
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	/**
	 * The viewer's identity as it is, never made; null when Bluesky is off
	 * or there is none.
	 */
	private function ownIdentity(): ?Identity {
		if (!$this->config->isEnabled()) {
			return null;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}
		try {
			return $this->identities->forActor($this->accountService->getActorFromUserId($user->getUID(), true), false);
		} catch (Throwable) {
			return null;
		}
	}
}
