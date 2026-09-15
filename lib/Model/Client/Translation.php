<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * Mastodon's Translation entity: what one status says, in another language.
 *
 * It is deliberately not a Status. A translation has no id, no author, no
 * counters and no date — putting the translated words into a Status entity
 * would hand the client something it could store in a timeline, and the next
 * refresh would replace it with the original with no sign that anything had
 * happened. Mastodon returns this narrow shape for exactly that reason, and a
 * client shows it under the post rather than instead of it.
 *
 * `detected_source_language` is what the post itself declared where it
 * declared anything: this app stores a status language from `contentMap`, and
 * a value that came off the wire is a better answer than a guess made here.
 */
class Translation implements JsonSerializable {
	private string $content = '';
	private string $spoilerText = '';
	private string $detectedSourceLanguage = '';
	private string $provider = '';
	/** @var array<int, array{id: string, description: string}> */
	private array $mediaAttachments = [];
	/** @var array<int, array{title: string}> */
	private array $pollOptions = [];
	private string $pollId = '';

	public function setContent(string $content): self {
		$this->content = $content;

		return $this;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function setSpoilerText(string $spoilerText): self {
		$this->spoilerText = $spoilerText;

		return $this;
	}

	public function getSpoilerText(): string {
		return $this->spoilerText;
	}

	public function setDetectedSourceLanguage(string $language): self {
		$this->detectedSourceLanguage = $language;

		return $this;
	}

	public function getDetectedSourceLanguage(): string {
		return $this->detectedSourceLanguage;
	}

	public function setProvider(string $provider): self {
		$this->provider = $provider;

		return $this;
	}

	public function getProvider(): string {
		return $this->provider;
	}

	/**
	 * @param array<int, array{id: string, description: string}> $mediaAttachments
	 */
	public function setMediaAttachments(array $mediaAttachments): self {
		$this->mediaAttachments = $mediaAttachments;

		return $this;
	}

	/**
	 * @param array<int, array{title: string}> $options
	 */
	public function setPoll(string $id, array $options): self {
		$this->pollId = $id;
		$this->pollOptions = $options;

		return $this;
	}

	/**
	 * The keys Mastodon defines, all of them, every time.
	 *
	 * `media_attachments` and `poll` are lists a client iterates rather than
	 * tests for, so they are always present and empty when there is nothing to
	 * translate; `poll` is null, because a client reads an object there.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'content' => $this->content,
			'spoiler_text' => $this->spoilerText,
			'media_attachments' => $this->mediaAttachments,
			'poll' => ($this->pollId === '')
				? null
				: ['id' => $this->pollId, 'options' => $this->pollOptions],
			'detected_source_language' => $this->detectedSourceLanguage,
			'provider' => $this->provider,
		];
	}
}
