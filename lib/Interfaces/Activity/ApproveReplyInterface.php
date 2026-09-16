<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\ApproveReply;
use OCA\Social\Model\ActivityPub\Stream;
use Psr\Log\LoggerInterface;

/**
 * "Your reply may be shown": FEP-5624, as PeerTube ≥ 6.2 sends it.
 *
 * A video that moderates its comments takes a reply in and shows it to nobody
 * until somebody has looked at it, then tells the server the reply came from.
 * This is that message arriving, and all it does is move a state on our own
 * reply — the reply is already stored and already ours, and nothing about the
 * post it answers changes.
 *
 * **Only about a reply of ours, and only from the server that holds what it
 * answers.** An `ApproveReply` from anywhere else would be a stranger deciding
 * what has been approved on somebody else's post.
 */
class ApproveReplyInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private StreamRequest $streamRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws InvalidOriginException the approval does not come from the server
	 *                                that holds the post the reply answers
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		if (!$item instanceof ApproveReply) {
			return;
		}

		$replyId = $item->getObjectId();
		if ($replyId === '') {
			return;
		}

		try {
			$reply = $this->streamRequest->getStreamById($replyId);
		} catch (StreamNotFoundException $e) {
			// an approval about something we never wrote
			return;
		}

		if (!$reply->isLocal() || $reply->getInReplyTo() === '') {
			return;
		}

		// the approval has to come from the server that holds the post being
		// replied to: anybody else saying so would be a stranger approving a
		// reply on somebody else's post
		$item->checkOrigin($reply->getInReplyTo());

		$reply->setReplyState(Stream::REPLY_APPROVED);
		$this->streamRequest->updateDetails($reply);

		$this->logger->info('a reply of ours was approved', [
			'reply' => $replyId, 'inReplyTo' => $reply->getInReplyTo(),
		]);
	}
}
