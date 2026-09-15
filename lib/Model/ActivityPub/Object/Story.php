<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * A story on the wire: one picture that stops existing after a day.
 *
 * Pixelfed's object, and Pixelfed's verbs around it — a story is published as
 * an `Add` addressed to the author's followers and withdrawn with a `Delete`,
 * which is what its inbox handles. It is not a `Note`: a story is not in
 * anybody's timeline, has no replies of its own here, and is gone tomorrow,
 * and a Mastodon-family server that does not know the type will ignore it,
 * which is the right outcome rather than a post nobody asked for.
 *
 * `expiresAt` is the whole contract. A receiver that keeps the object past it
 * is keeping something the author published on the understanding that it goes
 * — so this app stores the date it is given, refuses one that is absent or
 * already past, and holds nothing longer than a day whatever the sender says.
 */
class Story extends ACore implements JsonSerializable {
	public const TYPE = 'Story';

	/** No story is held longer than this, whatever `expiresAt` claims. */
	public const MAX_LIFETIME = 24 * 3600;

	private string $attributedTo = '';
	private string $caption = '';
	private int $duration = 5;
	private int $expiresAt = 0;
	private ?Document $attachment = null;

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/** Whose story it is. On `ACore` this lives only on a Stream, and a story is not one. */
	public function getAttributedTo(): string {
		return $this->attributedTo;
	}

	public function setAttributedTo(string $attributedTo): self {
		$this->attributedTo = $attributedTo;

		return $this;
	}

	public function getCaption(): string {
		return $this->caption;
	}

	public function setCaption(string $caption): self {
		$this->caption = $caption;

		return $this;
	}

	public function getDuration(): int {
		return $this->duration;
	}

	public function setDuration(int $duration): self {
		$this->duration = $duration;

		return $this;
	}

	public function getExpiresAt(): int {
		return $this->expiresAt;
	}

	public function setExpiresAt(int $expiresAt): self {
		$this->expiresAt = $expiresAt;

		return $this;
	}

	public function getAttachment(): ?Document {
		return $this->attachment;
	}

	public function setAttachment(?Document $attachment): self {
		$this->attachment = $attachment;

		return $this;
	}

	#[\Override]
	public function import(array $data): void {
		parent::import($data);

		$this->setAttributedTo($this->validate(ACore::AS_URL, 'attributedTo', $data, ''));
		$this->setCaption($this->validate(ACore::AS_CONTENT, 'content', $data, ''));
		$this->setDuration($this->getInt('duration', $data, 5));

		$expires = strtotime($this->get('expiresAt', $data, ''));
		$this->setExpiresAt(($expires === false) ? 0 : $expires);

		// one picture: a story is one thing seen for a few seconds, and a
		// sender that puts several in the array is sending something this app
		// has no way to show
		$attachments = $this->getArray('attachment', $data, []);
		$first = $attachments[0] ?? ($attachments === [] ? null : $attachments);
		if (is_array($first) && ($first['url'] ?? '') !== '') {
			$document = new Document();
			// a remote file is identified by where it lives: Pixelfed's
			// attachment carries a url and no id of its own, and an id this
			// app minted would name a document on this server that is not here
			$document->setId($this->validate(ACore::AS_URL, 'id', $first, '')
				?: $this->validate(ACore::AS_URL, 'url', $first, ''));
			$document->setUrl($this->validate(ACore::AS_URL, 'url', $first, ''));
			$document->setMediaType($this->validate(ACore::AS_STRING, 'mediaType', $first, ''));
			$document->setMimeType($document->getMediaType());
			$document->setDescription($this->validate(ACore::AS_STRING, 'name', $first, ''));
			$document->setParentId($this->getId());
			$this->setAttachment($document);
		}
	}

	#[\Override]
	public function jsonSerialize(): array {
		$attachment = $this->getAttachment();

		return array_filter(
			array_merge(
				parent::jsonSerialize(),
				[
					'attributedTo' => $this->getAttributedTo(),
					'content' => $this->getCaption(),
					'duration' => $this->getDuration(),
					'expiresAt' => ($this->getExpiresAt() > 0)
						? gmdate('Y-m-d\TH:i:s\Z', $this->getExpiresAt()) : '',
					'attachment' => ($attachment === null) ? [] : [[
						'type' => Document::TYPE,
						'mediaType' => $attachment->getMediaType(),
						'url' => $attachment->getUrl(),
						'name' => ($attachment->getDescription() === '') ? null : $attachment->getDescription(),
					]],
				]
			),
			static fn ($value): bool => $value !== '' && $value !== []
		);
	}
}
