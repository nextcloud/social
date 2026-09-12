<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\PlacesRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Place;

/**
 * Where a post was taken.
 *
 * Nothing here geocodes anything. Sending somebody's location to a third party
 * at the moment they are deciding whether to publish it is the same failure the
 * Exif stripping in this release exists to prevent, and doing it deliberately
 * would be worse than doing it by accident. A place is therefore either one this
 * instance has already seen, or one the client names outright with coordinates
 * it already had.
 */
class PlaceService {
	public function __construct(
		private PlacesRequest $placesRequest,
	) {
	}

	/**
	 * Places whose name begins with what was typed, out of what this instance
	 * has already seen.
	 *
	 * @return Place[]
	 */
	public function search(string $term, int $limit = PlacesRequest::SEARCH_LIMIT): array {
		return $this->placesRequest->search($term, $limit);
	}

	/** @throws ItemNotFoundException */
	public function byId(int $id): Place {
		return $this->placesRequest->getById($id);
	}

	/**
	 * The place a client asked a post to carry, or null for "nowhere".
	 *
	 * A client may name one by id -- one it got from the search route -- or
	 * outright, with a name and optionally coordinates. An id that is not there
	 * means nowhere rather than an error: a post is worth more than its
	 * location, and refusing to publish it over a stale place id would be the
	 * wrong trade.
	 */
	public function resolve(int $placeId, string $name, string $country, string $lat, string $lon): ?Place {
		if ($placeId > 0) {
			try {
				return $this->placesRequest->getById($placeId);
			} catch (ItemNotFoundException $e) {
				return null;
			}
		}

		$place = (new Place())->setName($name)->setCountry($country)->setCoordinates($lat, $lon);
		if ($place->getName() === '') {
			return null;
		}

		return $this->placesRequest->findOrCreate($place);
	}

	/**
	 * Fills in the place of every post on a page, in one query.
	 *
	 * The same shape as `LinkPreviewService::attachCards()` and for the same
	 * reason: a timeline draws dozens of posts, and asking per post is how a
	 * screen becomes dozens of round trips. The alternative -- joining
	 * `social_place` into every timeline query -- would put the cost on every
	 * page whether or not any post on it has a place, and almost none do.
	 *
	 * @param Stream[] $posts
	 */
	public function attachPlaces(array $posts): void {
		$ids = [];
		foreach ($posts as $post) {
			if ($post instanceof Stream && $post->getPlaceId() > 0) {
				$ids[] = $post->getPlaceId();
			}
		}

		if ($ids === []) {
			return;
		}

		$places = $this->placesRequest->getByIds($ids);
		foreach ($posts as $post) {
			if (!$post instanceof Stream) {
				continue;
			}

			$place = $places[$post->getPlaceId()] ?? null;
			if ($place !== null) {
				$post->setPlace($place);
			}
		}
	}
}
