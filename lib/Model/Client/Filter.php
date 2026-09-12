<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * One keyword filter of one account, as Mastodon's v2 `Filter` entity.
 *
 * A filter is a title, the timelines it applies to, when it stops applying,
 * what to do with a status it matches, and the keywords that match one. The
 * matching itself is `FilterService`'s; this holds the row and the shape a
 * client is handed.
 *
 * `expiresAt` is a timestamp and `0` means "never", not "expired in 1970":
 * every read compares with `isActive()` rather than with the number, so the
 * two cannot be confused.
 */
class Filter implements JsonSerializable {
	/** The home timeline and any list. */
	public const CONTEXT_HOME = 'home';
	public const CONTEXT_NOTIFICATIONS = 'notifications';
	/** The public and hashtag timelines. */
	public const CONTEXT_PUBLIC = 'public';
	/** A status shown inside a conversation. */
	public const CONTEXT_THREAD = 'thread';
	/** A profile's own statuses. */
	public const CONTEXT_ACCOUNT = 'account';

	/** @var string[] in the order Mastodon documents them */
	public const CONTEXTS = [
		self::CONTEXT_HOME,
		self::CONTEXT_NOTIFICATIONS,
		self::CONTEXT_PUBLIC,
		self::CONTEXT_THREAD,
		self::CONTEXT_ACCOUNT,
	];

	/** The status is sent with `filtered`, and the client blurs it. */
	public const ACTION_WARN = 'warn';
	/** The status is not sent at all. */
	public const ACTION_HIDE = 'hide';

	/** @var string[] */
	public const ACTIONS = [self::ACTION_WARN, self::ACTION_HIDE];

	/** The width of `social_filter.title`. */
	public const MAX_TITLE = 255;

	/**
	 * The width of `social_filter.contexts`, which holds the comma-joined
	 * list — far wider than the five names can ever need, and the check is
	 * here so a future context cannot silently be cut in half.
	 */
	public const MAX_CONTEXTS = 255;

	private int $id = 0;
	private string $actorId = '';
	private string $title = '';
	/** @var string[] */
	private array $contexts = [];
	private string $action = self::ACTION_WARN;
	/** Unix time; 0 is "never expires". */
	private int $expiresAt = 0;
	private int $creation = 0;
	/** @var FilterKeyword[] */
	private array $keywords = [];

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setTitle(string $title): self {
		$this->title = $title;

		return $this;
	}

	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * @param string[] $contexts
	 */
	public function setContexts(array $contexts): self {
		$this->contexts = $contexts;

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getContexts(): array {
		return $this->contexts;
	}

	public function setAction(string $action): self {
		$this->action = $action;

		return $this;
	}

	public function getAction(): string {
		return $this->action;
	}

	public function isHiding(): bool {
		return $this->action === self::ACTION_HIDE;
	}

	public function setExpiresAt(int $expiresAt): self {
		$this->expiresAt = $expiresAt;

		return $this;
	}

	public function getExpiresAt(): int {
		return $this->expiresAt;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/**
	 * @param FilterKeyword[] $keywords
	 */
	public function setKeywords(array $keywords): self {
		$this->keywords = array_values($keywords);

		return $this;
	}

	public function addKeyword(FilterKeyword $keyword): self {
		$this->keywords[] = $keyword;

		return $this;
	}

	/**
	 * @return FilterKeyword[]
	 */
	public function getKeywords(): array {
		return $this->keywords;
	}

	/**
	 * An expired filter stops applying on its own, with nothing run to make it
	 * happen: no cleanup job exists, and one that failed to run would otherwise
	 * silently keep hiding statuses the account expected back.
	 */
	public function isActive(?int $now = null): bool {
		return $this->expiresAt === 0 || $this->expiresAt > ($now ?? time());
	}

	public function appliesTo(string $context): bool {
		return in_array($context, $this->contexts, true);
	}

	/**
	 * The contexts of a filter as they are stored and compared: the names
	 * Mastodon defines, once each, in the order it lists them. Anything else a
	 * client sent is dropped — a context nothing reads would be a filter the
	 * account believes is applied somewhere it is not.
	 *
	 * @param array<mixed> $raw
	 *
	 * @return string[]
	 */
	public static function normaliseContexts(array $raw): array {
		$named = [];
		foreach ($raw as $context) {
			if (!is_string($context)) {
				continue;
			}

			$named[] = strtolower(trim($context));
		}

		return array_values(array_intersect(self::CONTEXTS, $named));
	}

	/**
	 * The action a client asked for, or '' for something that is not one — the
	 * caller answers 422 rather than storing a filter whose action nothing
	 * knows how to apply.
	 */
	public static function normaliseAction(string $raw): string {
		$action = strtolower(trim($raw));

		return in_array($action, self::ACTIONS, true) ? $action : '';
	}

	/**
	 * The title as it is stored: trimmed, and cut to the column's width in
	 * characters rather than bytes, so a multi-byte title is not cut through
	 * the middle of a character.
	 */
	public static function normaliseTitle(string $raw): string {
		return mb_substr(trim($raw), 0, self::MAX_TITLE, 'UTF-8');
	}

	/**
	 * The stored form of the context list, and back. Comma-joined: the five
	 * names carry no comma, the list is read whole and never searched by SQL,
	 * and a reader of the row can see what it says.
	 */
	public function exportContexts(): string {
		return implode(',', $this->contexts);
	}

	public function importContexts(string $stored): self {
		return $this->setContexts(self::normaliseContexts(explode(',', $stored)));
	}

	/**
	 * The filter as it appears inside a status' `filtered` key: what the client
	 * needs to decide whether to blur and what to say it is blurring for.
	 * Mastodon leaves the keywords out there, and so does this.
	 */
	public function exportAsResultFilter(): array {
		return [
			'id' => (string)$this->id,
			'title' => $this->title,
			'context' => $this->contexts,
			'expires_at' => $this->exportExpiresAt(),
			'filter_action' => $this->action,
		];
	}

	/**
	 * `statuses` is always empty: this app has no per-status filters, and the
	 * key is not optional for a client that declares it.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$keywords = [];
		foreach ($this->keywords as $keyword) {
			$keywords[] = $keyword->jsonSerialize();
		}

		return array_merge(
			$this->exportAsResultFilter(),
			[
				'keywords' => $keywords,
				'statuses' => [],
			]
		);
	}

	/** Null rather than a date, for a filter that never expires. */
	private function exportExpiresAt(): ?string {
		if ($this->expiresAt === 0) {
			return null;
		}

		return gmdate('Y-m-d\TH:i:s', $this->expiresAt) . '.000Z';
	}
}
