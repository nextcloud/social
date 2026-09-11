<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Settings;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\ReportService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Moderation panel: open (and recently resolved) reports, the state of outbound
 * federation, the Fediverse access list the occ social:fediverse command
 * manages, and the announcements the instance is showing everybody.
 *
 * The announcements section carries no data from here. It is read from
 * `/admin/announcements` when the page loads, because that is the same route
 * the section writes through, and a list rendered here would disagree with it
 * the moment an announcement was posted.
 */
class AdminSettings implements ISettings {
	public function __construct(
		private ReportService $reportService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private ModerationService $moderationService,
		private FederationHealthService $federationHealthService,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript('social', 'social-adminSettings');
		// its own bundle rather than a second panel in the hand-written one:
		// the announcements section reads and writes its own routes, and
		// nothing on the page above it is loaded any earlier for it
		Util::addScript('social', 'social-adminAnnouncements');

		return new TemplateResponse('social', 'settings/admin', [
			'reports' => $this->reportService->getReports(true),
			'accessType' => $this->fediverseService->getAccessType(),
			'accessList' => $this->fediverseService->getListedAddresses(),
			'retentionDays' => (int)$this->configService->getAppValue(ConfigService::SOCIAL_RETENTION_DAYS),
			'federation' => $this->federationHealthService->summary(),
			'moderation' => $this->currentDecisions(),
		]);
	}

	/**
	 * What stands against each account, keyed by actor id, so the reports
	 * table can show its own state rather than only what was complained about.
	 *
	 * @return array<string, string>
	 */
	private function currentDecisions(): array {
		$decisions = [];
		foreach ($this->moderationService->decisions() as $decision) {
			$decisions[$decision->getActorId()] = $decision->getLevel();
		}

		return $decisions;
	}

	public function getSection(): string {
		return 'social';
	}

	public function getPriority(): int {
		return 50;
	}
}
