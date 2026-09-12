<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\InvalidActionException;
use OCP\IAvatarManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The profile picture, as a Mastodon client sends it on `update_credentials`.
 *
 * Unlike the banner, this is not the app's own picture: it is the Nextcloud
 * account's avatar, the one the whole server shows, and the actor document
 * points at `core.avatar.getAvatar` for it. So it is written where it lives
 * and the actor cache is refreshed; `DocumentService::cacheLocalAvatarByUsername()`
 * notices the version core bumped and re-caches the icon.
 *
 * `avatar` used to be accepted and dropped. A client's profile editor sends the
 * whole form in one PATCH, so somebody changing their picture and their bio
 * together got a 200 and a new bio over the old picture.
 */
class AvatarService {
	/**
	 * What a Nextcloud avatar may be. Narrower than what an attachment may be:
	 * core renders these and nothing else.
	 */
	private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

	/** Core's own ceiling for an avatar upload, in bytes. */
	private const MAX_SIZE = 20 * 1024 * 1024;

	public function __construct(
		private IAvatarManager $avatarManager,
		private IUserManager $userManager,
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Stores an uploaded file as the account's avatar.
	 *
	 * @throws InvalidActionException the backend owns the avatar, or the bytes
	 *                                are not a picture this can store
	 */
	public function setFromTempFile(string $userId, string $tmpPath): void {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new InvalidActionException('unknown account');
		}

		// LDAP, SAML and friends serve the picture from elsewhere. Saying so is
		// the point: the profile looks unchanged either way, and only a refusal
		// tells the user why.
		if (!$user->canChangeAvatar()) {
			throw new InvalidActionException(
				'the avatar of this account is managed outside Nextcloud and cannot be changed here'
			);
		}

		if (!is_file($tmpPath)) {
			throw new InvalidActionException('no avatar found in the request');
		}

		$size = filesize($tmpPath);
		if ($size === false || $size === 0) {
			throw new InvalidActionException('the uploaded avatar is empty');
		}

		if ($size > self::MAX_SIZE) {
			throw new InvalidActionException('the uploaded avatar is too large');
		}

		// the bytes decide, not the name or the declared type: a client may
		// call anything an image, and core will be handed this to render
		$type = (string)@mime_content_type($tmpPath);
		if (!in_array($type, self::ALLOWED_TYPES, true)) {
			throw new InvalidActionException('an avatar has to be a JPEG, PNG, GIF or WebP image');
		}

		$data = file_get_contents($tmpPath);
		if ($data === false) {
			throw new InvalidActionException('the uploaded avatar could not be read');
		}

		try {
			$this->avatarManager->getAvatar($userId)->set($data);
		} catch (\Throwable $e) {
			$this->logger->warning('could not store an avatar', [
				'userId' => $userId, 'exception' => $e,
			]);

			throw new InvalidActionException('the uploaded avatar could not be stored');
		}

		// the actor's icon follows the account's, but only once something asks
		// the cache to catch up
		$this->accountService->cacheLocalActorByUsername(
			$this->accountService->getActorFromUserId($userId)->getPreferredUsername()
		);
	}
}
