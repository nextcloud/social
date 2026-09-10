<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Tools\Model\NCRequest;
use Psr\Log\LoggerInterface;

/**
 * Signing outbound HTTP requests (RFC 9421's predecessor, the draft-cavage
 * scheme every ActivityPub implementation speaks).
 *
 * Verification lives in SignatureService; generation lives here because a GET
 * has to be signed from inside CurlService, and CurlService is what
 * SignatureService uses to fetch keys — a service that did both would be a
 * dependency cycle.
 */
class HttpSignatureService {
	public const DATE_HEADER = 'D, d M Y H:i:s T';

	/**
	 * The headers a delivery POST signs. `digest` and `content-length` bind the
	 * body; `host` and `date` stop the request being replayed elsewhere or
	 * later.
	 */
	private const DELIVERY_HEADERS = ['(request-target)', 'content-length', 'date', 'host', 'digest'];

	/**
	 * The headers a fetch GET signs.
	 *
	 * A GET has no body, so there is no digest to sign and no content-length
	 * worth signing — and a peer that receives either on a GET is entitled to
	 * find it strange. This is the set Mastodon, GoToSocial and Pleroma all
	 * produce and all verify.
	 */
	private const FETCH_HEADERS = ['(request-target)', 'host', 'date'];

	private ?Person $fetchActor = null;
	private bool $fetchActorResolved = false;

	public function __construct(
		private ActorsRequest $actorsRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The delivery signature: signs a POST to a peer's inbox as the local actor
	 * the activity belongs to.
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SignatureException
	 * @throws SocialAppConfigException
	 */
	public function signDelivery(NCRequest $request, RequestQueue $queue): void {
		$path = $queue->getInstance();
		$localActor = $this->actorsRequest->getFromId($queue->getAuthor());

		$this->sign(
			$request,
			$localActor,
			self::DELIVERY_HEADERS,
			[
				'(request-target)' => 'post ' . $path->getPath(),
				'date' => gmdate(self::DATE_HEADER),
				'host' => $path->getAddress(),
				'digest' => $this->digest($request->getDataBody()),
				'content-length' => (string)strlen($request->getDataBody()),
			]
		);
	}

	/**
	 * Signs an outbound ActivityPub GET, so that a peer running Mastodon's
	 * `AUTHORIZED_FETCH` or GoToSocial's secure mode answers it.
	 *
	 * Unsigned, those peers answer 401 to every actor, object, collection and
	 * outbox fetch: resolving an account fails ("user not found" when
	 * following), a thread stops at the first remote reply, poll refresh and
	 * timeline sync return nothing. Roughly a third of the fediverse by
	 * instance count runs in that mode.
	 *
	 * **Whose key.** Not the user who happens to have triggered the fetch: that
	 * tells the remote instance which of our accounts is reading which of their
	 * posts, on every thread expansion, which is a correlation nobody asked for.
	 * One fixed local actor signs every fetch instead — deterministically the
	 * oldest local account, which acts as this instance's fetching identity.
	 * It is already dereferenceable at its own actor URL and already publishes
	 * its key, so no new endpoint is needed; a dedicated instance actor of the
	 * `Application` type would be tidier and is what Mastodon uses, but it needs
	 * a route and an actor row of its own — this method is the only place that
	 * would have to change.
	 *
	 * @return bool whether a signature was added; false means the request goes
	 *              out exactly as it did before, which is all this instance can
	 *              do before any local account exists
	 */
	public function signFetch(NCRequest $request): bool {
		$actor = $this->fetchActor();
		if ($actor === null) {
			return false;
		}

		try {
			$this->sign(
				$request,
				$actor,
				self::FETCH_HEADERS,
				[
					'(request-target)' => 'get ' . $request->getParsedUrl() . $request->getQueryString(),
					'host' => $request->getHost(),
					'date' => gmdate(self::DATE_HEADER),
				]
			);
		} catch (SignatureException $e) {
			// an unsignable key must not stop the fetch: unsigned is what this
			// request was until now, and against most peers it still works
			$this->logger->warning('cannot sign an outgoing fetch', [
				'actor' => $actor->getId(), 'exception' => $e,
			]);

			return false;
		}

		return true;
	}

	/**
	 * The local actor whose key signs outbound fetches, or null when this
	 * instance has no local account yet.
	 */
	private function fetchActor(): ?Person {
		if ($this->fetchActorResolved) {
			return $this->fetchActor;
		}
		$this->fetchActorResolved = true;

		try {
			$actors = $this->actorsRequest->getAll();
		} catch (Exception $e) {
			$this->logger->warning('cannot read the local actors', ['exception' => $e]);

			return null;
		}

		$actors = array_values(
			array_filter($actors, static fn (Person $actor): bool => $actor->getPrivateKey() !== '')
		);
		if ($actors === []) {
			return null;
		}

		// deterministic, so every fetch from this instance carries the same
		// keyId and a peer can cache it
		usort($actors, static fn (Person $a, Person $b): int => $a->getNid() <=> $b->getNid());
		$this->fetchActor = $actors[0];

		return $this->fetchActor;
	}

	/**
	 * @throws SignatureException
	 */
	private function sign(NCRequest $request, Person $actor, array $elements, array $values): void {
		$signing = [];
		foreach ($elements as $element) {
			$signing[] = $element . ': ' . $values[$element];
			if ($element !== '(request-target)') {
				$request->addHeader($element, (string)$values[$element]);
			}
		}

		// the warning a bad key raises is handled right here, as an exception
		if (!@openssl_sign(implode("\n", $signing), $signed, $actor->getPrivateKey(), OPENSSL_ALGO_SHA256)) {
			// an empty or undecryptable private key must fail loudly, not send
			// base64('') as the signature
			throw new SignatureException(
				'cannot sign request for ' . $actor->getId() . ': ' . openssl_error_string()
			);
		}

		$request->addHeader('Signature', implode(',', [
			'keyId="' . $actor->getId() . '#main-key"',
			'algorithm="rsa-sha256"',
			'headers="' . implode(' ', $elements) . '"',
			'signature="' . base64_encode($signed) . '"',
		]));
	}

	public function digest(string $data): string {
		return 'SHA-256=' . base64_encode(hash('sha256', $data, true));
	}
}
