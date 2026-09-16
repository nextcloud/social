<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCP\IConfig;

/**
 * What happens to a post somebody marked sensitive.
 *
 * PeerTube gives an instance three NSFW policies and lets a person override
 * the one their instance chose; this app had one, hard-coded, and no way to
 * say anything about it. The three are the same three, under Mastodon's names
 * rather than PeerTube's — because Mastodon already has a field for exactly
 * this (`reading:expand:media` on `/api/v1/preferences`), every client that
 * speaks this API already reads it, and inventing a fourth vocabulary for the
 * same three states would mean no client could act on it:
 *
 * - `show_all` — PeerTube's **display**. Sensitive media is drawn like any
 *   other; a content warning, which is a different thing, still covers its
 *   post.
 * - `default` — PeerTube's **blur**. The media is covered and its blurhash
 *   shows through it, one press away. What this app has always done, and the
 *   default here so that an upgrade changes nothing for anybody.
 * - `hide_all` — PeerTube's **hide**. The media is not drawn and there is no
 *   button to draw it: opening the post itself is what it takes.
 *
 * An account that has expressed no preference follows the instance, and an
 * instance that has expressed none is `default`. An account's own choice is
 * stored against its Nextcloud user rather than its actor, because it is a
 * fact about a person reading and not about an identity other servers see.
 */
class SensitiveMediaService {
	public const SHOW_ALL = 'show_all';
	public const COVERED = 'default';
	public const HIDE_ALL = 'hide_all';

	public const POLICIES = [self::SHOW_ALL, self::COVERED, self::HIDE_ALL];

	/** What the account's row says when it has not chosen. */
	public const FOLLOW_INSTANCE = '';

	/** The Nextcloud user-config key an account's own choice is kept under. */
	public const USER_KEY = 'nsfw_policy';

	public function __construct(
		private ConfigService $configService,
		private IConfig $config,
	) {
	}

	/** What this instance does for somebody who has not chosen. */
	public function instancePolicy(): string {
		return $this->clean(
			(string)$this->configService->getAppValue(ConfigService::SOCIAL_NSFW_POLICY)
		);
	}

	/**
	 * What to do for this reader.
	 *
	 * @param string $userId the Nextcloud user, or '' for nobody signed in
	 */
	public function policyFor(string $userId): string {
		if ($userId === '') {
			return $this->instancePolicy();
		}

		$own = (string)$this->config->getUserValue(
			$userId, Application::APP_ID, self::USER_KEY, self::FOLLOW_INSTANCE
		);

		return ($own === self::FOLLOW_INSTANCE) ? $this->instancePolicy() : $this->clean($own);
	}

	/**
	 * What this account has chosen, as it chose it — `''` where it has not.
	 *
	 * Distinct from `policyFor()` on purpose: a settings page has to be able to
	 * show "follow the instance" as the state it is, rather than as whichever
	 * policy that currently resolves to. The two look the same until an
	 * administrator changes the instance default, at which point a page that
	 * could not tell them apart would have silently pinned everybody to the
	 * old one.
	 */
	public function choiceOf(string $userId): string {
		if ($userId === '') {
			return self::FOLLOW_INSTANCE;
		}

		$own = (string)$this->config->getUserValue(
			$userId, Application::APP_ID, self::USER_KEY, self::FOLLOW_INSTANCE
		);

		return in_array($own, self::POLICIES, true) ? $own : self::FOLLOW_INSTANCE;
	}

	/**
	 * Records what an account chose. `''` puts it back to following the
	 * instance, which is not the same as choosing what the instance currently
	 * does.
	 *
	 * @return bool whether the value was one of the four things it may be
	 */
	public function choose(string $userId, string $policy): bool {
		if ($userId === '') {
			return false;
		}

		if ($policy !== self::FOLLOW_INSTANCE && !in_array($policy, self::POLICIES, true)) {
			return false;
		}

		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY, $policy);

		return true;
	}

	/** Anything that is not one of the three is `default`. */
	private function clean(string $policy): string {
		return in_array($policy, self::POLICIES, true) ? $policy : self::COVERED;
	}
}
