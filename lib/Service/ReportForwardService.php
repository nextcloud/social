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
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Report;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use Psr\Log\LoggerInterface;

/**
 * Passing a local report on to the instance the reported account is on.
 *
 * `POST /api/v1/reports` takes `forward`, and a user who ticks it is asking
 * for the one thing this instance cannot do for them: act on an account it
 * does not host. Without this, ticking the box did nothing at all and the
 * report stopped at our own moderators — the remote instance, the only one
 * that can suspend the account, was never told.
 *
 * **Who signs it.** The instance, never the reporter. The `Flag` names this
 * server's own actor and is signed with its key, so the receiving moderators
 * see "cloud.example reported this" and not the account that filed it. That is
 * Mastodon's behaviour and the reason for it is the whole point: the person
 * is reporting an account on that very instance, whose admins may be hostile
 * to them, and a report that carried their handle would hand those admins a
 * name to retaliate against. The comment is passed on as written — the user is
 * told the report is being forwarded, and can keep it to our moderators by not
 * ticking the box.
 *
 * **Delivery.** Sent inline rather than through `social_request_queue`, and
 * this is the one thing here that is a compromise: a queued delivery is signed
 * by `HttpSignatureService::signDelivery()`, which resolves the signing key
 * from `oc_social_actor` by the queue row's author — and the instance actor is
 * deliberately not a row there (see `InstanceActorService`). So the one
 * activity that must be signed as the server is the one the queue cannot sign.
 * One report is one POST with a short timeout, it never blocks storing the
 * report, and a failure costs the forward and nothing else.
 */
class ReportForwardService {
	/**
	 * The report is already stored and the user is waiting on the response, so
	 * an unreachable instance has to give up quickly. Matches the timeout
	 * `ActivityService` gives its own inline delivery.
	 */
	private const TIMEOUT = 3;

	/**
	 * The headers Mastodon requires a POST signature to cover. `digest` is
	 * what makes the signature cover the body: without it a signature on a
	 * body-carrying request proves nothing about the body, and Mastodon
	 * refuses the delivery outright.
	 */
	private const DELIVERY_HEADERS = ['(request-target)', 'content-length', 'date', 'host', 'digest'];

	public function __construct(
		private InstanceActorService $instanceActorService,
		private HttpSignatureService $httpSignatureService,
		private CurlService $curlService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the report can be forwarded at all: it has to be one of ours,
	 * about an account somewhere else, that publishes an inbox.
	 *
	 * A report that arrived as a `Flag` is not ours to pass on — forwarding it
	 * again would put this instance's name on somebody else's complaint, and
	 * two instances doing that to each other is a loop.
	 */
	public function canForward(Report $report, Person $target): bool {
		return $report->isLocal()
			&& !$target->isLocal()
			&& $this->inboxOf($target) !== '';
	}

	/**
	 * Delivers the anonymised `Flag`.
	 *
	 * @return bool whether the remote instance accepted it — which is what the
	 *              report's `forwarded` reports, so it must not be optimistic
	 */
	public function forward(Report $report, Person $target): bool {
		if (!$this->canForward($report, $target)) {
			return false;
		}

		$actor = $this->instanceActorService->getSigningActor();
		if ($actor === null) {
			// this instance cannot sign as itself, and signing as the reporter
			// is the one thing forwarding must never do
			$this->logger->warning('cannot forward a report: no instance actor to sign it', [
				'report' => $report->getId(),
			]);

			return false;
		}

		$inbox = $this->inboxOf($target);
		$body = json_encode($this->flag($report, $target, $actor), JSON_UNESCAPED_SLASHES);

		try {
			$this->curlService->retrieveJson('post', $inbox, [
				'headers' => $this->sign($actor, $inbox, (string)$body),
				'body' => (string)$body,
				'timeout' => self::TIMEOUT,
			]);
		} catch (RequestResultNotJsonException $e) {
			// an inbox answers 202 with an empty body, which is a success and
			// not a document — the delivery queue reads it the same way
		} catch (\Exception $e) {
			$this->logger->warning('could not forward a report', [
				'report' => $report->getId(), 'inbox' => $inbox, 'exception' => $e,
			]);

			return false;
		}

		$this->logger->info('report forwarded', ['report' => $report->getId(), 'inbox' => $inbox]);

		return true;
	}

	/**
	 * The activity: this instance's actor flagging the reported account and
	 * the reported statuses, with the reporter's comment and nothing that
	 * identifies them.
	 *
	 * Addressed `to` the reported account, the way Mastodon addresses one, so
	 * an instance that routes by audience delivers it to the right moderators.
	 */
	private function flag(Report $report, Person $target, InstanceActor $actor): Flag {
		$flag = new Flag();
		$flag->setId($actor->getId() . '#reports/' . $report->getId())
			->setActorId($actor->getId())
			->setTo($target->getId());
		$flag->setObjectIds(array_merge([$target->getId()], $report->getStatusIds()))
			->setContent($report->getComment());

		return $flag;
	}

	/**
	 * The HTTP signature, built here rather than by `HttpSignatureService`:
	 * every signing path there takes either a `RequestQueue` row or a local
	 * actor, and this request has neither. Only the assembly is local — the
	 * digest is the same one every other delivery uses, and it covers the very
	 * bytes that are sent.
	 *
	 * @return array<string, string> the headers to send
	 *
	 * @throws SignatureException an unusable key must fail loudly rather than
	 *                            send an empty signature that the peer would
	 *                            reject for the wrong reason
	 * @throws SocialAppConfigException
	 */
	private function sign(InstanceActor $actor, string $inbox, string $body): array {
		$path = new InstancePath($inbox);
		$values = [
			'(request-target)' => 'post ' . $path->getPath(),
			'content-length' => (string)strlen($body),
			'date' => gmdate(HttpSignatureService::DATE_HEADER),
			'host' => $path->getAddress(),
			'digest' => $this->httpSignatureService->digest($body),
		];

		$signing = [];
		$headers = [];
		foreach (self::DELIVERY_HEADERS as $element) {
			$signing[] = $element . ': ' . $values[$element];
			if ($element !== '(request-target)') {
				$headers[$element] = $values[$element];
			}
		}

		if (!@openssl_sign(implode("\n", $signing), $signed, $actor->getPrivateKey(), OPENSSL_ALGO_SHA256)) {
			throw new SignatureException(
				'cannot sign the forwarded report as ' . $actor->getId() . ': ' . openssl_error_string()
			);
		}

		$headers['Signature'] = implode(',', [
			'keyId="' . $actor->getKeyId() . '"',
			'algorithm="rsa-sha256"',
			'headers="' . implode(' ', self::DELIVERY_HEADERS) . '"',
			'signature="' . base64_encode($signed) . '"',
		]);

		return $headers;
	}

	/**
	 * A personal inbox first: a `Flag` is about one account, and an instance
	 * that publishes both routes it to that account's moderators either way.
	 */
	private function inboxOf(Person $target): string {
		$inbox = $target->getInbox();

		return ($inbox !== '') ? $inbox : $target->getSharedInbox();
	}
}
