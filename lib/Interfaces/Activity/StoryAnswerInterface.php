<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\StoryInteraction;
use OCA\Social\Model\ActivityPub\Activity\View;
use OCA\Social\Service\StoryInteractionService;
use OCA\Social\Service\ViewCountService;

/**
 * The three ways a peer answers a story: `View`, `Story:Reaction`, `Story:Reply`
 * — and, since PeerTube sends one for every watch of a video, the way a peer
 * says it has watched a post.
 *
 * One interface for all three because the checks are the same and the decision
 * is the same: this is about a story, or it is about nothing. What is *done*
 * with it differs by one line, which is why the service takes them apart
 * rather than this.
 *
 * An answer names its story by address and nothing else, so the origin check
 * here is on the actor: the account claiming to have watched or reacted must
 * be on the server that sent the activity. The story's own ownership is
 * checked where it is read — it must be one of this instance's, still live,
 * and followed by whoever is answering.
 */
class StoryAnswerInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private StoryInteractionService $storyInteractionService,
		private ViewCountService $viewCountService,
	) {
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		// what stops one server reporting a view, a reaction or a reply on
		// behalf of an account on another
		$item->checkOrigin($item->getActorId());

		if ($item instanceof View) {
			// A `View` is not only a story's. PeerTube sends one to the owner
			// of a video for every watch, which is the same fact about a post
			// rather than about a story — so a view that names no story of ours
			// is offered to the post counter before it is dropped.
			if (!$this->storyInteractionService->receiveView($item)) {
				$this->viewCountService->receiveView($item->getStoryId(), $item->getActorId());
			}

			return;
		}

		if ($item instanceof StoryInteraction) {
			$this->storyInteractionService->receiveInteraction($item);
		}
	}
}
