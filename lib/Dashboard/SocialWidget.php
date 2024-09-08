<?php
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use OCA\Social\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;

class SocialWidget implements IWidget {
	private IL10N $l10n;
	private IURLGenerator $urlGenerator;

	public function __construct(
		IL10N $l10n,
		IURLGenerator $urlGenerator
	) {
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'social_notifications';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return $this->l10n->t('Social notifications');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconClass(): string {
		return 'icon-social';
	}

	/**
	 * @inheritDoc
	 */
	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRoute('social.local.streamNotifications', []);
	}

	/**
	 * @inheritDoc
	 */
	public function load(): void {
		\OCP\Util::addScript(Application::APP_ID, 'social-dashboard');
		\OCP\Util::addStyle(Application::APP_ID, 'dashboard');
	}
}
