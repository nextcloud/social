<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\StreamAction;

/**
 * An ActivityPub poll, the way Mastodon federates it: a Note-like object
 * whose options live in `oneOf` (single choice) or `anyOf` (multiple), each
 * option carrying its vote count in `replies.totalItems`.
 *
 * Everything a poll needs survives the database through the stored wire
 * source, like emoji and remote counters do; a remote `Update{Question}`
 * refreshes the source and with it the counts.
 */
class Question extends Note implements JsonSerializable {
	public const TYPE = 'Question';

	/** @var array[] [['title' => string, 'votes_count' => int], …] */
	private array $options = [];
	private bool $multiple = false;
	private string $endTime = '';
	private string $closed = '';
	private int $votersCount = 0;

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * @return array[]
	 */
	public function getOptions(): array {
		return $this->options;
	}

	public function isMultiple(): bool {
		return $this->multiple;
	}

	public function getEndTime(): string {
		return $this->endTime;
	}

	public function isExpired(): bool {
		if ($this->closed !== '') {
			return true;
		}
		if ($this->endTime === '') {
			return false;
		}

		return strtotime($this->endTime) < time();
	}

	public function getVotersCount(): int {
		return $this->votersCount;
	}

	public function import(array $data): void {
		parent::import($data);

		$this->parsePollData($data);
	}

	public function importFromDatabase(array $data): void {
		parent::importFromDatabase($data);

		$source = json_decode($this->getSource(), true);
		if (is_array($source)) {
			$this->parsePollData($source);
		}
	}

	private function parsePollData(array $data): void {
		$options = $this->getArray('oneOf', $data, []);
		$this->multiple = false;
		if ($options === []) {
			$options = $this->getArray('anyOf', $data, []);
			$this->multiple = ($options !== []);
		}

		$this->options = [];
		foreach ($options as $option) {
			if (!is_array($option) || (string)($option['name'] ?? '') === '') {
				continue;
			}
			$this->options[] = [
				'title' => (string)$option['name'],
				'votes_count' => (int)($option['replies']['totalItems'] ?? 0),
			];
		}

		$this->endTime = $this->get('endTime', $data, '');
		$this->closed = $this->get('closed', $data, '');
		$this->votersCount = $this->getInt('votersCount', $data, 0);
	}

	/**
	 * The Mastodon Poll entity, carried in the status as `poll`. The viewer's
	 * own votes come from the per-viewer stream action (`poll_votes`).
	 */
	public function exportAsLocal(): array {
		$result = parent::exportAsLocal();
		if ($this->options === []) {
			return $result;
		}

		$ownVotes = [];
		if ($this->hasAction()) {
			$stored = $this->getAction()->getValues()[StreamAction::POLL_VOTES] ?? '';
			$decoded = is_string($stored) ? json_decode($stored, true) : $stored;
			if (is_array($decoded)) {
				$ownVotes = array_values(array_map('intval', $decoded));
			}
		}

		$result['poll'] = [
			'id' => (string)$this->getNid(),
			'expires_at' => ($this->endTime === '') ? null : $this->endTime,
			'expired' => $this->isExpired(),
			'multiple' => $this->multiple,
			'votes_count' => array_sum(array_column($this->options, 'votes_count')),
			'voters_count' => $this->votersCount,
			'options' => $this->options,
			'emojis' => $this->getEmojis(),
			'voted' => ($ownVotes !== []),
			'own_votes' => $ownVotes,
		];

		return $result;
	}
}
