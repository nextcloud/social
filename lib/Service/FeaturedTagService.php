<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\FeaturedTagsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\FeaturedTag;
use OCP\IURLGenerator;

/**
 * The hashtags an account pins to its profile.
 *
 * A featured tag is a claim an account makes about itself — "this is what I
 * post about" — so the only thing stored is the claim. The numbers beside it
 * (`statuses_count`, `last_status_at`) are counted from the posts when the
 * entity is built, so they cannot disagree with the tag timeline a visitor
 * gets by clicking it.
 *
 * The instance advertises a ceiling as `configuration.accounts
 * .max_featured_tags`, and this is where it is enforced. The advertisement
 * lives in `InstanceService` and currently says `0`, which is how a client is
 * told the feature does not exist: until that number changes, every Mastodon
 * client hides the UI regardless of what these routes answer.
 */
class FeaturedTagService {
	/**
	 * How many tags one account may feature.
	 *
	 * The number `InstanceService` must advertise as
	 * `configuration.accounts.max_featured_tags`. It is Mastodon's own limit,
	 * so a client that pre-checks against it behaves here as it does there.
	 */
	public const MAX_FEATURED_TAGS = 10;

	/** How many suggestions `GET /api/v1/featured_tags/suggestions` offers. */
	public const SUGGESTIONS_LIMIT = 10;

	public function __construct(
		private FeaturedTagsRequest $featuredTagsRequest,
		private HashtagService $hashtagService,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * Every tag an account features, with its counts.
	 *
	 * @return FeaturedTag[]
	 */
	public function featured(string $actorId): array {
		$tags = $this->featuredTagsRequest->getByActor($actorId);
		if ($tags === []) {
			return [];
		}

		$usage = $this->featuredTagsRequest->countUsage(
			$actorId,
			array_map(static fn (FeaturedTag $tag): string => $tag->getHashtag(), $tags)
		);

		foreach ($tags as $tag) {
			$counts = $usage[$tag->getHashtag()] ?? ['count' => 0, 'last' => ''];
			$tag->setUrl($this->tagUrl($tag->getHashtag()))
				->setStatusesCount($counts['count'])
				->setLastStatusAt($counts['last']);
		}

		return $tags;
	}

	/**
	 * Pins a tag to the account's profile.
	 *
	 * @throws InvalidResourceException the tag is not one, or the account is
	 *                                  already at the advertised ceiling
	 */
	public function feature(string $actorId, string $name): FeaturedTag {
		$hashtag = FeaturedTagsRequest::normaliseHashtag($name);
		if ($hashtag === '') {
			throw new InvalidResourceException("Name can't be blank");
		}

		try {
			// an existing one is not a failure and does not count against the
			// ceiling: a client that lost the answer and retried asked for a
			// state that is already true
			return $this->withCounts($actorId, $this->featuredTagsRequest->getOwnedByHashtag($actorId, $hashtag));
		} catch (ItemNotFoundException) {
		}

		if ($this->featuredTagsRequest->countByActor($actorId) >= self::MAX_FEATURED_TAGS) {
			throw new InvalidResourceException(
				'Limit of ' . self::MAX_FEATURED_TAGS . ' featured tags reached'
			);
		}

		$tag = (new FeaturedTag())->setOwnerId($actorId)->setHashtag($hashtag);

		return $this->withCounts($actorId, $this->featuredTagsRequest->create($tag));
	}

	/**
	 * @throws ItemNotFoundException it is not there, or it is not theirs —
	 *                               which are one answer
	 */
	public function unfeature(string $actorId, int $id): void {
		$this->featuredTagsRequest->delete($this->featuredTagsRequest->getOwnedById($actorId, $id));
	}

	/**
	 * The tags an account posts with most and has not featured, as Tag
	 * entities.
	 *
	 * Built by `HashtagService::tagEntity()` rather than here, so a Tag from
	 * this route and a Tag from the trends or the tag lookup cannot mean
	 * different things. `following` is left out: this is a list of the asking
	 * account's own tags, and whether they follow their own hashtag is not
	 * what the route is about.
	 *
	 * @return array[]
	 */
	public function suggestions(string $actorId): array {
		$featured = [];
		foreach ($this->featuredTagsRequest->getByActor($actorId) as $tag) {
			$featured[$tag->getHashtag()] = true;
		}

		$suggestions = [];
		$mostUsed = $this->featuredTagsRequest->mostUsed(
			$actorId, self::SUGGESTIONS_LIMIT + count($featured)
		);

		foreach (array_keys($mostUsed) as $hashtag) {
			if (isset($featured[$hashtag])) {
				continue;
			}

			$suggestions[] = $this->hashtagService->tagEntity($hashtag);
			if (count($suggestions) >= self::SUGGESTIONS_LIMIT) {
				break;
			}
		}

		return $suggestions;
	}

	private function withCounts(string $actorId, FeaturedTag $tag): FeaturedTag {
		$usage = $this->featuredTagsRequest->countUsage($actorId, [$tag->getHashtag()]);
		$counts = $usage[$tag->getHashtag()] ?? ['count' => 0, 'last' => ''];

		return $tag->setUrl($this->tagUrl($tag->getHashtag()))
			->setStatusesCount($counts['count'])
			->setLastStatusAt($counts['last']);
	}

	/** The same tag timeline `HashtagService::tagEntity()` links a Tag to. */
	private function tagUrl(string $hashtag): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'social.Navigation.timeline', ['path' => 'tags/' . $hashtag]
		);
	}
}
