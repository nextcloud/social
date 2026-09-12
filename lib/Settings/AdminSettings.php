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
use OCP\IL10N;
use OCP\Settings\IDelegatedSettings;
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
 *
 * Delegated rather than plain admin settings: moderating used to mean
 * administering the whole server, which is a great deal of power to hand
 * somebody so they can act on a report. An administrator can now pass this
 * section to a group under Administration privileges, and Nextcloud's own
 * machinery — `AuthorizedAdminSetting` on the routes below the page, and
 * `IManager::getAllowedAdminSettings()` on the Mastodon admin API — lets that
 * group act without letting it near anything else.
 */
class AdminSettings implements IDelegatedSettings {
	public function __construct(
		private ReportService $reportService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private ModerationService $moderationService,
		private FederationHealthService $federationHealthService,
		private IL10N $l10n,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript('social', 'social-adminSettings');
		// its own bundle rather than a second panel in the hand-written one:
		// the announcements section reads and writes its own routes, and
		// nothing on the page above it is loaded any earlier for it
		Util::addScript('social', 'social-adminAnnouncements');
		// and the account browser, which is the same page's other half: the
		// reports table is what somebody complained about, this is everything
		// else the instance knows
		Util::addScript('social', 'social-adminModeration');

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
		return AdminSection::SECTION_ID;
	}

	public function getPriority(): int {
		return 50;
	}

	/**
	 * The name this section is offered under in Administration privileges.
	 * The section has one panel, so it names what is being handed over.
	 */
	public function getName(): ?string {
		return $this->l10n->t('Moderation');
	}

	/**
	 * No app config is reachable through core's own settings API: everything
	 * this panel writes — the retention period, the access mode and list —
	 * goes through `ModerationController`, which is guarded by
	 * `AuthorizedAdminSetting` and validates what it is given. A delegate can
	 * change those and nothing else under `social`.
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
