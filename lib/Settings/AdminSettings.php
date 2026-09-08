<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Settings;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\ReportService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Moderation panel: open (and recently resolved) reports plus the Fediverse
 * access list the occ social:fediverse command manages.
 */
class AdminSettings implements ISettings {
	public function __construct(
		private ReportService $reportService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript('social', 'social-adminSettings');

		return new TemplateResponse('social', 'settings/admin', [
			'reports' => $this->reportService->getReports(true),
			'accessType' => $this->fediverseService->getAccessType(),
			'accessList' => $this->fediverseService->getListedAddresses(),
			'retentionDays' => (int)$this->configService->getAppValue(ConfigService::SOCIAL_RETENTION_DAYS),
		]);
	}

	public function getSection(): string {
		return 'social';
	}

	public function getPriority(): int {
		return 50;
	}
}
