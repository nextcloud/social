<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\AccountNotesRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\DomainBlocksRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\Moderation;
use Psr\Log\LoggerInterface;

/**
 * Acting on a report.
 *
 * The reports panel could describe a problem and do nothing about it: resolve
 * marked the complaint handled and left the account exactly as it was. These
 * are the two decisions a moderator actually needs, plus the ability to take
 * one post down.
 *
 * Silencing is reversible and touches nothing: the account stays reachable for
 * the people who chose to follow it, and leaves the public and global
 * timelines. Suspending removes what the account has posted here and refuses
 * what it sends next, so it is the one that needs care — and it is why the
 * decision is recorded rather than only applied. Lifting a suspension stops
 * the refusal; it does not bring back what was deleted, and the confirmation
 * in the admin panel says so.
 */
class ModerationService {
	public function __construct(
		private ModerationRequest $moderationRequest,
		private StreamRequest $streamRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private FollowsRequest $followsRequest,
		private ActorRelationRequest $actorRelationRequest,
		private StreamDestRequest $streamDestRequest,
		private RequestQueueRequest $requestQueueRequest,
		private StreamService $streamService,
		private LoggerInterface $logger,
		private DomainBlocksRequest $domainBlocksRequest,
		private AccountNotesRequest $accountNotesRequest,
		private MuteExpiryRequest $muteExpiryRequest,
	) {
	}

	/**
	 * @return Moderation[]
	 */
	public function decisions(): array {
		return $this->moderationRequest->getAll();
	}

	public function levelOf(string $actorId): string {
		return $this->moderationRequest->levelOf($actorId);
	}

	/** @return string[] actor ids kept out of the public timelines */
	public function silenced(): array {
		return $this->moderationRequest->getActorIdsAt(Moderation::SILENCE);
	}

	public function isSuspended(string $actorId): bool {
		return $this->moderationRequest->levelOf($actorId) === Moderation::SUSPEND;
	}

	/**
	 * Refuses an account that is suspended here anything it would send out.
	 *
	 * A suspension used to be enforced on the way in only, so a local account
	 * suspended by its own admin went on posting, boosting and following from
	 * every client — and this instance went on federating it. Asked at the
	 * service that performs the action, so no entry point can miss it.
	 *
	 * @throws InvalidActionException
	 */
	public function assertNotSuspended(string $actorId): void {
		if ($this->isSuspended($actorId)) {
			throw new InvalidActionException('this account is suspended');
		}
	}

	/**
	 * Records a decision and applies it.
	 *
	 * @param string $actorId the account
	 * @param string $level one of Moderation::LEVELS
	 * @param string $comment why, for whoever reads the list later
	 */
	public function decide(string $actorId, string $level, string $comment = ''): Moderation {
		if (!in_array($level, Moderation::LEVELS, true)) {
			throw new \InvalidArgumentException('unknown moderation level: ' . $level);
		}

		$moderation = new Moderation($actorId, $level, $comment, time());
		$this->moderationRequest->save($moderation);

		if ($level === Moderation::SUSPEND) {
			$this->purgeActor($actorId);
		}

		// not 'level': the server's logger reads that key in a context as a log
		// level and throws on anything that is not one
		$this->logger->info('moderation decision applied', ['actor' => $actorId, 'decision' => $level]);

		return $moderation;
	}

	/**
	 * Lifts a decision. What a suspension deleted stays deleted — this only
	 * stops the instance refusing what the account sends from now on.
	 */
	public function lift(string $actorId): void {
		$this->moderationRequest->delete($actorId);
		$this->logger->info('moderation decision lifted', ['actor' => $actorId]);
	}

	/**
	 * Takes one post down, whoever wrote it.
	 *
	 * A post of this instance's own goes the way its author's own delete goes:
	 * a federated Delete to the followers, boosters and repliers holding a
	 * copy. Dropping only the row left a post taken down here live on every
	 * other instance that ever saw it. A post from elsewhere is dropped here
	 * and nowhere else — this instance is not its origin, and a Delete it
	 * signed for somebody else's post is not one any other server would act on.
	 */
	public function removeStream(string $streamId): void {
		try {
			$stream = $this->streamRequest->getStreamById($streamId);
		} catch (StreamNotFoundException $e) {
			return;
		}

		if ($stream->isLocal()) {
			try {
				$this->streamService->deleteLocalItem($stream);
			} catch (\Exception $e) {
				// a takedown does not wait on the fediverse: the post goes, and
				// the Delete that could not be built is what is lost
				$this->logger->error('could not federate a moderator takedown', [
					'stream' => $streamId, 'exception' => $e,
				]);
				$this->streamRequest->deleteById($streamId);
			}
		} else {
			$this->streamRequest->deleteById($streamId);
		}

		$this->logger->info('post removed by a moderator', ['stream' => $streamId]);
	}

	/**
	 * Everything an account has here. Its cached actor goes too, so nothing of
	 * it is served from this instance afterwards.
	 *
	 * The relationships go with it. A suspension that left the follow rows in
	 * place kept the account in the delivery fan-out — every local post still
	 * went to it — and kept it in the timelines of the people who followed it,
	 * addressed through the dest rows.
	 *
	 * Public because a domain purge detaches accounts one at a time and has to
	 * detach exactly what a suspension detaches: two lists of tables that were
	 * meant to be the same one would drift, and whichever was forgotten would
	 * be a row of a blocked instance still reaching a timeline. Every step is
	 * caught on its own and none is a delete that depends on an earlier one,
	 * so calling it twice on the same account is a no-op rather than a
	 * failure.
	 */
	public function purgeActor(string $actorId): void {
		try {
			$this->streamRequest->deleteByAuthor($actorId);
		} catch (\Exception $e) {
			$this->logger->error('could not remove the posts of a suspended account', [
				'actor' => $actorId, 'exception' => $e,
			]);
		}

		foreach ([
			// both directions of the follow relationship
			'follows' => fn () => $this->followsRequest->deleteRelatedId($actorId),
			// the per-user blocks and mutes against it, which have nothing
			// left to hide
			'relations' => fn () => $this->actorRelationRequest->deleteRelatedId($actorId),
			// the instances it blocked, the notes it wrote and the notes others
			// wrote about it, and the expiry of any mute in either direction
			'domainBlocks' => fn () => $this->domainBlocksRequest->deleteRelatedId($actorId),
			'notes' => fn () => $this->accountNotesRequest->deleteRelatedId($actorId),
			'muteExpiry' => fn () => $this->muteExpiryRequest->deleteRelatedId($actorId),
			// what put its posts in a local timeline, and what addressed local
			// posts to it
			'dest' => fn () => $this->streamDestRequest->deleteRelatedToActor($actorId),
			// deliveries still queued towards it
			'queue' => fn () => $this->requestQueueRequest->deleteByAuthor($actorId),
		] as $what => $delete) {
			try {
				$delete();
			} catch (\Exception $e) {
				$this->logger->error('could not detach a suspended account', [
					'actor' => $actorId, 'what' => $what, 'exception' => $e,
				]);
			}
		}

		try {
			// a local account has no cached copy; deleting one that is not
			// there is not a failure
			$this->cacheActorsRequest->deleteCacheById($actorId);
		} catch (\Exception $e) {
			$this->logger->notice('could not drop the cached actor of a suspended account', [
				'actor' => $actorId, 'exception' => $e,
			]);
		}
	}
}
