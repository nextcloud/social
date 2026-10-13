<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\InstanceKey;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Security\PrivateKeyCipher;

/**
 * The seals on every Bluesky key — the instance rotation and service keys
 * and each account's signing key: whether they still open with the
 * instance secret, and their re-sealing under a new secret while the old
 * one is still known. A key that does not open can sign nothing, and a
 * DID whose keys are lost can no longer be updated.
 */
class KeySealService {
	private const PAGE = 500;

	public function __construct(
		private AtprotoIdentityRequest $identityRequest,
		private PrivateKeyCipher $cipher,
	) {
	}

	/**
	 * How many keys do not open with the secret in use.
	 */
	public function countSealed(): int {
		$sealed = 0;
		foreach ($this->instanceKeys() as $key) {
			if (!$this->cipher->opens($key->sealedPrivateKey)) {
				$sealed++;
			}
		}
		foreach ($this->identities() as $identity) {
			if (!$this->cipher->opens($identity->sealedSigningKey)) {
				$sealed++;
			}
		}

		return $sealed;
	}

	/**
	 * Seals every key under the secret in use, opening the ones sealed
	 * with $oldSecret. A key that opens already is left as it is.
	 *
	 * @return array{resealed: int, failed: int} how many were sealed again, and how many neither secret opens
	 */
	public function reseal(#[\SensitiveParameter] string $oldSecret): array {
		$resealed = 0;
		$failed = 0;
		foreach ($this->instanceKeys() as $key) {
			if ($this->cipher->opens($key->sealedPrivateKey)) {
				continue;
			}
			$sealed = $this->cipher->reseal($key->sealedPrivateKey, $oldSecret);
			if ($sealed === '') {
				$failed++;
				continue;
			}
			$this->identityRequest->resealInstanceKey($key->id, $sealed);
			$resealed++;
		}
		foreach ($this->identities() as $identity) {
			if ($this->cipher->opens($identity->sealedSigningKey)) {
				continue;
			}
			$sealed = $this->cipher->reseal($identity->sealedSigningKey, $oldSecret);
			if ($sealed === '') {
				$failed++;
				continue;
			}
			$this->identityRequest->setSigningKey($identity->did, $sealed, $identity->signingPublic);
			$resealed++;
		}

		return ['resealed' => $resealed, 'failed' => $failed];
	}

	/**
	 * @return InstanceKey[] the rotation keys, retired ones included, and the service key
	 */
	private function instanceKeys(): array {
		return [...$this->identityRequest->getInstanceKeys(InstanceKey::KIND_ROTATION), ...$this->identityRequest->getInstanceKeys(InstanceKey::KIND_SERVICE)];
	}

	/**
	 * @return iterable<Identity> the identities that still sign: not tombstoned, not moved away
	 */
	private function identities(): iterable {
		$offset = 0;
		while (($page = $this->identityRequest->getAll(self::PAGE, $offset)) !== []) {
			foreach ($page as $identity) {
				if ($identity->state !== Identity::STATE_TOMBSTONED && $identity->state !== Identity::STATE_MOVED_AWAY) {
					yield $identity;
				}
			}
			$offset += self::PAGE;
		}
	}
}
