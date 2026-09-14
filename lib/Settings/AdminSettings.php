<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Settings;

use OCA\Social\Model\Report;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\ServerSettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Moderation panel: the open reports (the resolved ones a click away), the
 * state of outbound federation, the Fediverse access list the occ
 * social:fediverse command manages, the announcements the instance is showing
 * everybody — and, for an administrator proper, the Server card.
 *
 * The page is one Vue application (`src/adminSettings.js`); the template is
 * the element it mounts on and nothing else, and what is known at render time
 * travels as initial state.
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
 *
 * The Server card is the exception, and is not rendered for a delegate: what
 * it holds (whether unsigned fetches are answered, how large an upload may
 * be, who to write to about the instance) is administration, not moderation,
 * and its endpoint refuses anybody who is not an administrator anyway.
 */
class AdminSettings implements IDelegatedSettings {
	public function __construct(
		private ReportService $reportService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private ModerationService $moderationService,
		private FederationHealthService $federationHealthService,
		private IL10N $l10n,
		private ServerSettingsService $serverSettingsService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IInitialState $initialState,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		// the framework chunk first, then the page's own entry: an entry of
		// this app is no longer self-contained, and one served without the
		// framework leaves the page empty without saying anything
		Util::addScript('social', 'social-framework');
		Util::addScript('social', 'social-adminSettings');

		$open = $this->reportService->page(false, 1);
		$decisions = $this->currentDecisions();

		// The page is a Vue application; what the server knows when it renders
		// is handed over as initial state rather than as markup, and
		// everything after it — a further page of reports, the account
		// browser, the announcements — is read from the routes the page also
		// writes through.
		$this->initialState->provideInitialState('adminSettings', [
			// the first page of the open reports, in the shape
			// `ModerationController::reports()` answers in, so a row that
			// arrived with the page and one fetched afterwards are the same
			// thing to the table that draws them
			'reports' => array_map(
				static fn (Report $report): array => $report->moderationRow($decisions),
				$open['reports']
			),
			'openReports' => $open['total'],
			'resolvedReports' => $this->reportService->countResolved(),
			'reportsPerPage' => $open['perPage'],
			'server' => $this->isAdministrator() ? $this->serverSettingsService->current() : null,
			'accessType' => $this->fediverseService->getAccessType(),
			'accessList' => $this->fediverseService->getListedAddresses(),
			'retentionDays' => (int)$this->configService->getAppValue(ConfigService::SOCIAL_RETENTION_DAYS),
			'federation' => $this->federationHealthService->summary(),
		]);

		return new TemplateResponse('social', 'settings/admin');
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

	/**
	 * Whether the viewer administers the server, as opposed to holding the
	 * Social section by delegation.
	 */
	private function isAdministrator(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}

	#[\Override]
	public function getSection(): string {
		return AdminSection::SECTION_ID;
	}

	#[\Override]
	public function getPriority(): int {
		return 50;
	}

	/**
	 * The name this section is offered under in Administration privileges.
	 * The section has one panel, so it names what is being handed over.
	 */
	#[\Override]
	public function getName(): ?string {
		return $this->l10n->t('Moderation');
	}

	/**
	 * No app config is reachable through core's own settings API: everything
	 * this panel writes — the retention period, the access mode and list —
	 * goes through `ModerationController`, which is guarded by
	 * `AuthorizedAdminSetting` and validates what it is given, and the Server
	 * card through `ServerSettingsController`, which admits administrators
	 * only. A delegate can change the first and nothing else under `social`.
	 */
	#[\Override]
	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
