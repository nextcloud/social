<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\ChannelsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Channel;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Channels: what a video belongs to, everywhere but here.
 *
 * PeerTube has no video without a channel. Its builder resolves one by looking
 * for a **`Group`** in the video's `attributedTo` and throws *"Cannot find
 * associated video channel"* when there is none; then it fetches that `Group`
 * and looks for a **`Person`** in *its* `attributedTo`. A Social account is a
 * `Person` and nothing else, so until this existed every video this app
 * published was refused by every PeerTube that received it — silently, and in
 * their logs rather than ours.
 *
 * A channel is **an actor like any other**: a key pair, an inbox, an outbox,
 * followers, followable from anywhere, moderatable, suspendable. That is the
 * same design `TeamService` uses and the reason neither of them needed the
 * actor machinery written a second time. What is new here is only the type it
 * is served as and who it belongs to.
 *
 * **Nobody has to know what a channel is.** One is made for an account the
 * first time it posts a video, named after the account, and used unless the
 * person picks another. Making the concept mandatory before somebody can post
 * a video would be importing PeerTube's data model into a Nextcloud app as a
 * chore.
 *
 * **A channel is not a second identity.** It carries no posts of its own
 * beyond the videos attributed to it, it is not something to write from, and
 * the post stays the author's: the video is attributed to both, which is
 * exactly what PeerTube does — the channel is what it is *filed under*, the
 * account is who made it.
 */
class ChannelService {
	/**
	 * The `user_id` a channel actor is stored under.
	 *
	 * Reserved rather than clever: a Nextcloud user id may not contain a
	 * slash, so nothing a real account is stored under can collide with this,
	 * and a channel never resolves as somebody's own account on a path that
	 * looks one up by user.
	 */
	public const USER_PREFIX = 'channel/';

	/** What a derived channel handle has appended to it, as PeerTube does. */
	private const DEFAULT_SUFFIX = '_channel';

	/** A handle is a handle: the same ceiling `AccountService` holds one to. */
	private const MAX_HANDLE = 64;

	/** More than this and it is a directory, not a person's channels. */
	private const MAX_PER_ACCOUNT = 20;

	/** @var array<string, array<int, array{type: string, id: string}>> memoised for the request */
	private array $attributions = [];

	public function __construct(
		private ChannelsRequest $channelsRequest,
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
	}

	/** Whether an account is a channel rather than somebody's own. */
	public static function isChannelUserId(string $userId): bool {
		return str_starts_with($userId, self::USER_PREFIX);
	}

	/** @return Channel[] the channels of one account, oldest first */
	public function forOwner(Person $owner): array {
		$channels = $this->channelsRequest->getByOwner($owner->getId());
		foreach ($channels as $channel) {
			$channel->setHandle($this->handleOf($channel));
		}

		return $channels;
	}

	/**
	 * The channel a video goes to when nobody chose, making one if this
	 * account has none.
	 *
	 * Lazily, on the first video: an account that never posts one never grows
	 * an actor nobody asked for, and an account that does never has to learn
	 * the word.
	 */
	public function defaultFor(Person $owner): Channel {
		$channels = $this->channelsRequest->getByOwner($owner->getId());
		foreach ($channels as $channel) {
			if ($channel->isDefault()) {
				$channel->setHandle($this->handleOf($channel));

				return $channel;
			}
		}

		if ($channels !== []) {
			// channels exist but none is marked: the oldest is the default, so
			// that a row written by hand cannot leave an account without one
			$first = $channels[0];
			$first->setHandle($this->handleOf($first));

			return $first;
		}

		return $this->create(
			$owner,
			$this->deriveHandle($owner),
			$owner->getName() !== '' ? $owner->getName() : $owner->getPreferredUsername(),
			'',
			true
		);
	}

	/**
	 * Makes a channel, with an actor of its own.
	 *
	 * @throws InvalidResourceException the handle is taken, or is not one, or
	 *                                  this account already has as many
	 *                                  channels as anybody sensibly needs
	 */
	public function create(
		Person $owner,
		string $handle,
		string $name = '',
		string $description = '',
		bool $default = false,
	): Channel {
		$handle = ltrim(trim($handle), '@');
		if ($handle === '' || strlen($handle) > self::MAX_HANDLE) {
			throw new InvalidResourceException('a channel needs a handle of its own');
		}

		$existing = $this->channelsRequest->getByOwner($owner->getId());
		if (count($existing) >= self::MAX_PER_ACCOUNT) {
			throw new InvalidResourceException(
				'this account already has ' . self::MAX_PER_ACCOUNT . ' channels'
			);
		}

		try {
			// the actor is made first and the row second: a row pointing at an
			// actor that was never created would be a channel nothing can serve
			$this->accountService->createActor(
				self::USER_PREFIX . $handle, $handle, Group::TYPE
			);
		} catch (Throwable $e) {
			throw new InvalidResourceException($e->getMessage());
		}

		$actor = $this->accountService->getActor($handle);

		$channel = new Channel();
		$channel->setActorId($actor->getId())
			->setOwnerId($owner->getId())
			->setName($name !== '' ? $name : $handle)
			->setDescription($description)
			->setDefault($default || $existing === []);
		$this->channelsRequest->create($channel);

		// the actor was cached before its row existed, so what it says about
		// whose it is was written without an owner to name; built again now
		$this->accountService->cacheLocalActorByUsername($handle);

		$channel->setHandle($handle);

		return $channel;
	}

