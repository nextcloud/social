<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\AP;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PeerTubeService;
use OCP\Server;
use Throwable;

class Note extends Stream implements JsonSerializable {
	private string $name = '';
	public const TYPE = 'Note';

	private array $hashtags = [];

	public function __construct(?ACore $parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	#[\Override]
	public function getHashtags(): array {
		return $this->hashtags;
	}

	public function setHashtags(array $hashtags): Note {
		$this->hashtags = $hashtags;

		return $this;
	}

	public function fillMentions(): void {
		$personInterface = AP::instance()->getInterfaceFromType(Person::TYPE);
		$mentions = [];

		foreach ($this->getTags('Mention') as $item) {
			$username = ltrim($this->get('name', $item), '@');
			$mention = [
				'id' => 0,
				'username' => $username,
				'url' => $this->get('href', $item),
				'acct' => $username,
			];

			try {
				/** @var Person $actor */
				$actor = $personInterface->getItemById($mention['url']);
				$mention['id'] = (string)$actor->getNid();
				$mention['username'] = $actor->getPreferredUsername();
				$mention['acct'] = $actor->getAccount();
			} catch (ItemNotFoundException $e) {
			}

			$mentions[] = $mention;
		}

		$this->setDetailArray('mentions', $mentions);
	}

	public function fillHashtags(): void {
		$tags = $this->getTags('Hashtag');
		$hashtags = [];
		foreach ($tags as $tag) {
			$hashtag = $tag['name'];
			if (substr($hashtag, 0, 1) === '#') {
				$hashtag = substr($hashtag, 1);
			}
			$hashtags[] = trim($hashtag);
		}

		$this->setHashtags($hashtags);
	}

	/**
	 * @throws ItemAlreadyExistsException
	 */
	#[\Override]
	public function import(array $data): void {
		parent::import($data);

		$this->fillHashtags();
		$this->fillMentions();
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		parent::importFromDatabase($data);

		$this->setHashtags($this->getArray('hashtags', $data, []));
	}

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): self {
		$this->name = $name;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		$result = parent::jsonSerialize();

		if ($this->getName() !== '') {
			// a poll vote: the chosen option travels in `name`
			$result['name'] = $this->getName();
		}

		// `hashtags` is this app's own shape and predates the client API.
		// Mastodon's `tags: [{name, url}]` carries the same data and is what a
		// client reads, so the bare-string version stays out of that format.
		if ($this->isCompleteDetails() && $this->getExportFormat() !== self::FORMAT_LOCAL) {
			$result['hashtags'] = $this->getHashtags();
		}

		$result = $this->asVideoIfItIsOne($result);

		$this->cleanArray($result);

		return $result;
	}

	/**
	 * A post that is a video goes onto the wire as a `Video`, not a `Note`.
	 *
	 * This is the outbound half of what `PeerTubeService` reads. PeerTube --
	 * and every other video-native server -- ingests `Video` objects and
	 * nothing else, so a Social instance publishing `Note`s had, from their
	 * side, no videos at all: not badly-formatted ones, none. Only the
	 * serialisation changes; the row stays a `Note`, exactly as an incoming
	 * `Video` is stored as one.
	 *
	 * Three guards, in cost order so the cheap ones decide first:
	 *
	 * - only for the wire. The client API is Mastodon's and has no `Video`.
	 * - only for **local** posts. A remote note is somebody else's document and
	 *   is re-serialised as it arrived.
	 * - only when the post *is* a video: one attachment, and it a video. See
	 *   `PeerTubeService::soleVideo()`.
	 *
	 * The app value is last because it costs a container lookup, and it exists
	 * because this cannot be proven from here: whether a Mastodon-family server
	 * renders a `Video` as well as it rendered the `Note` is a question only a
	 * real one can answer. `attachment` is published either way to make that
	 * as likely as possible, and an admin who finds otherwise turns it off with
	 * `occ config:app:set social publish_video_objects --value 0`.
	 */
	private function asVideoIfItIsOne(array $result): array {
		if ($this->getExportFormat() === self::FORMAT_LOCAL || !$this->isLocal()) {
			return $result;
		}

		$video = PeerTubeService::soleVideo($this->getAttachments());
		if ($video === null) {
			return $result;
		}

		try {
			if (!Server::get(ConfigService::class)->getAppValueBool(ConfigService::SOCIAL_PUBLISH_VIDEO)) {
				return $result;
			}
		} catch (Throwable $e) {
			// nothing to resolve it from: publish the post as it was rather
			// than lose it over a setting
			return $result;
		}

		return PeerTubeService::asVideo($result, $video, $this->getId());
	}
}
