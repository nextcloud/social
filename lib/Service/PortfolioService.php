<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\PortfoliosRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\Portfolio;
use Throwable;

/**
 * A page of somebody's work, to put on a CV.
 *
 * A profile is a feed: everything somebody posted, newest first, with the
 * follow button and the boosts and the replies around it. A portfolio is the
 * opposite — a title, a sentence, a set of pictures, and nothing else. It is
 * what a photographer links from a CV, and a profile is not that however it is
 * styled. Pixelfed has the same thing and its photographers are the people who
 * ask for it.
 *
 * The rule that everything here is built around: **the page is readable signed
 * out, so the posts on it are only ever public ones.** That is not a switch an
 * owner can get wrong — it is a property of how the posts are read. A
 * portfolio is asked for with **no viewer at all**, and the account timeline
 * query answers an anonymous reader with public posts and nothing else. A
 * followers-only photograph cannot reach this page, because nothing on the
 * path to it has ever seen one.
 */
class PortfolioService {
	public function __construct(
		private PortfoliosRequest $portfoliosRequest,
		private StreamRequest $streamRequest,
		private CollectionService $collectionService,
		private CacheActorService $cacheActorService,
		private StreamService $streamService,
	) {
	}

	/**
	 * The owner's own page, whether or not it has been turned on.
	 *
	 * An account that has never opened the editor gets the defaults rather
	 * than a 404: there is nothing to find, and handing back an empty
	 * portfolio is what lets the editor render without a "create it first"
	 * step nobody needs.
	 */
	public function own(Person $owner): Portfolio {
		try {
			$portfolio = $this->portfoliosRequest->getByActor($owner->getId());
		} catch (ItemNotFoundException $e) {
			$portfolio = (new Portfolio())->setActorId($owner->getId());
		}

		$portfolio->setAuthor($this->authorOf($owner->getId()));
		// as the internet will see it, not as its owner can. An owner
		// previewing their own draft was shown their followers-only pictures
		// among the rest, and none of those would have been on the published
		// page — a preview that shows a photograph which will not appear is
		// worse than no preview. Found on devel.
		$portfolio->setPosts($this->postsFor($portfolio, null));

		return $portfolio;
	}

	/**
	 * Somebody else's page, as the internet reads it.
	 *
	 * A portfolio that has not been turned on is a **404**, not an empty page
	 * with somebody's name on it: a row that exists is a draft, and `active`
	 * is the moment its owner decided the internet may read it.
	 *
	 * @throws ItemNotFoundException
	 */
	public function published(string $handle): Portfolio {
		try {
			$owner = $this->cacheActorService->getFromAccount($handle, false);
		} catch (Throwable $e) {
			throw new ItemNotFoundException('no portfolio');
		}

		$portfolio = $this->portfoliosRequest->getByActor($owner->getId());
		if (!$portfolio->isActive()) {
			throw new ItemNotFoundException('no portfolio');
		}

		$portfolio->setAuthor($this->authorOf($owner->getId()));
		// deliberately with no viewer: what a portfolio shows is what the
		// whole internet may see, whoever happens to be reading it
		$portfolio->setPosts($this->postsFor($portfolio, null));

		return $portfolio;
	}

	/**
	 * Writes the owner's page.
	 *
	 * @throws InvalidResourceException the collection named is not theirs
	 */
	public function save(Person $owner, array $values): Portfolio {
		try {
			$portfolio = $this->portfoliosRequest->getByActor($owner->getId());
		} catch (ItemNotFoundException $e) {
			$portfolio = (new Portfolio())->setActorId($owner->getId());
		}

		$portfolio->setActive((bool)($values['active'] ?? $portfolio->isActive()))
			->setTitle((string)($values['title'] ?? $portfolio->getTitle()))
			->setIntro((string)($values['intro'] ?? $portfolio->getIntro()))
			->setLayout((string)($values['layout'] ?? $portfolio->getLayout()))
			->setSource((string)($values['source'] ?? $portfolio->getSource()))
			->setCollectionId((int)($values['collection_id'] ?? $portfolio->getCollectionId()))
			->setShowCaptions((bool)($values['show_captions'] ?? $portfolio->showsCaptions()))
			->setShowPlaces((bool)($values['show_places'] ?? $portfolio->showsPlaces()))
			->setShowDates((bool)($values['show_dates'] ?? $portfolio->showsDates()))
			->setShowAvatar((bool)($values['show_avatar'] ?? $portfolio->showsAvatar()));

		if ($portfolio->getSource() === Portfolio::SOURCE_COLLECTION) {
			if ($portfolio->getCollectionId() < 1) {
				throw new InvalidResourceException('there is no collection to show');
			}

			// checked here rather than where the page is read: a page built
			// from somebody else's collection is a thing to refuse when it is
			// asked for, not a thing to render as empty afterwards
			try {
				$this->collectionService->own($owner, $portfolio->getCollectionId());
			} catch (Throwable $e) {
				throw new InvalidResourceException('that is not one of your collections');
			}
		}

		$saved = $this->portfoliosRequest->save($portfolio);
		$saved->setAuthor($this->authorOf($owner->getId()));
		$saved->setPosts($this->postsFor($saved, null));

		return $saved;
	}

	/** An account's page goes with the account. */
	public function forgetActor(string $actorId): void {
		$this->portfoliosRequest->deleteByActor($actorId);
	}

	/**
	 * The pictures on the page.
	 *
	 * Always **null** today, which is the point: every reader of this page,
	 * its owner included, is shown what the internet is shown. The parameter
	 * stays because the collection source needs a reader to decide whether a
	 * followers-only collection is readable, and passing nobody there is how
	 * it answers "no".
	 *
	 * @param Person|null $viewer who to read the posts as
	 *
	 * @return array
	 */
	private function postsFor(Portfolio $portfolio, ?Person $viewer): array {
		if ($portfolio->getSource() === Portfolio::SOURCE_COLLECTION) {
			return $this->fromCollection($portfolio, $viewer);
		}

		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::ACCOUNT)
			->setAccountId($portfolio->getActorId())
			->setFormat(ACore::FORMAT_LOCAL)
			->setLimit(Portfolio::MAX_POSTS);
		// a portfolio is pictures; a text post on one is a paragraph in a
		// gallery
		$options->setMediaType('image');

		// the viewer is set on the request rather than on the options, which is
		// where every other timeline read sets it. Null clears it, and a
		// cleared viewer is what makes the account query answer with public
		// posts and nothing else — the whole guarantee this page rests on.
		if ($viewer !== null) {
			$this->streamRequest->setViewer($viewer);
		} else {
			$this->streamRequest->resetViewer();
		}

		$posts = $this->streamRequest->getTimeline($options);
		$this->streamService->attachTaggedPeople($posts);

		return $posts;
	}

	/**
	 * @return array
	 */
	private function fromCollection(Portfolio $portfolio, ?Person $viewer): array {
		try {
			$collection = $this->collectionService->readable($viewer, $portfolio->getCollectionId());
		} catch (Throwable $e) {
			// a collection its owner has since deleted or made followers-only
			// leaves the page empty rather than broken
			return [];
		}

		return $this->collectionService->posts($collection, Portfolio::MAX_POSTS);
	}

	private function authorOf(string $actorId): ?Person {
		try {
			$author = $this->cacheActorService->getFromId($actorId);
			$author->setExportFormat(ACore::FORMAT_LOCAL);

			return $author;
		} catch (Throwable $e) {
			return null;
		}
	}
}