	/**
	 * One of this account's own channels, by the id a client names it with.
	 *
	 * @throws InvalidResourceException it is not one, or not theirs
	 */
	public function ownChannel(Person $owner, int $id): Channel {
		foreach ($this->forOwner($owner) as $channel) {
			if ($channel->getId() === $id) {
				return $channel;
			}
		}

		// somebody else's channel and one that does not exist are the same
		// answer: the pair of them apart would say which ids exist
		throw new InvalidResourceException('no such channel');
	}

	/** Renames one, or changes what it says it is about. */
	public function update(Person $owner, int $id, string $name, string $description): Channel {
		$channel = $this->ownChannel($owner, $id);
		$channel->setName($name)->setDescription($description);
		$this->channelsRequest->update($channel);

		try {
			// the actor row directly, not `AccountService::setDisplayName()`:
			// that one renames a *Nextcloud user*, and a channel has none
			$actor = $this->accountService->getFromId($channel->getActorId());
			$actor->setName($name);
			$actor->setSummary($description);
			$this->actorsRequest->update($actor);
			$this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
		} catch (Throwable $e) {
			// the row is what the picker reads; the actor document catching up
			// is worth a line in the log and not a failed request
			$this->logger->notice('a channel was renamed and its actor was not', [
				'channel' => $channel->getActorId(), 'exception' => $e,
			]);
		}

		return $channel;
	}

	/**
	 * The `attributedTo` a video carries: the channel it is filed under, then
	 * the account that made it.
	 *
	 * The order is PeerTube's own and the shape its `findOwner` reads — it
	 * filters `attributedTo` by `type`, so entries that are bare id strings
	 * would make it fetch each one to learn what it is.
	 *
	 * @return array<int, array{type: string, id: string}>
	 */
	public static function attribution(Channel $channel, Person $author): array {
		return [
			['type' => Group::TYPE, 'id' => $channel->getActorId()],
			['type' => Person::TYPE, 'id' => $author->getId()],
		];
	}

	/**
	 * The `attributedTo` a video by this account carries, or `[]` when the
	 * account has no channel.
	 *
	 * Read only, and memoised for the request: this is called from a
	 * serialisation, which happens once per instance a post is delivered to,
	 * and a fan-out to forty servers must not be forty queries. Nothing is
	 * created here — a channel is made when the post is written, because
	 * making an actor inside a serialisation is a write on a read path.
	 *
	 * @return array<int, array{type: string, id: string}>
	 */
	public function attributionOf(string $authorId): array {
		if ($authorId === '') {
			return [];
		}

		if (array_key_exists($authorId, $this->attributions)) {
			return $this->attributions[$authorId];
		}

		$this->attributions[$authorId] = [];
		foreach ($this->channelsRequest->getByOwner($authorId) as $channel) {
			if ($channel->isDefault() || $this->attributions[$authorId] === []) {
				$this->attributions[$authorId] = [
					['type' => Group::TYPE, 'id' => $channel->getActorId()],
					['type' => Person::TYPE, 'id' => $authorId],
				];
			}
		}

		return $this->attributions[$authorId];
	}

	/** Who owns a channel, or '' when the id is not one of ours. */
	public function ownerOf(string $actorId): string {
		return $this->channelsRequest->ownerOf($actorId);
	}

	/** The handle half of a channel's address, which is its actor's username. */
	private function handleOf(Channel $channel): string {
		try {
			return $this->accountService->getFromId($channel->getActorId())->getPreferredUsername();
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * What to call an account's first channel.
	 *
	 * `alice_channel`, which is PeerTube's own derivation, with a number after
	 * it where that is taken — an account called `news` on an instance that
	 * already has a `news_channel` still gets one.
	 */
	private function deriveHandle(Person $owner): string {
		$base = substr($owner->getPreferredUsername(), 0, self::MAX_HANDLE - strlen(self::DEFAULT_SUFFIX) - 3);
		$candidate = $base . self::DEFAULT_SUFFIX;

		for ($suffix = 0; $suffix < 100; $suffix++) {
			$handle = ($suffix === 0) ? $candidate : $candidate . $suffix;
			try {
				$this->accountService->getActor($handle);
			} catch (Throwable $e) {
				return $handle;
			}
		}

		return $candidate . substr(md5($owner->getId()), 0, 6);
	}
}
