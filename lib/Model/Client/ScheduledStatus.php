<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Mastodon's ScheduledStatus entity: a post that has been accepted but not
 * published, and the request that will be replayed when it is.
 *
 * This is what `POST /api/v1/statuses` answers when the client sent
 * `scheduled_at`. A client tells the two entities apart by their keys — a
 * ScheduledStatus has `params` and no `content`, `account` or `created_at` —
 * so returning a Status there, which is what this app used to do, is not a
 * near miss: Tusky and Ivory decode the answer into the wrong type and the
 * post the user was told was scheduled is already public.
 *
 * `params` is kept as the client's own request rather than as typed fields.
 * Mastodon defines it as "the parameters that were used when scheduling the
 * status, to be used when the status is posted", its members are Mastodon's
 * and change with Mastodon's, and nothing on this side ever queries inside it.
 * `paramText()` and friends below are the only readers, and they are the job's.
 *
 * `actorId` is not part of the entity a client sees. It is carried so the one
 * row a request names can be checked against the account that asked before
 * anything is done with it — see ScheduledStatusesRequest, where that check is
 * a SQL predicate rather than a comparison made after the row was read.
 */
class ScheduledStatus implements JsonSerializable {
	use TArrayTools;

	/**
	 * The keys `params` carries, in Mastodon's order.
	 *
	 * Every one of them is sent on every entity, `null` where the client said
	 * nothing: a client that declares the member non-optional cannot decode an
	 * entity that leaves it out, and "the client did not ask for a poll" is an
	 * answer a scheduling UI draws.
	 */
	private const PARAM_KEYS = [
		'text', 'media_ids', 'poll', 'in_reply_to_id', 'quoted_status_id',
		'sensitive', 'spoiler_text', 'visibility', 'language',
		'application_id', 'idempotency', 'scheduled_at', 'with_rate_limit',
	];

	private int $id = 0;
	private string $actorId = '';
	private int $scheduledAt = 0;
	/** @var array<string, mixed> */
	private array $params = [];
	/** @var MediaAttachment[] */
	private array $mediaAttachments = [];
	private int $creation = 0;

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

	public function setScheduledAt(int $scheduledAt): self {
		$this->scheduledAt = $scheduledAt;

		return $this;
	}

	public function getScheduledAt(): int {
		return $this->scheduledAt;
	}

	/**
	 * Anything outside Mastodon's set is dropped rather than stored: `params`
	 * is echoed back to whoever asks for the entity, so a client that could
	 * put arbitrary keys in it would have a place to park arbitrary content
	 * under this account's name.
	 *
	 * @param array<string, mixed> $params
	 */
	public function setParams(array $params): self {
		$kept = [];
		foreach (self::PARAM_KEYS as $key) {
			$kept[$key] = $params[$key] ?? null;
		}

		$this->params = $kept;

		return $this;
	}

	/** @return array<string, mixed> */
	public function getParams(): array {
		return $this->params;
	}

	public function paramText(): string {
		$text = $this->params['text'] ?? '';

		return is_scalar($text) ? (string)$text : '';
	}

	public function paramString(string $key): string {
		$value = $this->params[$key] ?? '';

		return is_scalar($value) ? (string)$value : '';
	}

	public function paramBool(string $key): bool {
		return ($this->params[$key] ?? false) === true;
	}

	public function paramPoll(): ?array {
		$poll = $this->params['poll'] ?? null;

		return (is_array($poll) && $poll !== []) ? $poll : null;
	}

	/**
	 * The attachments the post will carry, as the numeric media ids this app
	 * uses. Held as strings in `params`, because that is how Mastodon holds
	 * every id a client sees, and because the entity is echoed verbatim.
	 *
	 * @return int[]
	 */
	public function paramMediaIds(): array {
		$ids = [];
		foreach ((array)($this->params['media_ids'] ?? []) as $id) {
			if (is_scalar($id) && (int)$id > 0) {
				$ids[] = (int)$id;
			}
		}

		return $ids;
	}

	/** @param MediaAttachment[] $mediaAttachments */
	public function setMediaAttachments(array $mediaAttachments): self {
		$this->mediaAttachments = $mediaAttachments;

		return $this;
	}

	/** @return MediaAttachment[] */
	public function getMediaAttachments(): array {
		return $this->mediaAttachments;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/** @param array<string, mixed> $data a row of `social_scheduled` */
	public function importFromDatabase(array $data): self {
		$scheduledAt = $this->get('scheduled_at', $data);
		$creation = $this->get('creation', $data);
		$params = json_decode($this->get('params', $data), true);

		$this->setId($this->getInt('id', $data))
			->setActorId($this->get('actor_id', $data))
			->setScheduledAt(($scheduledAt === '') ? 0 : (int)strtotime($scheduledAt))
			->setParams(is_array($params) ? $params : [])
			->setCreation(($creation === '') ? 0 : (int)strtotime($creation));

		return $this;
	}

	/** The `params` column as it is written. */
	public function exportParams(): string {
		return (string)json_encode($this->getParams());
	}

	/**
	 * Exactly Mastodon's four keys and nothing else.
	 *
	 * `media_attachments` is the same `MediaAttachment` entity a published
	 * status carries, so a client draws the thumbnails of a waiting post with
	 * the code it already has — and `params.media_ids` alone would make it
	 * fetch each one.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'scheduled_at' => gmdate('Y-m-d\TH:i:s', $this->getScheduledAt()) . '.000Z',
			'params' => $this->getParams(),
			'media_attachments' => $this->getMediaAttachments(),
		];
	}
}
