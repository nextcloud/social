<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;
use Psr\Log\LoggerInterface;

/**
 * Voting on federated polls: a vote is a bare Note whose `name` is the chosen
 * option, addressed only to the poll's author — exactly what Mastodon expects.
 * The chosen indices are remembered per viewer in the stream action, so the
 * poll renders as voted; authoritative counts arrive later as Update{Question}
 * from the origin server and refresh the stored source.
 *
 * Creating own polls is not supported.
 */
class PollService {
	use TStringTools;

	public function __construct(
		private StreamRequest $streamRequest,
		private CacheActorService $cacheActorService,
		private ActivityService $activityService,
		private SignatureService $signatureService,
		private StreamActionService $streamActionService,
		private StreamActionsRequest $streamActionsRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws StreamNotFoundException when the id is not a known poll
	 */
	public function getPoll(int $nid, ?Person $viewer = null): Question {
		$stream = $this->streamRequest->getStreamByNid($nid);
		if (!$stream instanceof Question) {
			throw new StreamNotFoundException('not a poll');
		}

		if ($viewer !== null && !$stream->hasAction()) {
			// single-stream fetches are viewer-less; attach the viewer's action
			// so voted/own_votes render
			try {
				$stream->setAction(
					$this->streamActionsRequest->getAction($viewer->getId(), $stream->getId())
				);
			} catch (\Exception $e) {
			}
		}

		return $stream;
	}

	/**
	 * @param int[] $choices option indices
	 *
	 * @throws StreamNotFoundException
	 * @throws InvalidActionException
	 */
	public function vote(Person $viewer, int $nid, array $choices): Question {
		$poll = $this->getPoll($nid, $viewer);

		if ($poll->isLocal()) {
			throw new InvalidActionException('only federated polls can be voted on');
		}
		if ($poll->isExpired()) {
			throw new InvalidActionException('this poll has ended');
		}

		$choices = array_values(array_unique(array_map('intval', $choices)));
		$options = $poll->getOptions();
		foreach ($choices as $choice) {
			if ($choice < 0 || $choice >= count($options)) {
				throw new InvalidActionException('invalid poll option');
			}
		}
		if ($choices === [] || (!$poll->isMultiple() && count($choices) > 1)) {
			throw new InvalidActionException('this poll accepts a single choice');
		}

		$stored = $poll->hasAction()
			? ($poll->getAction()->getValues()[StreamAction::POLL_VOTES] ?? '')
			: '';
		if ($stored !== '' && $stored !== '[]') {
			throw new InvalidActionException('you already voted on this poll');
		}

		$author = $this->cacheActorService->getFromId($poll->getAttributedTo());
		foreach ($choices as $choice) {
			$this->federateVote($viewer, $poll, $options[$choice]['title'], $author);
		}

		$this->streamActionService->setAction(
			$viewer->getId(), $poll->getId(), StreamAction::POLL_VOTES, json_encode($choices)
		);

		// render the fresh vote without waiting for the origin's Update
		$action = new StreamAction($viewer->getId(), $poll->getId());
		$action->updateValue(StreamAction::POLL_VOTES, json_encode($choices));
		$poll->setAction($action);

		return $poll;
	}

	private function federateVote(Person $viewer, Question $poll, string $option, Person $author): void {
		/** @var Note $note */
		$note = AP::$activityPub->getItemFromType(Note::TYPE);
		$note->setId($viewer->getId() . '/vote/' . $this->uuid(8));
		$note->setName($option);
		$note->setInReplyTo($poll->getId());
		$note->setAttributedTo($viewer->getId());
		$note->setTo($author->getId());
		$note->setPublished(date('c'));
		$note->setLocal(true);

		/** @var Create $create */
		$create = AP::$activityPub->getItemFromType(Create::TYPE);
		$create->generateUniqueId('#vote');
		$create->setActor($viewer);
		$create->setObject($note);
		$create->setTo($author->getId());
		$create->addInstancePath(new InstancePath(
			$author->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
		));

		$this->signatureService->signObject($viewer, $create);
		$this->activityService->request($create);
	}

	/**
	 * The status export (with poll entity) for API responses.
	 */
	public function exportPoll(Question $poll): array {
		$poll->setExportFormat(ACore::FORMAT_LOCAL);

		return $poll->exportAsLocal()['poll'];
	}
}
