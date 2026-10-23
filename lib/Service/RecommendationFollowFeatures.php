<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Stream;

/** Resolves accepted follows for one bounded recommendation candidate batch. */
final class RecommendationFollowFeatures {
	public function __construct(
		private FollowsRequest $follows,
	) {
	}

	/**
	 * @param list<Stream> $posts
	 * @return array<string, true> author IDs the viewer currently follows
	 */
	public function followedAuthors(Person $viewer, array $posts): array {
		$authors = [];
		foreach ($posts as $post) {
			$author = $post->getAttributedTo();
			if ($author !== '' && $author !== $viewer->getId()) {
				$authors[$author] = true;
			}
		}
		if ($authors === []) {
			return [];
		}

		$relationships = $this->follows->getBetweenMany($viewer->getId(), array_keys($authors));
		$followed = [];
		foreach ($relationships['following'] as $author => $follow) {
			if ($follow->getType() === Follow::TYPE && $follow->isAccepted()) {
				$followed[$author] = true;
			}
		}

		return $followed;
	}
}
