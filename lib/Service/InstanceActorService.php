<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\InstanceActor;
use OCA\Social\Security\PrivateKeyCipher;
use Psr\Log\LoggerInterface;

/**
 * The instance's own `Application` actor and its key pair.
 *
 * Outbound fetches used to be signed with the key of the oldest local account,
 * which made one person this instance's public face on every peer, tied every
 * signed fetch to whether that one account was blocked remotely, and left an
 * instance with no Social accounts yet unable to sign at all — so
 * authorized-fetch peers answered 401 to everything.
 *
 * The key pair lives in the app config rather than in `oc_social_actor`. A row
 * there is a local account: it is listed by the directory, resolved by
 * webfinger, offered to the client API, counted in the instance statistics and
 * handed a followers collection and an outbox. The instance actor is none of
 * those things, and every one of those places would have needed a clause
 * excluding it. Two config values and one route are the whole of it, and there
 * is no migration to get wrong.
 */
class InstanceActorService {
	/** The last path segment of the actor's id: `<social url>actor`, as Mastodon serves it. */
	public const PATH = 'actor';

	public const CONFIG_PUBLIC_KEY = 'instance_actor_public_key';
	public const CONFIG_PRIVATE_KEY = 'instance_actor_private_key';

	private ?InstanceActor $actor = null;

	public function __construct(
		private ConfigService $configService,
		private PrivateKeyCipher $keyCipher,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The actor id, which is also what a signature's `keyId` is built from and
	 * the URL a peer dereferences to check one.
	 *
	 * @throws SocialAppConfigException before the app knows its own URL
	 */
	public function getId(): string {
		return $this->root() . self::PATH;
	}

	/**
	 * The actor document, generating the key pair on first use.
	 *
	 * @throws SocialAppConfigException
	 * @throws SignatureException when no key pair can be produced
	 */
	public function getActor(): InstanceActor {
		if ($this->actor !== null) {
			return $this->actor;
		}

		[$publicKey, $privateKey] = $this->keyPair();

		$actor = new InstanceActor();
		$actor->setId($this->getId())
			->setUrl($this->getId())
			->setPreferredUsername($this->configService->getSocialAddress())
			->setName($this->configService->getSocialAddress())
			->setInbox($this->root() . 'inbox')
			->setSharedInbox($this->root() . 'inbox')
			->setPublicKey($publicKey)
			->setPrivateKey($privateKey)
			->setLocal(true);

		return $this->actor = $actor;
	}

	/**
	 * The actor to sign an outbound fetch as, or null when this instance cannot
	 * produce one — it does not know its own URL yet, or OpenSSL refused.
	 *
	 * Null means the fetch goes out unsigned, which is what it did before any
	 * of this existed: against a peer that does not demand a signature it still
	 * works, and a failure to sign must not become a failure to fetch.
	 */
	public function getSigningActor(): ?InstanceActor {
		try {
			return $this->getActor();
		} catch (SocialAppConfigException|SignatureException $e) {
			$this->logger->warning('no instance actor to sign with', ['exception' => $e]);

			return null;
		}
	}

	/**
	 * @return array{string, string} the public and private key, in that order
	 * @throws SignatureException
	 */
	private function keyPair(): array {
		$stored = $this->storedKeyPair();
		if ($stored !== null) {
			return $stored;
		}

		return $this->generateKeyPair();
	}

	/** @return ?array{string, string} */
	private function storedKeyPair(): ?array {
		$publicKey = (string)$this->configService->getAppValue(self::CONFIG_PUBLIC_KEY);
		$privateKey = $this->keyCipher->open(
			(string)$this->configService->getAppValue(self::CONFIG_PRIVATE_KEY)
		);

		if ($publicKey === '' || $privateKey === '') {
			return null;
		}

		return [$publicKey, $privateKey];
	}

	/**
	 * Generates and stores the pair.
	 *
	 * The generation is `SignatureService::generateKeys()` written out again
	 * rather than called: `SignatureService` owns `HttpSignatureService`, which
	 * is what needs this service, and a constructor cycle is not worth reusing
	 * a dozen lines for.
	 *
	 * The private key is sealed with the instance secret, the way an actor's is
	 * in `oc_social_actor` — a config dump alone must not be enough to sign as
	 * this server.
	 *
	 * @return array{string, string}
	 * @throws SignatureException
	 */
	private function generateKeyPair(): array {
		$res = openssl_pkey_new(
			['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]
		);
		if ($res === false || !openssl_pkey_export($res, $privateKey)) {
			throw new SignatureException(
				'cannot generate the instance actor key pair: ' . openssl_error_string()
			);
		}

		$details = openssl_pkey_get_details($res);
		if ($details === false || ($details['key'] ?? '') === '') {
			throw new SignatureException(
				'cannot export the instance actor public key: ' . openssl_error_string()
			);
		}

		$this->configService->setAppValue(self::CONFIG_PUBLIC_KEY, $details['key']);
		$this->configService->setAppValue(
			self::CONFIG_PRIVATE_KEY, $this->keyCipher->seal($privateKey)
		);

		// Two requests can reach this at the same moment on a fresh instance.
		// Whichever pair was written last is the one the actor document will
		// publish, so both sign with that one rather than with the one they
		// happen to hold.
		return $this->storedKeyPair() ?? [$details['key'], $privateKey];
	}

	/**
	 * @throws SocialAppConfigException
	 */
	private function root(): string {
		return rtrim($this->configService->getSocialUrl(), '/') . '/';
	}
}
