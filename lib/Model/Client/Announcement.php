<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * Mastodon's Announcement entity: the instance-wide notice an admin posts and
 * every account is shown once.
 *
 * What is stored is the text the admin typed; `content` is HTML, so the text
 * is escaped and its line breaks turned into markup when the entity is built.
 * A client renders `content` as HTML, so a stray `<` in a notice about an XML
 * config file must not become a tag.
 *
 * `mentions`, `statuses`, `tags` and `emojis` are always empty here — an
 * announcement is plain text and nothing is parsed out of it — but all four
 * are sent, because Mastodon documents them as non-optional and a client that
 * declares them so cannot decode the entity without them. `read` is the one
 * Mastodon marks optional, and it is always sent too: every reader of this
 * entity is an authenticated account, which is the condition Mastodon sends it
 * under.
 *
 * `reactions` was in that list until there was a route to write one. It is the
 * only thing an account can say back about an instance-wide notice, and
 * without it the only thing anybody could do with one was put it away.
 *
 * `startsAt`/`endsAt` are timestamps and `0` means "no such bound", not 1970:
 * `isActiveAt()` is what every reader compares with, so the two cannot be
 * confused. Mastodon treats the pair as display metadata beside a separate
 * `published` flag; here the pair is what decides whether the announcement is
 * served at all — see AnnouncementsRequest.
 */
class Announcement implements JsonSerializable {
	/**
	 * The longest notice an admin may post.
	 *
	 * `social_announcement.content` is a TEXT column, which holds 64 KiB on
	 * MySQL; the ceiling is here so that a paste that would not fit is refused
	 * whole rather than stored cut in half.
	 */
	public const MAX_TEXT = 10000;

	private int $id = 0;
	private string $text = '';
	private int $startsAt = 0;
	private int $endsAt = 0;
	private bool $allDay = false;
	private int $publishedAt = 0;
	private int $updatedAt = 0;
	private bool $read = false;
	/** @var array<string, array{count: int, me: bool}> emoji => count and ours */
	private array $reactions = [];

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	/** The announcement as the admin typed it: plain text, never HTML. */
	public function setText(string $text): self {
		$this->text = $text;

		return $this;
	}

	public function getText(): string {
		return $this->text;
	}

	public function setStartsAt(int $startsAt): self {
		$this->startsAt = $startsAt;

		return $this;
	}

	public function getStartsAt(): int {
		return $this->startsAt;
	}

	public function setEndsAt(int $endsAt): self {
		$this->endsAt = $endsAt;

		return $this;
	}

	public function getEndsAt(): int {
		return $this->endsAt;
	}

	public function setAllDay(bool $allDay): self {
		$this->allDay = $allDay;

		return $this;
	}

	public function isAllDay(): bool {
		return $this->allDay;
	}

	public function setPublishedAt(int $publishedAt): self {
		$this->publishedAt = $publishedAt;

		return $this;
	}

	public function getPublishedAt(): int {
		return $this->publishedAt;
	}

	public function setUpdatedAt(int $updatedAt): self {
		$this->updatedAt = $updatedAt;

		return $this;
	}

	public function getUpdatedAt(): int {
		return $this->updatedAt;
	}

	/** Whether the account being answered has dismissed this announcement. */
	public function setRead(bool $read): self {
		$this->read = $read;

		return $this;
	}

	public function isRead(): bool {
		return $this->read;
	}

	/** Mastodon requires both bounds or neither, so one of them is the pair. */
	public function hasRange(): bool {
		return $this->startsAt > 0 && $this->endsAt > 0;
	}

	/**
	 * Whether the announcement applies at that moment.
	 *
	 * An announcement with no bounds applies for good; one with a range
	 * applies from its start up to but not including its end, so an
	 * announcement ending at noon and one starting at noon do not both show at
	 * noon. Nothing runs to make this happen: an expiry a cron has to act on
	 * keeps being shown on an instance whose cron is broken, which is where a
	 * stale notice does the most harm.
	 */
	public function isActiveAt(?int $now = null): bool {
		$now ??= time();

		return ($this->startsAt === 0 || $this->startsAt <= $now)
			&& ($this->endsAt === 0 || $this->endsAt > $now);
	}

