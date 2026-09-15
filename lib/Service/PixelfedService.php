<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\Report;

/**
 * What Pixelfed's own app asks for, in the shapes it reads.
 *
 * Pixelfed speaks Mastodon's client API for almost everything and its own
 * `v1.1`/`v1.2` surface for the rest. The rest is mostly the same data this
 * app already serves under Mastodon's paths, arranged the way the app's
 * screens expect: a story carousel grouped by account, a collection with a
 * `thumb` and a `post_count`, a report with a `report_type`. Nothing here is a
 * second implementation of anything — every method reads through the service
 * that owns the data and rearranges the answer.
 *
 * Two answers are honest rather than convenient. `pushState()` says push is
 * off and there is no token, because there is no Web Push here; `nagState()`
 * says there is nothing to nag about. Both keep the app's settings screens
 * from erroring without promising anything the server does not do.
 */
class PixelfedService {
	/** The report reasons Pixelfed's app offers, and which Mastodon category each is. */
	private const REPORT_TYPES = [
		'spam' => Report::CATEGORY_SPAM,
		'sensitive' => Report::CATEGORY_OTHER,
		'abusive' => Report::CATEGORY_VIOLATION,
		'underage' => Report::CATEGORY_VIOLATION,
		'violence' => Report::CATEGORY_VIOLATION,
		'copyright' => Report::CATEGORY_LEGAL,
		'impersonation' => Report::CATEGORY_OTHER,
		'scam' => Report::CATEGORY_OTHER,
		'terrorism' => Report::CATEGORY_VIOLATION,
	];

	/** What a report can be about. */
	private const REPORT_OBJECTS = ['post', 'user', 'story'];

	/** Pixelfed's licence ids: 1 is "all rights reserved", which is what a post here carries. */
	private const LICENSE_ALL_RIGHTS_RESERVED = 1;

	/** How many accounts a mention autocomplete or a mutuals list names at most. */
	public const ACCOUNTS_LIMIT = 24;

	public function __construct(
		private StoryService $storyService,
		private StoriesRequest $storiesRequest,
		private CollectionService $collectionService,
		private FollowService $followService,
		private CacheActorService $cacheActorService,
		private SearchService $searchService,
		private ReportService $reportService,
		private StreamService $streamService,
		private AvatarService $avatarService,
		private AccountService $accountService,
		private InstanceService $instanceService,
		private ConfigService $configService,
	) {
	}

	/**
	 * The story carousel as Pixelfed's app draws it: one node per account,
	 * the viewer's own first, each holding its stories oldest first.
	 *
	 * @return array{self: array<string, mixed>|null, nodes: list<array<string, mixed>>}
	 */
	public function carousel(Person $viewer): array {
		$groups = [];
		foreach ($this->storyService->carousel($viewer) as $story) {
			$owner = $story->getOwnerId();
			if (!isset($groups[$owner])) {
				$account = $story->getAuthor();
				$groups[$owner] = [
					'id' => ($account === null) ? '' : (string)$account->getNid(),
					'user' => $this->storyUser($account, $owner === $viewer->getId()),
					'nodes' => [],
					'url' => ($account === null) ? $owner : $account->getId(),
					'seen' => true,
				];
			}

			$groups[$owner]['nodes'][] = $this->storyNode($story);
			if (!$story->isSeen()) {
				$groups[$owner]['seen'] = false;
			}
		}

		$self = $groups[$viewer->getId()] ?? null;
		unset($groups[$viewer->getId()]);

		return [
			'self' => $self,
			'nodes' => array_values($groups),
		];
	}

	/**
	 * Who watched one of the viewer's own stories, as Account entities.
	 *
	 * @return list<Person>
	 */
	public function storyViewers(Person $viewer, int $storyId): array {
		return $this->accounts($this->storyService->viewers($viewer, $storyId));
	}

	/**
	 * The accounts a `@` in a story caption could mean.
	 *
	 * @return list<Person>
	 */
	public function mentionAutocomplete(string $q, int $limit = self::ACCOUNTS_LIMIT): array {
		$q = trim($q);
		if ($q === '') {
			return [];
		}

		return $this->accounts($this->searchService->searchAccounts($q, max(1, min(self::ACCOUNTS_LIMIT, $limit))));
	}

