<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

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

	public function __construct(
		private ActorsRequest $actorsRequest,
		private InstanceActorService $instanceActorService,
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
	 * **Whose key.** The instance's own `Application` actor, never a person.
	 * Not whoever triggered the fetch — that tells the remote instance which of
	 * our accounts is reading which of their posts, on every thread expansion.
	 * And not a fixed local account either: the owner of the signing key is
	 * dereferenced by every peer that checks the signature, so that one person
	 * appears in every peer's logs as this instance's reader, and a block or
	 * suspension of their account anywhere stops every signed fetch from here.
	 * An instance whose users have not created Social accounts yet has no such
	 * account to borrow at all, and could not sign anything.
	 *
	 * @return bool whether a signature was added; false means the request goes
	 *              out exactly as it did before, which is what a peer that does
	 *              not demand one still answers
	 */
	public function signFetch(NCRequest $request): bool {
		$actor = $this->instanceActorService->getSigningActor();
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
