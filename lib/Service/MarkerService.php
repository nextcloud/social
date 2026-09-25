<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Tools\Nid;
use OCP\Config\IUserConfig;

/**
 * How far through a timeline someone has read.
 *
 * Mastodon calls these markers and every client keeps them per timeline, so
 * the position follows a reader between the web app and their phone. The only
 * thing the app itself needs one for is the unread badge — but a marker is the
 * shape the client API already asks for, so it is the shape stored.
 *
 * A per-user config value rather than a table: there is one small row per
 * person, it is written when a timeline is opened and read when the badge is
 * drawn, and none of that is worth a migration.
 */
class MarkerService {
	/** The timelines a marker may be kept for, as the client API names them. */
	public const TIMELINES = ['home', 'notifications'];

	private const CONFIG_KEY = 'markers';

	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	/**
	 * @return array<string, array{last_read_id: string, version: int, updated_at: string}>
	 */
	public function getAll(string $userId): array {
		$stored = json_decode(
			$this->userConfig->getValueString($userId, Application::APP_ID, self::CONFIG_KEY, '{}'),
			true
		);

		return is_array($stored) ? $stored : [];
	}

	/**
	 * @param string[] $timelines which markers to answer with; all of them when empty
	 *
	 * @return array<string, array{last_read_id: string, version: int, updated_at: string}>
	 */
	public function get(string $userId, array $timelines = []): array {
		$markers = $this->getAll($userId);
		if ($timelines === []) {
			return $markers;
		}

		return array_intersect_key($markers, array_flip($timelines));
	}

	/**
	 * The last read position as a nid, which is what a comparison against a
	 * stream's nid needs — as its decimal string, because a nid does not fit
	 * a PHP int everywhere. An unread timeline, or a marker from a client that
	 * stored something that is not a nid, reads as zero — everything unread.
	 */
	public function lastReadId(string $userId, string $timeline): string {
		$markers = $this->getAll($userId);

		return $this->nid($markers[$timeline]['last_read_id'] ?? '0') ?? '0';
	}

	/** A stored or submitted position as a normalised nid, null if it is not one. */
	private function nid(mixed $id): ?string {
		if (!is_string($id) && !is_int($id)) {
			return null;
		}

		return ctype_digit((string)$id) ? Nid::normalize($id) : null;
	}

	/**
	 * Moves a marker forward.
	 *
	 * Never backwards: two clients reading the same account report their own
	 * positions, and the one that is further behind must not un-read what the
	 * other has already seen.
	 *
	 * @return array{last_read_id: string, version: int, updated_at: string}
	 */
	public function set(string $userId, string $timeline, string $lastReadId): array {
		$markers = $this->getAll($userId);
		$current = $markers[$timeline] ?? null;

		$was = $this->nid($current['last_read_id'] ?? null);
		$now = $this->nid($lastReadId);
		if ($current !== null && $was !== null && $now !== null && Nid::compare($was, $now) >= 0) {
			return $current;
		}

		$marker = [
			'last_read_id' => $lastReadId,
			'version' => (int)($current['version'] ?? 0) + 1,
			'updated_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
		];

		$markers[$timeline] = $marker;
		$this->userConfig->setValueString(
			$userId, Application::APP_ID, self::CONFIG_KEY, (string)json_encode($markers)
		);

		return $marker;
	}
}