	/** @param array<string, mixed> $data a row of `social_announcement` */
	public function importFromDatabase(array $data): self {
		$this->setId((int)($data['id'] ?? 0))
			->setText((string)($data['content'] ?? ''))
			->setStartsAt($this->timestamp($data['starts_at'] ?? null))
			->setEndsAt($this->timestamp($data['ends_at'] ?? null))
			->setAllDay((int)($data['all_day'] ?? 0) === 1)
			->setPublishedAt($this->timestamp($data['creation'] ?? null))
			->setUpdatedAt($this->timestamp($data['last_update'] ?? null));

		return $this;
	}

	/**
	 * The text as the `content` of the entity: HTML, one paragraph per blank
	 * line, single line breaks kept as breaks, and everything the admin typed
	 * escaped.
	 */
	public function getContent(): string {
		$text = str_replace("\r\n", "\n", $this->text);

		$paragraphs = [];
		foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph === '') {
				continue;
			}

			$paragraphs[] = '<p>' . str_replace(
				"\n",
				'<br />',
				htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			) . '</p>';
		}

		return implode('', $paragraphs);
	}

	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'content' => $this->getContent(),
			'starts_at' => self::datetime($this->getStartsAt()),
			'ends_at' => self::datetime($this->getEndsAt()),
			// Mastodon: "false if no start/end times exist", so the flag
			// cannot claim a whole-day range that is not there
			'all_day' => $this->hasRange() && $this->isAllDay(),
			'published_at' => (string)self::datetime($this->getPublishedAt()),
			// Mastodon documents updated_at as non-nullable, and an
			// announcement nothing has happened to was last changed when it
			// was written
			'updated_at' => (string)self::datetime($this->getUpdatedAt() ?: $this->getPublishedAt()),
			'read' => $this->isRead(),
			'mentions' => [],
			'statuses' => [],
			'tags' => [],
			'emojis' => [],
			'reactions' => $this->exportReactions(),
		];
	}

	/**
	 * @param array<string, array{count: int, me: bool}> $reactions
	 */
	public function setReactions(array $reactions): self {
		$this->reactions = $reactions;

		return $this;
	}

	/** @return array<string, array{count: int, me: bool}> */
	public function getReactions(): array {
		return $this->reactions;
	}

	/**
	 * Mastodon's `Reaction`, most-reacted first and alphabetical within a tie,
	 * so a client redrawing the same announcement does not reshuffle it.
	 *
	 * `url` and `static_url` belong to a custom emoji and are absent for a
	 * Unicode one — Mastodon omits them rather than sending null, and a client
	 * reads their presence as "this is a picture, not a character". This app
	 * fills them in from what it publishes, in AnnouncementService.
	 *
	 * @return array[]
	 */
	private function exportReactions(): array {
		$names = array_keys($this->reactions);
		usort($names, fn (string $a, string $b): int
			=> [$this->reactions[$b]['count'], $a] <=> [$this->reactions[$a]['count'], $b]);

		$reactions = [];
		foreach ($names as $name) {
			$reaction = [
				'name' => $name,
				'count' => $this->reactions[$name]['count'],
				'me' => $this->reactions[$name]['me'],
			];
			if (($this->reactions[$name]['url'] ?? '') !== '') {
				$reaction['url'] = $this->reactions[$name]['url'];
				$reaction['static_url'] = $this->reactions[$name]['url'];
			}
			$reactions[] = $reaction;
		}

		return $reactions;
	}

	/** The datetime format every Mastodon entity in this app is dated with. */
	public static function datetime(int $timestamp): ?string {
		if ($timestamp === 0) {
			return null;
		}

		return gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z';
	}

	/** A date column as this model holds one; an empty column is `0`. */
	private function timestamp(mixed $value): int {
		if (!is_string($value) || $value === '') {
			return 0;
		}

		return (int)strtotime($value);
	}
}
