<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Who is asking, on a GET.
 *
 * Signature verification ran on inbox POSTs only, so this instance could not
 * tell one remote reader from another. It failed closed — every ActivityPub
 * GET served what an anonymous reader gets — which is safe and is also why a
 * follower on another server saw a profile with nothing on it, and why a
 * followers-only post never reached the people who follow it from elsewhere.
 *
 * Two things live here, and they are separate on purpose:
 *
 * - **Authorized fetch** is reading the signature when there is one, so that
 *   what this instance serves can depend on who asked. An unsigned request is
 *   the ordinary case — every crawler, every link preview, every server not
 *   signing its fetches — and gets exactly what it got before.
 * - **Secure mode** is refusing an unsigned GET outright. It is off by
 *   default, because turning it on makes this instance invisible to every
 *   peer that does not sign, and that is a decision about who an instance
 *   federates with rather than a default.
 */
class AuthorizedFetchService {
	public function __construct(
		private SignatureService $signatureService,
		private CacheActorService $cacheActorService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/** Whether this instance refuses a GET that is not signed. */
	public function isSecureMode(): bool {
		return $this->configService->getAppValue(ConfigService::SOCIAL_SECURE_MODE) === '1';
	}

	/**
	 * The remote account behind a signed GET, or null for an unsigned one.
	 *
	 * A signature that is present and does not verify returns null too — and
	 * says why in the log. The alternative is a 401 on a route whose job is to
	 * serve a public object: a peer whose clock has drifted, or whose key this
	 * instance cannot fetch right now, would stop seeing anything at all
	 * rather than seeing what an anonymous reader sees.
	 *
	 * The actor is fetched if it is not already cached, because the reader of
	 * a followers-only post is, by definition, somebody this instance has a
	 * follow row for — and a follow row is not an actor document.
	 */
	public function reader(IRequest $request): ?Person {
		$signer = '';

		try {
			$origin = $this->signatureService->checkGetRequest($request, $signer);
		} catch (\Exception $e) {
			$this->logger->notice('a signed fetch could not be verified', [
				'exception' => $e, 'signer' => $signer,
			]);

			return null;
		}

		if ($origin === '' || $signer === '') {
			return null;
		}

		try {
			// the access list applies to a reader as it applies to a sender:
			// an instance this one does not federate with does not become a
			// reader by signing a GET
			$this->fediverseService->authorized($origin);

			$actor = $this->cacheActorService->getFromId($signer);
		} catch (UnauthorizedFediverseException $e) {
			$this->logger->info('a signed fetch came from an instance this server does not federate with', [
				'origin' => $origin,
			]);

			return null;
		} catch (\Exception $e) {
			$this->logger->notice('the account behind a signed fetch could not be resolved', [
				'exception' => $e, 'signer' => $signer,
			]);

			return null;
		}

		if ($actor->isLocal()) {
			// a local actor's key signing an inbound fetch is this instance
			// talking to itself, or somebody replaying one of our own requests
			return null;
		}

		return $actor;
	}

	/**
	 * Refuses an unsigned GET when this instance is in secure mode.
	 *
	 * @throws SignatureException nothing signed the request
	 * @throws InvalidOriginException the signature is there and does not verify
	 */
	public function assertReadable(IRequest $request, ?Person $reader): void {
		if (!$this->isSecureMode() || $reader !== null) {
			return;
		}

		throw new SignatureException(
			'this server serves ActivityPub objects to signed requests only'
		);
	}
}
