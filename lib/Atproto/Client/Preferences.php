<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Service\AccountService;
use OCP\IConfig;

/**
 * A Bluesky app's preferences — saved feeds, content filters, muted words,
 * the threads view — which a PDS keeps for its accounts rather than the
 * AppView. Kept per person as the app wrote them, within Bluesky's own
 * namespace and a size limit — except the muted words, which are the
 * person's filters here (`MutedWords`), read from them and written to them.
 */
class Preferences {
	public const METHODS = ['app.bsky.actor.getPreferences', 'app.bsky.actor.putPreferences'];
	public const VERIFICATION = 'app.bsky.actor.defs#verificationPrefs';
	private const KEY = 'atproto_preferences';
	private const MAX_BYTES = 262144;

	public function __construct(
		private IConfig $config,
		private MutedWords $mutedWords,
		private AccountService $accounts,
	) {
	}

	public function get(ClientSession $session): array {
		$preferences = $this->storedFor($session->userId);
		$words = $this->mutedWords->pref($this->accounts->getActorFromUserId($session->userId));
		if ($words !== null) {
			$preferences[] = $words;
		}

		return ['preferences' => $preferences];
	}

	public function hidesVerificationBadges(string $userId): bool {
		foreach ($this->storedFor($userId) as $preference) {
			if (is_array($preference) && ($preference['$type'] ?? '') === self::VERIFICATION) {
				return ($preference['hideBadges'] ?? false) === true;
			}
		}

		return false;
	}

	public function setHideVerificationBadges(string $userId, bool $hide): void {
		$preferences = $this->storedFor($userId);
		$updated = false;
		foreach ($preferences as &$preference) {
			if (is_array($preference) && ($preference['$type'] ?? '') === self::VERIFICATION) {
				$preference['hideBadges'] = $hide;
				$updated = true;
			}
		}
		unset($preference);

		if (!$updated) {
			$preferences[] = ['$type' => self::VERIFICATION, 'hideBadges' => $hide];
		}
		$this->storeFor($userId, $preferences);
	}

	/** @return list<array> */
	private function storedFor(string $userId): array {
		$stored = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::KEY, '[]'), true);
		$preferences = array_values(array_filter(is_array($stored) ? $stored : [], static fn (mixed $preference): bool => !self::isMutedWords($preference)));

		return $preferences;
	}

	/** @param list<array> $preferences */
	private function storeFor(string $userId, array $preferences): void {
		$encoded = (string)json_encode($preferences, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY, $encoded);
	}

	/**
	 * @throws XrpcException
	 */
	public function put(ClientSession $session, array $body): array {
		$preferences = $body['preferences'] ?? null;
		if (!is_array($preferences) || !array_is_list($preferences)) {
			throw XrpcException::invalidRequest('preferences must be a list');
		}
		foreach ($preferences as $preference) {
			if (!is_array($preference) || !is_string($preference['$type'] ?? null) || !str_starts_with($preference['$type'], 'app.bsky.')) {
				throw XrpcException::invalidRequest('Some preferences are not in the app.bsky namespace');
			}
		}
		$words = null;
		foreach ($preferences as $preference) {
			if (self::isMutedWords($preference)) {
				$words = $preference;
			}
		}
		$preferences = array_values(array_filter($preferences, static fn (array $preference): bool => !self::isMutedWords($preference)));
		$encoded = (string)json_encode($preferences, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (strlen($encoded) > self::MAX_BYTES) {
			throw new XrpcException(413, 'PayloadTooLarge', 'Preferences too large');
		}
		$this->storeFor($session->userId, $preferences);
		if ($words !== null) {
			$this->mutedWords->apply($this->accounts->getActorFromUserId($session->userId), $words);
		}

		return [];
	}

	private static function isMutedWords(mixed $preference): bool {
		return is_array($preference) && ($preference['$type'] ?? '') === MutedWords::PREF;
	}
}
