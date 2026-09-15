<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * Mastodon's FilterStatus entity: one post a filter covers by name.
 *
 * The other half of a filter. A keyword catches whatever it matches, now and
 * in the future; a filter status catches one post and nothing else, which is
 * what a client's "filter this post" offers — a thread that has stopped being
 * worth reading, a picture somebody does not want to meet again.
 *
 * Its `id` is its own, not the post's: a client removes the entry with
 * `DELETE /api/v2/filters/statuses/{id}`, and the post keeps existing
 * afterwards.
 */
class FilterStatus implements JsonSerializable {
	private int $id = 0;
	private int $filterId = 0;
	private int $statusId = 0;

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setFilterId(int $filterId): self {
		$this->filterId = $filterId;

		return $this;
	}

	public function getFilterId(): int {
		return $this->filterId;
	}

	public function setStatusId(int $statusId): self {
		$this->statusId = $statusId;

		return $this;
	}

	public function getStatusId(): int {
		return $this->statusId;
	}

	/** Both ids are strings on the wire, as every id in this API is. */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->id,
			'status_id' => (string)$this->statusId,
		];
	}
}
