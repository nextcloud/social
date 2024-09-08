<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class Post
 *
 * @package OCA\Social\Model
 */
class Post implements JsonSerializable {
	use TArrayTools;

	private Person $actor;
	private array $to = [];
	private string $replyTo = '';
	private string $content = '';
	private string $type = '';
	private array $hashtags = [];

	/** @var string[] */
	private array $attachments = [];
	/** @var MediaAttachment[] */
	private array $medias = [];

	/** @var Document[] */
	private array $documents = [];

	/**
	 * Post constructor.
	 *
	 * @param Person $actor
	 */
	public function __construct(Person $actor) {
		$this->actor = $actor;
	}

	/**
	 * @return Person
	 */
	public function getActor(): Person {
		return $this->actor;
	}


	/**
	 * @param string $to
	 *
	 * @return Post
	 */
	public function addTo(string $to): Post {
		$to = trim($to);
		if ($to !== '' && !in_array($to, $this->to)) {
			$this->to[] = $to;
		}

		return $this;
	}

	/**
	 * @return array
	 */
	public function getTo(): array {
		return $this->to;
	}

	/**
	 * @param array $to
	 *
	 * @return Post
	 */
	public function setTo(array $to): Post {
		$this->to = $to;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getReplyTo(): string {
		return $this->replyTo;
	}

	/**
	 * @param string $replyTo
	 *
	 * @return Post
	 */
	public function setReplyTo(string $replyTo): Post {
		$this->replyTo = $replyTo;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @param string $type
	 *
	 * @return Post
	 */
	public function setType(string $type): Post {
		$this->type = $type;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getHashtags(): array {
		return $this->hashtags;
	}

	/**
	 * @param array $hashtags
	 *
	 * @return Post
	 */
	public function setHashtags(array $hashtags): Post {
		$this->hashtags = $hashtags;

		return $this;
	}

	public function addHashtag(string $hashtag): Post {
		$hashtag = trim($hashtag);
		if ($hashtag !== '' && !in_array($hashtag, $this->hashtags)) {
			$this->hashtags[] = $hashtag;
		}

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getAttachments(): array {
		return $this->attachments;
	}

	/**
	 * @param string[] $attachments
	 *
	 * @return self
	 */
	public function setAttachments(array $attachments): self {
		$this->attachments = $attachments;

		return $this;
	}

	/**
	 * @param MediaAttachment[] $medias
	 */
	public function setMedias(array $medias): self {
		$this->medias = $medias;

		return $this;
	}

	/**
	 * @return MediaAttachment[]
	 */
	public function getMedias(): array {
		return $this->medias;
	}


	/**
	 * @return Document[]
	 */
	public function getDocuments(): array {
		return $this->documents;
	}

	/**
	 * @param Document[] $documents
	 *
	 * @return Post
	 */
	public function setDocuments(array $documents): Post {
		$this->documents = $documents;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}

	/**
	 * @param string $content
	 */
	public function setContent(string $content) {
		$this->content = $content;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'actor' => $this->getActor(),
			'to' => $this->getTo(),
			'replyTo' => $this->getReplyTo(),
			'content' => $this->getContent(),
			'attachments' => $this->getAttachments(),
			'hashtags' => $this->getHashtags(),
			'type' => $this->getType()
		];
	}
}
