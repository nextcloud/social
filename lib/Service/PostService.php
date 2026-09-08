<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Post;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use Psr\Log\LoggerInterface;

class PostService {
	private StreamService $streamService;
	private AccountService $accountService;
	private ActivityService $activityService;
	private LoggerInterface $logger;

	public function __construct(
		StreamService $streamService, AccountService $accountService, ActivityService $activityService,
		LoggerInterface $logger,
	) {
		$this->streamService = $streamService;
		$this->accountService = $accountService;
		$this->activityService = $activityService;
		$this->logger = $logger;
	}

	/**
	 * @param Post $post
	 * @param string $token
	 *
	 * @return ACore
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 * @throws UnauthorizedFediverseException
	 */
	public function createPost(Post $post, string &$token = ''): ?ACore {
		$this->fixRecipientAndHashtags($post);

		$note = new Note();
		$actor = $post->getActor();
		$this->streamService->assignItem($note, $actor, $post->getType());

		$note->setAttributedTo($actor->getId());
		$note->setContent(nl2br(htmlentities($post->getContent(), ENT_QUOTES)));
		$note->setAttachments($post->getMedias());
		$note->setVisibility($post->getType());

		$this->streamService->replyTo($note, $post->getReplyTo());
		$this->streamService->addRecipients($note, $post->getType(), $post->getTo());
		$this->streamService->addHashtags($note, $post->getHashtags());
		//		$this->streamService->addAttachments($note, $post->getDocuments());

		$token = $this->activityService->createActivity($actor, $note, $activity);
		$this->accountService->cacheLocalActorDetailCount($actor);

		$this->logger->debug('Activity: ' . json_encode($activity));

		return $activity;
	}

	/**
	 * @throws \Exception
	 */
	public function editPost(int $nid, Person $actor, string $content, ?string $spoilerText = null, ?bool $sensitive = null): Stream {
		$stream = $this->streamService->getStreamByNid($nid);

		if ($stream->getAttributedTo() !== $actor->getId()) {
			throw new \Exception('Not authorized to edit this post');
		}

		$stream->setContent(nl2br(htmlentities($content, ENT_QUOTES)));
		if ($spoilerText !== null) {
			$stream->setSpoilerText($spoilerText);
		}
		if ($sensitive !== null) {
			$stream->setSensitive($sensitive);
		}
		$stream->setPublished(date('c'));

		$this->streamService->updateStream($stream);

		$updated = $this->streamService->getStreamByNid($nid);
		$updated->addInstancePath(
			new InstancePath(
				$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			)
		);

		try {
			$this->activityService->updateActivity($actor, $updated);
		} catch (\Exception $e) {
			$this->logger->warning('Failed to federate post update', ['exception' => $e]);
		}

		return $updated;
	}

	/**
	 * @param Post $post
	 */
	public function fixRecipientAndHashtags(Post $post) {
		preg_match_all('/(?!\b)@([^\s]+)/', $post->getContent(), $matchesTo);
		preg_match_all('/(?!\b)#([^\s]+)/', $post->getContent(), $matchesHash);

		foreach ($matchesTo[1] as $to) {
			$post->addTo($to);
		}

		foreach ($matchesHash[1] as $hash) {
			$post->addHashtag($hash);
		}
	}
}