	/**
	 * The viewer's collections in Pixelfed's shape.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function collections(Person $viewer): array {
		$collections = [];
		foreach ($this->collectionService->forProfile($viewer, $viewer) as $collection) {
			$collections[] = $this->collectionEntity($this->collectionService->withPreview($collection));
		}

		return $collections;
	}

	/**
	 * An account by whatever the app has to name it with: a client id, a
	 * `name@host`, a bare local username, or an actor URL.
	 *
	 * @throws CacheActorDoesNotExistException
	 */
	public function resolveAccount(string $reference): Person {
		$reference = ltrim(trim($reference), '@');
		if ($reference === '') {
			throw new CacheActorDoesNotExistException('unknown account');
		}

		if (is_numeric($reference)) {
			if ((int)$reference < 1) {
				throw new CacheActorDoesNotExistException('unknown account');
			}
			$actors = $this->cacheActorService->getFromNids([(int)$reference]);
			if ($actors === []) {
				throw new CacheActorDoesNotExistException('unknown account');
			}

			return $actors[0];
		}

		if (str_starts_with($reference, 'http://') || str_starts_with($reference, 'https://')) {
			return $this->cacheActorService->getFromId($reference);
		}

		// only what this server already knows: a profile screen is not the
		// place to start fetching strangers from
		return $this->cacheActorService->getFromAccount($reference, false);
	}

	/**
	 * Pixelfed's "mutuals": the accounts the viewer follows that also follow
	 * the account named — which is exactly Mastodon's familiar followers.
	 *
	 * @return list<Person>
	 */
	public function mutuals(Person $viewer, string $reference): array {
		$target = $this->resolveAccount($reference);

		return $this->accounts($this->followService->familiarFollowers($viewer, $target, self::ACCOUNTS_LIMIT));
	}

	/**
	 * Removes the viewer's avatar and answers with the account as it now is.
	 */
	public function removeAvatar(Person $viewer): Person {
		$this->avatarService->remove($viewer->getUserId());

		$account = $this->accountService->getActorFromUserId($viewer->getUserId(), true);
		$account->setExportFormat(ACore::FORMAT_LOCAL);

		return $account;
	}

	/**
	 * A report as the app files one — a reason, a thing and a kind of thing —
	 * turned into the report this server keeps.
	 *
	 * The reason becomes Mastodon's category and is kept in the comment as
	 * well, because `violation` says less than `underage` did. A report about
	 * a post names its author and carries the post; one about a story names
	 * the story's owner and nothing else, since a story has no client id a
	 * moderator could open.
	 *
	 * @throws InvalidResourceException
	 */
	public function report(Person $viewer, string $reportType, string $objectId, string $objectType, string $message): void {
		$reportType = strtolower(trim($reportType));
		if (!array_key_exists($reportType, self::REPORT_TYPES)) {
			throw new InvalidResourceException('unknown report_type');
		}

		$objectType = strtolower(trim($objectType));
		if (!in_array($objectType, self::REPORT_OBJECTS, true)) {
			throw new InvalidResourceException('unknown object_type');
		}

		$statusIds = [];
		switch ($objectType) {
			case 'post':
				$post = $this->streamService->getStreamByNid((int)$objectId);
				$target = $this->cacheActorService->getFromId($post->getAttributedTo());
				$statusIds = [(string)$post->getNid()];
				break;
			case 'story':
				$story = $this->storiesRequest->getLiveById((int)$objectId);
				$target = $this->cacheActorService->getFromId($story->getOwnerId());
				break;
			default:
				$target = $this->resolveAccount($objectId);
		}

		if ($target->getId() === $viewer->getId()) {
			throw new InvalidResourceException('you cannot report yourself');
		}

		$message = trim($message);
		$comment = ($message === '') ? $reportType : $reportType . ': ' . $message;

		$this->reportService->reportFromLocal(
			$viewer, $target, $statusIds, $comment, self::REPORT_TYPES[$reportType], false
		);
	}

	/**
	 * What the app's composer reads before it lets anybody write.
	 *
	 * @return array<string, mixed>
	 */
	public function composeSettings(Person $viewer): array {
		return [
			'allowed_media_types' => $this->instanceService->supportedMimeTypes(),
			'max_caption_length' => InstanceService::MAX_CHARACTERS,
			// the only licence a post here carries
			'default_license' => self::LICENSE_ALL_RIGHTS_RESERVED,
			// whether alt text is *required*; it is asked for, not demanded
			'media_descriptions' => false,
			'max_file_size' => $this->instanceService->maxUploadSize(),
			'max_media_attachments' => Stream::MAX_ATTACHMENTS,
			'max_altext_length' => PixelfedConfigService::MAX_ALTTEXT_LENGTH,
			'default_scope' => $this->defaultScope($viewer),
		];
	}

