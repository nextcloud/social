<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/** Per-reader choices about which posts appear as cards in the feeds. */
class FeedDisplayService {
	public const USER_KEY_HIDE_REPLIES = 'hide_replies_in_feeds';

	/** @var array<string, bool> user id => hide replies */
	private array $hideReplies = [];

	public function __construct(
		private ConfigService $configService,
	) {
	}

	/** Whether this reader hides replies as standalone feed cards. */
	public function hidesReplies(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		$this->hideReplies[$userId] ??= $this->configService->getUserValue(self::USER_KEY_HIDE_REPLIES, $userId) === '1';

		return $this->hideReplies[$userId];
	}

	public function setHidesReplies(string $userId, bool $hide): void {
		$this->configService->setValueForUser($userId, self::USER_KEY_HIDE_REPLIES, $hide ? '1' : '0');
		$this->hideReplies[$userId] = $hide;
	}

	/** The switch as the client API answers it. */
	public function export(string $userId): array {
		return ['hide_replies' => $this->hidesReplies($userId)];
	}
}
