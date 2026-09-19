<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Service\CheckService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether a Mastodon client can reach this instance at all.
 *
 * The routes of the client API live under `/index.php/apps/social`, but every
 * Mastodon app is given a domain and builds `https://<domain>/api/v1/...`
 * from it; not one of them takes a path prefix. So without a web-server rule
 * that maps the root paths onto the app, adding this instance to an app fails
 * at its first request, with nothing in the app's own log to show for it.
 *
 * Nextcloud only lets a handful of apps claim root URLs, and Social is not
 * among them, so this cannot be fixed in PHP here. The check exists because
 * the alternative is an administrator who believes the app is broken: it says
 * plainly which rules are missing and where to read them.
 */
class ClientApiAtRoot implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#mastodon-apps-cannot-connect';

	public function __construct(
		private IL10N $l10n,
		private CheckService $checkService,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'network';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Social: client API');
	}

	#[\Override]
	public function run(): SetupResult {
		if ($this->checkService->checkClientApiRoot()) {
			return SetupResult::success(
				$this->l10n->t('Mastodon apps can reach this instance.')
			);
		}

		return SetupResult::warning(
			$this->l10n->t(
				'Nothing answers at /api/v1/instance, so Mastodon apps cannot add an account here: '
				. 'they build their addresses from the domain alone and never use the '
				. '/index.php/apps/social prefix these routes live under. The web server has to map '
				. '/api, /oauth and /.well-known/host-meta onto the app. Everything else — the web '
				. 'interface and federation with other servers — works without this.'
			),
			self::DOC
		);
	}
}