	/**
	 * The push settings screen, told the truth: there is no Web Push here,
	 * so nothing is enabled and there is no token to compare against.
	 *
	 * @return array<string, mixed>
	 */
	public function pushState(Person $viewer): array {
		return [
			'version' => '1',
			'username' => $viewer->getPreferredUsername(),
			'profile_id' => (string)$viewer->getNid(),
			'notify_enabled' => false,
			'has_token' => false,
			'notify_like' => false,
			'notify_follow' => false,
			'notify_mention' => false,
			'notify_comment' => false,
		];
	}

	/**
	 * The answer to "is this the token you have": no token, so no match.
	 *
	 * @return array<string, mixed>
	 */
	public function pushCompare(Person $viewer): array {
		return [
			'version' => '1',
			'username' => $viewer->getPreferredUsername(),
			'profile_id' => (string)$viewer->getNid(),
			'notify_enabled' => false,
			'match' => false,
			'has_existing' => false,
		];
	}

	/**
	 * Pixelfed's nag — a banner the app shows when its server wants something
	 * of the user. This one never does.
	 *
	 * @return array{active: false}
	 */
	public function nagState(): array {
		return ['active' => false];
	}

	/**
	 * One collection as Pixelfed's app expects it.
	 *
	 * `visibility` uses Pixelfed's words: a followers-only collection is what
	 * Pixelfed calls `private`. `thumb` is the first picture of the first
	 * post, or nothing, and `url` is this app's own page for the collection.
	 *
	 * @return array<string, mixed>
	 */
	private function collectionEntity(Collection $collection): array {
		$thumb = '';
		foreach ($collection->getPreview() as $post) {
			foreach ($post->getAttachments() as $attachment) {
				$thumb = $attachment->getPreviewUrl() ?: $attachment->getUrl();
				break 2;
			}
		}

		$created = date('c', $collection->getCreation());

		return [
			'id' => (string)$collection->getId(),
			'pid' => $this->ownerNid($collection->getOwnerId()),
			'visibility' => $collection->isPublic() ? 'public' : 'private',
			'title' => $collection->getTitle(),
			'description' => $collection->getDescription(),
			'thumb' => $thumb,
			'url' => rtrim($this->configService->getSocialUrl(), '/') . '/collections/' . $collection->getId(),
			'post_count' => $collection->getSize(),
			'published_at' => $collection->isPublic() ? $created : null,
			'created_at' => $created,
			'updated_at' => date('c', ($collection->getUpdated() === 0) ? $collection->getCreation() : $collection->getUpdated()),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function storyNode(Story $story): array {
		$media = $story->getMedia();

		return [
			'id' => (string)$story->getId(),
			'pid' => $this->ownerNid($story->getOwnerId()),
			'type' => ($media !== null && $media->getType() === 'video') ? 'video' : 'photo',
			'src' => ($media === null) ? '' : $media->getUrl(),
			'duration' => $story->getDuration(),
			'seen' => $story->isSeen(),
			'created_at' => date('c', $story->getCreation()),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function storyUser(?Person $account, bool $isViewer): array {
		if ($account === null) {
			return ['id' => '', 'username' => '', 'username_acct' => '', 'avatar' => '', 'local' => false, 'is_author' => $isViewer];
		}

		$account->setExportFormat(ACore::FORMAT_LOCAL);
		$entity = $account->exportAsLocal();

		return [
			'id' => (string)($entity['id'] ?? ''),
			'username' => (string)($entity['username'] ?? ''),
			'username_acct' => (string)($entity['acct'] ?? ''),
			'avatar' => (string)($entity['avatar'] ?? ''),
			'local' => $account->isLocal(),
			'is_author' => $isViewer,
		];
	}

	private function ownerNid(string $actorId): string {
		try {
			return (string)$this->cacheActorService->getFromId($actorId)->getNid();
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * The audience a new post starts with, in Pixelfed's words.
	 */
	private function defaultScope(Person $viewer): string {
		$privacy = $viewer->getPrivacy();

		return match ($privacy) {
			'', Stream::TYPE_PUBLIC => 'public',
			Stream::TYPE_UNLISTED => 'unlisted',
			Stream::TYPE_FOLLOWERS, 'private' => 'private',
			default => 'public',
		};
	}

	/**
	 * @param array<Person> $accounts
	 * @return list<Person>
	 */
	private function accounts(array $accounts): array {
		$entities = [];
		foreach ($accounts as $account) {
			$entities[] = $account->setExportFormat(ACore::FORMAT_LOCAL);
		}

		return $entities;
	}
}
