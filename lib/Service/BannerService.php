<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\InstancePath;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The banner across the top of a profile — `image` on the actor document.
 *
 * There are three ways one arrives: a file the user picked, a URL they typed,
 * and `header` on Mastodon's `update_credentials`. All three end with the same
 * work — store the bytes, point the cached actor at them, tell the followers —
 * and that work is here so the three cannot drift on the parts that matter:
 * the banner being public where an attachment is not, and the `Update{Person}`
 * that is the only reason anybody else ever sees it.
 */
class BannerService {
	public function __construct(
		private AccountService $accountService,
		private ActorService $actorService,
		private CacheActorService $cacheActorService,
		private CacheDocumentService $cacheDocumentService,
		private ActivityService $activityService,
		private ConfigService $configService,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Stores a local file as the user's banner and federates the change.
	 *
	 * @throws \Exception the actor is unknown, or the bytes are not an image
	 *                    this app stores
	 */
	public function setFromTempFile(string $userId, string $tmpPath): Image {
		$actor = $this->accountService->getActorFromUserId($userId);

		$image = new Image();
		$image->setLocal(true);
		$image->setAccount($actor->getPreferredUsername());
		$image->setUrlCloud($this->configService->getCloudUrl());
		$image->generateUniqueId('/documents/header');
		// unlike an attachment, a banner is public by definition: it is part of
		// the actor document, which every server that has heard of this account
		// may read
		$image->setPublic(true);

		$this->cacheDocumentService->saveFromTempToCache($image, $tmpPath);
		$image->setUrl($image->getMediaUrl($this->urlGenerator, $image->getMimeType()));

		$interface = AP::$activityPub->getInterfaceForItem($image);
		$interface->save($image);

		$this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
		$cached = $this->cacheActorService->getFromId($actor->getId());
		$cached->setHeader($image->getUrl());
		$this->actorService->cacheLocalActor($cached);

		$this->announce($cached);

		return $image;
	}

	/**
	 * A banner nobody is told about is a banner only this instance can see, so
	 * the failure is worth a line in the log — but not worth losing the banner
	 * over: it is stored either way, and a remote server picks it up the next
	 * time it refreshes the actor.
	 */
	private function announce(Person $cached): void {
		try {
			$update = clone $cached;
			$update->addInstancePath(new InstancePath(
				$cached->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			));
			$this->activityService->updateActivity($cached, $update);
		} catch (\Exception $e) {
			$this->logger->warning('could not federate a banner change', [
				'actor' => $cached->getId(),
				'exception' => $e,
			]);
		}
	}
}
