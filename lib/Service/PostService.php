<?php

declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Service;

use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Post;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

class PostService {
	private StreamService $streamService;
	private AccountService $accountService;
	private ActivityService $activityService;
	private CacheDocumentService $cacheDocumentService;
	private ConfigService $configService;
	private MiscService $miscService;
	private LoggerInterface $logger;

	public function __construct(
		StreamService $streamService, AccountService $accountService, ActivityService $activityService,
		CacheDocumentService $cacheDocumentService, ConfigService $configService, MiscService $miscService, LoggerInterface $logger
	) {
		$this->streamService = $streamService;
		$this->accountService = $accountService;
		$this->activityService = $activityService;
		$this->cacheDocumentService = $cacheDocumentService;
		$this->configService = $configService;
		$this->miscService = $miscService;
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
		$note->setContent(htmlentities($post->getContent(), ENT_QUOTES));
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
	 * @param Note $note
	 * @param Post $post
	 */
	private function generateDocumentsFromAttachments(Note $note, Post $post) {
		$documents = [];
		if (!isset($_FILES['attachments'])) {
			return;
		}
		if (is_array($_FILES['attachments']['error'])) {
			foreach ($_FILES['attachments']['error'] as $key => $error) {
				if ($error == UPLOAD_ERR_OK) {
					try {
						$document = $this->generateDocumentFromAttachment($note, $key);

						$service = AP::$activityPub->getInterfaceForItem($document);
						$service->save($document);

						$documents[] = $document;
					} catch (Exception $e) {
					}
				}
			}
		} else {
			try {
				$tmp_name = $_FILES["attachments"]["tmp_name"];
				$name = basename($_FILES["attachments"]["name"]);
				$tmpFile = tmpfile();
				$tmpPath = stream_get_meta_data($tmpFile)['uri'];
				if (move_uploaded_file($tmp_name, $tmpPath)) {
					$document = new Document();
					$document->setUrlCloud($this->configService->getCloudUrl());
					$document->generateUniqueId('/documents/local');
					$document->setParentId($note->getId());
					$document->setPublic(true);

					$this->cacheDocumentService->saveFromTempToCache($document, $tmpPath);
				}

				$service = AP::$activityPub->getInterfaceForItem($document);
				$service->save($document);

				$documents[] = $document;
			} catch (Exception $e) {
				$this->logger->error($e->getMessage(), [
					'exception' => $e,
				]);
			}
		}
		$post->setDocuments($documents);
	}


	/**
	 * @param Note $note
	 * @param string $attachment
	 *
	 * @return Document
	 * @throws CacheContentMimeTypeException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	private function generateDocumentFromAttachment(Note $note, int $key): Document {
		$tmp_name = $_FILES["attachments"]["tmp_name"][$key];
		$name = basename($_FILES["attachments"]["name"][$key]);
		$tmpFile = tmpfile();
		$tmpPath = stream_get_meta_data($tmpFile)['uri'];
		if (move_uploaded_file($tmp_name, $tmpPath)) {
			$document = new Document();
			$document->setUrlCloud($this->configService->getCloudUrl());
			$document->generateUniqueId('/documents/local');
			$document->setParentId($note->getId());
			$document->setPublic(true);

			$this->cacheDocumentService->saveFromTempToCache($document, $tmpPath);
		}


		return $document;
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
