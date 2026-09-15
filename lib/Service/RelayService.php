<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\RelayRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\Relay;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Relays: the fediverse's answer to an empty federated timeline.
 *
 * A small instance only ever sees what the people on it follow, so its
 * federated timeline is empty on the first day and thin for months — there is
 * nobody here yet to have found anybody out there, and nobody out there has
 * heard of this server either. A relay breaks that circle: an actor that
 * rebroadcasts the public posts of every instance subscribed to it. Subscribe
 * and the federated timeline fills with posts from servers nobody here
 * follows; deliver to it and this instance's own public posts reach people who
 * have never heard of it.
 *
 * **The subscription belongs to the instance.** The `Follow` is signed by the
 * instance actor, not by a person: what comes back is for everybody's
 * federated timeline, and signing as a user would put one account's name on
 * every server's relay list. That also means it cannot go through
 * `social_request_queue`, which resolves its signing key from `social_actor` —
 * see `HttpSignatureService::signAsInstance()`.
 *
 * **What is sent.** `Follow{object: as:Public}`, which is what Mastodon sends
 * and what the relay software (`activityrelay`, `pub-relay`, Mastodon's own)
 * reads as "subscribe me". The document is built by hand rather than from the
 * `Follow` model because that model is shaped for a person following a person
 * — an inbox, a followers collection, a request token — and this is neither.
 *
 * **What comes back is not a boost.** A relay announces a post it does not
 * own, and storing that the way a boost is stored would put "relay.example
 * boosted this" in front of every post and hang the post off an actor nobody
 * follows. Mastodon treats a relayed `Announce` as a *pointer*: fetch the post
 * from the server that wrote it and store that. So does this — through
 * `SearchService::resolveStatus()`, which is the same fetch a reader pasting a
 * link gets, with the same guard that the document has to claim the address it
 * came from.
 *
 * **Only what a relay is allowed to say.** An `Announce` is taken from a relay
 * whose subscription this instance asked for and the relay accepted, and from
 * nobody else. Without that, any server could push posts into the federated
 * timeline by calling itself a relay.
 */
class RelayService {
	/**
	 * A relay is either there or it is not; the administrator is waiting on
	 * the answer. The same timeout the report forwarder gives its own inline
	 * delivery.
	 */
	private const TIMEOUT = 5;

	public function __construct(
		private RelayRequest $relayRequest,
		private HttpSignatureService $httpSignatureService,
		private CurlService $curlService,
		private CacheActorService $cacheActorService,
		private SearchService $searchService,
		private InstanceActorService $instanceActorService,
		private LoggerInterface $logger,
	) {
	}

	/** @return Relay[] newest first */
	public function all(): array {
		return $this->relayRequest->getAll();
	}

	/**
	 * Subscribes to a relay.
	 *
	 * `$address` is the relay's actor — `https://relay.example/actor` is the
	 * usual shape, and the one every relay's own front page prints. It is
	 * fetched first, because what is wanted from it is its inbox and there is
	 * no way to guess one: a relay that does not resolve is refused here
	 * rather than recorded as a subscription that can never be answered.
	 *
	 * The row is written **before** the Follow goes out, so a relay that
	 * answers instantly finds a row to mark accepted.
	 *
	 * @throws InvalidResourceException the address is not a relay this instance can reach
	 */
	public function subscribe(string $address): Relay {
		$address = trim($address);
		if (!str_starts_with($address, 'https://') && !str_starts_with($address, 'http://')) {
			throw new InvalidResourceException('a relay is named by its address, like https://relay.example/actor');
		}

		try {
			$actor = $this->cacheActorService->getFromId($address, true);
		} catch (Throwable $e) {
			throw new InvalidResourceException('that address did not answer with an actor: ' . $e->getMessage());
		}

		$inbox = $actor->getInbox();
		if ($inbox === '') {
			throw new InvalidResourceException('that actor publishes no inbox, so there is nowhere to subscribe');
		}

		$relay = new Relay();
		$relay->setActorId($actor->getId())
			->setInbox($inbox)
			->setStatus(Relay::STATUS_PENDING)
			->setFollowId($this->followId($actor->getId()));
		$this->relayRequest->save($relay);

		$this->send($relay, $this->followActivity($relay));

		return $this->relayRequest->getByActorId($actor->getId()) ?? $relay;
	}

	/**
	 * Unsubscribes, and forgets the relay.
	 *
	 * The `Undo` is sent first and its failure does not stop the row going:
	 * an administrator who has pressed this wants to stop taking posts from
	 * that relay, and whether the relay heard is the relay's problem. Nothing
	 * further is taken from it either way, because taking one in needs a row.
	 */
	public function unsubscribe(int $id): bool {
		$relay = $this->relayRequest->getById($id);
		if ($relay === null) {
			return false;
		}

		try {
			$this->send($relay, $this->undoActivity($relay), false);
		} catch (Throwable $e) {
			$this->logger->info('a relay was not told it had been dropped', [
				'relay' => $relay->getActorId(), 'exception' => $e,
			]);
		}

		return $this->relayRequest->delete($id);
	}

	/**
	 * Takes in an activity a relay sent, and says whether it was one.
	 *
	 * Called before the ordinary interfaces see it. Everything here is scoped
	 * to an actor this instance has a subscription row for: an `Announce` from
	 * a server calling itself a relay that nobody subscribed to is not a relay
	 * activity and goes on to be handled — or refused — as what it is.
	 *
	 * @return bool whether this was a relay's activity and has been dealt with
	 */
	public function handleIncoming(ACore $activity): bool {
		$relay = $this->relayRequest->getByActorId($activity->getActorId());
		if ($relay === null) {
			return false;
		}

		switch ($activity->getType()) {
			case 'Accept':
				// the Accept has to name the Follow this instance sent, or any
				// Accept from a server that happens to be a relay would enable
				// a subscription nobody asked for
				if ($this->acceptsOurFollow($activity, $relay)) {
					$this->relayRequest->setStatus($relay->getActorId(), Relay::STATUS_ACCEPTED);
					$this->logger->info('a relay accepted the subscription', ['relay' => $relay->getActorId()]);
				}

				return true;
			case 'Reject':
				$this->relayRequest->setStatus(
					$relay->getActorId(), Relay::STATUS_REJECTED, 'the relay refused the subscription'
				);

				return true;
			case Announce::TYPE:
				$this->announced($relay, $activity->getObjectId());

				return true;
			case 'Undo':
				// a relay dropping us: the row stays so an administrator can
				// see what happened and subscribe again
				$this->relayRequest->setStatus(
					$relay->getActorId(), Relay::STATUS_REJECTED, 'the relay ended the subscription'
				);

				return true;
		}

		return false;
	}

	/**
	 * Where a local public post has to go besides its followers' inboxes.
	 *
	 * @return string[] the inboxes of the relays that accepted
	 */
	public function inboxes(): array {
		return $this->relayRequest->acceptedInboxes();
	}

	/**
	 * A post a relay pointed at.
	 *
	 * Fetched from the server that wrote it rather than believed from the
	 * relay: the relay is a directory, not a source, and the post it names has
	 * to claim its own address before it is stored. A post this instance
	 * already holds costs nothing — `resolveStatus()` answers from the
	 * database first.
	 */
	private function announced(Relay $relay, string $objectId): void {
		if ($objectId === '') {
			return;
		}

		if (!$relay->isAccepted()) {
			// a relay that has not accepted has not been subscribed to as far
			// as this instance is concerned, and must not be able to put posts
			// in front of anybody by announcing them
			$this->logger->info('ignoring an Announce from a relay that has not accepted', [
				'relay' => $relay->getActorId(), 'object' => $objectId,
			]);

			return;
		}

		if ($this->searchService->resolveStatus($objectId) === null) {
			$this->logger->debug('could not take in a post a relay announced', [
				'relay' => $relay->getActorId(), 'object' => $objectId,
			]);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function followActivity(Relay $relay): array {
		return [
			'@context' => ACore::CONTEXT_ACTIVITYSTREAMS,
			'id' => $relay->getFollowId(),
			'type' => 'Follow',
			'actor' => $this->instanceActorId(),
			// what Mastodon sends and what every relay reads as "subscribe
			// me": the relay is asked for everything public, not for the
			// relay actor's own posts, of which it has none
			'object' => ACore::CONTEXT_PUBLIC,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function undoActivity(Relay $relay): array {
		return [
			'@context' => ACore::CONTEXT_ACTIVITYSTREAMS,
			'id' => $relay->getFollowId() . '/undo',
			'type' => 'Undo',
			'actor' => $this->instanceActorId(),
			'object' => $this->followActivity($relay),
		];
	}

	/**
	 * Delivers one activity to the relay's inbox, signed as the instance.
	 *
	 * @param bool $record whether a failure is worth showing in the panel
	 */
	private function send(Relay $relay, array $activity, bool $record = true): void {
		$body = (string)json_encode($activity, JSON_UNESCAPED_SLASHES);
		$inbox = $relay->getInbox();

		try {
			$this->curlService->retrieveJson('post', $inbox, [
				'headers' => $this->httpSignatureService->signAsInstance($inbox, $body),
				'body' => $body,
				'timeout' => self::TIMEOUT,
			]);
		} catch (RequestResultNotJsonException $e) {
			// an inbox answers 202 with an empty body, which is a success and
			// not a document — every other delivery here reads it the same way
		} catch (Throwable $e) {
			$this->logger->warning('could not reach a relay', [
				'relay' => $relay->getActorId(), 'inbox' => $inbox, 'exception' => $e,
			]);

			if ($record) {
				$this->relayRequest->setStatus(
					$relay->getActorId(), Relay::STATUS_REJECTED, $e->getMessage()
				);
			}
		}
	}

	/**
	 * Whether an `Accept` is an answer to the Follow this instance sent.
	 *
	 * A relay may answer with the Follow embedded or with its id alone, and
	 * some answer with neither — an `Accept` with no object at all. The first
	 * two are matched on the id; the third is taken, because a relay that
	 * answers an unmatched Accept is answering the one thing this instance
	 * ever sent it.
	 */
	private function acceptsOurFollow(ACore $activity, Relay $relay): bool {
		$objectId = $activity->getObjectId();
		if ($objectId === '' || $objectId === $relay->getFollowId()) {
			return true;
		}

		$this->logger->info('a relay accepted something this instance did not send', [
			'relay' => $relay->getActorId(), 'object' => $objectId, 'expected' => $relay->getFollowId(),
		]);

		return false;
	}

	/**
	 * The id of the Follow, derived from the instance actor and the relay, so
	 * that subscribing again after a rejection sends the same id rather than
	 * accumulating ones no relay will ever answer.
	 */
	private function followId(string $actorId): string {
		return $this->instanceActorId() . '#relay/' . substr(md5($actorId), 0, 12);
	}

	private function instanceActorId(): string {
		return $this->instanceActorService->getId();
	}
}
