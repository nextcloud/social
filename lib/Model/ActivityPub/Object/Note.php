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

		$this->cleanArray($result);

		return $result;
	}
}
