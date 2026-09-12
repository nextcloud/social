<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Settings;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\ReportService;
use OCA\Social\Settings\AdminSection;
use OCA\Social\Settings\AdminSettings;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The administration page and the template it feeds.
 *
 * The template reads the summary by key, so a rename on either side is a
 * PHP warning and a blank section rather than a failure anybody notices —
 * which is exactly what these render assertions are here to catch.
 */
class AdminSettingsTest extends TestCase {
	private FederationHealthService|MockObject $federationHealthService;
	private ModerationService|MockObject $moderationService;
	private ReportService|MockObject $reportService;
	private AdminSettings $settings;

	/** Translates nothing, which is what a test needs of it. */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}

	protected function setUp(): void {
		// getForm() registers the page's script, which resolves the l10n factory
		// off the container rather than taking it as a dependency
		\OC::$server->register(IFactory::class, $this->createMock(IFactory::class));

		$this->reportService = $this->createMock(ReportService::class);

		$fediverseService = $this->createMock(FediverseService::class);
		$fediverseService->method('getAccessType')->willReturn('all_but');
		$fediverseService->method('getListedAddresses')->willReturn([]);

		$this->federationHealthService = $this->createMock(FederationHealthService::class);
		$this->moderationService = $this->createMock(ModerationService::class);

		$this->settings = new AdminSettings(
			$this->reportService,
			$fediverseService,
			$this->createMock(ConfigService::class),
			$this->moderationService,
			$this->federationHealthService,
			$this->l10n(),
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();

		parent::tearDown();
	}

	/**
	 * @return array{waiting: int, running: int, failing: int, atRisk: int, maxTries: int, truncated: bool, instances: array}
	 */
	private function summary(array $overrides = []): array {
		return array_merge([
			'waiting' => 0,
			'running' => 0,
			'failing' => 0,
			'atRisk' => 0,
			'maxTries' => 15,
			'truncated' => false,
			'instances' => [],
		], $overrides);
	}

	/**
	 * Renders the template the settings page returns, with warnings promoted to
	 * failures so a key the template reads but nobody sets cannot pass quietly.
	 */
	private function render(array $summary, array $reports = [], array $decisions = []): string {
		$this->reportService->method('getReports')->willReturn($reports);
		$this->moderationService->method('decisions')->willReturn($decisions);
		$this->federationHealthService->method('summary')->willReturn($summary);
		$parameters = $this->settings->getForm()->getParams();

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			fn (string $text, array $parameters = []): string => vsprintf(str_replace('%s', '%s', $text), $parameters)
		);
		$l->method('n')->willReturnCallback(
			fn (string $singular, string $plural, int $count): string
				=> str_replace('%n', (string)$count, $count === 1 ? $singular : $plural)
		);

		set_error_handler(static function (int $severity, string $message): bool {
			throw new \ErrorException($message, 0, $severity);
		});

		try {
			ob_start();
			$_ = $parameters;
			require __DIR__ . '/../../templates/settings/admin.php';

			return (string)ob_get_clean();
		} finally {
			// pop ours off the stack rather than installing a replacement, so
			// whatever PHPUnit had is exactly what the next test gets
			restore_error_handler();
		}
	}

	public function testTheReportsTableOffersTheDecisionsAModeratorCanMake(): void {
		$target = $this->createMock(\OCA\Social\Model\ActivityPub\Actor\Person::class);
		$target->method('getId')->willReturn('https://spam.example/users/spammer');
		$target->method('getAccount')->willReturn('spammer@spam.example');

		$report = $this->createMock(\OCA\Social\Model\Report::class);
		$report->method('getId')->willReturn(1);
		$report->method('getTargetAccount')->willReturn($target);
		$report->method('getAccountId')->willReturn('https://spam.example/users/spammer');
		$report->method('getActorId')->willReturn('https://cloud.example/users/alice');
		$report->method('getStatusIds')->willReturn([]);
		$report->method('getCategory')->willReturn('spam');
		$report->method('getComment')->willReturn('endless crypto');

		$html = $this->render($this->summary(), [$report], []);

		// the panel used to describe a problem and offer no lever at all
		$this->assertStringContainsString('Silence', $html);
		$this->assertStringContainsString('Suspend', $html);
		$this->assertStringContainsString('data-actor-id="https://spam.example/users/spammer"', $html);
	}

	public function testAnAccountAlreadyDealtWithSaysSo(): void {
		$target = $this->createMock(\OCA\Social\Model\ActivityPub\Actor\Person::class);
		$target->method('getId')->willReturn('https://spam.example/users/spammer');
		$target->method('getAccount')->willReturn('spammer@spam.example');

		$report = $this->createMock(\OCA\Social\Model\Report::class);
		$report->method('getId')->willReturn(1);
		$report->method('getTargetAccount')->willReturn($target);
		$report->method('getStatusIds')->willReturn([]);
		$report->method('getCategory')->willReturn('spam');
		$report->method('getComment')->willReturn('');
		$report->method('getActorId')->willReturn('');

		$decision = new \OCA\Social\Model\Moderation('https://spam.example/users/spammer', 'suspend');
		$html = $this->render($this->summary(), [$report], [$decision]);

		$this->assertStringContainsString('Suspended', $html);
	}

	public function testTheFormCarriesTheFederationSummary(): void {
		$this->reportService->method('getReports')->willReturn([]);
		$this->moderationService->method('decisions')->willReturn([]);
		$this->federationHealthService->expects($this->once())->method('summary')
			->willReturn($this->summary(['waiting' => 4]));

		$this->assertSame(4, $this->settings->getForm()->getParams()['federation']['waiting']);
	}

	public function testAHealthyQueueSaysSoInsteadOfShowingAnEmptyTable(): void {
		$html = $this->render($this->summary(['waiting' => 4]));

		$this->assertStringContainsString('Federation health', $html);
		$this->assertStringContainsString('4 deliveries waiting to be sent.', $html);
		$this->assertStringContainsString('Nothing is failing to deliver.', $html);
		// the section is always there; the table of failures should not be
		$this->assertStringNotContainsString('Waiting deliveries', $html);
	}

	public function testAFailingInstanceIsNamedWithItsAttemptsAndLastTry(): void {
		$html = $this->render($this->summary([
			'waiting' => 30,
			'failing' => 6,
			'atRisk' => 2,
			'instances' => [
				['host' => 'gone.example', 'requests' => 5, 'tries' => 14, 'last' => 1_700_000_000],
			],
		]));

		$this->assertStringContainsString('gone.example', $html);
		$this->assertStringContainsString('14 / 15', $html);
		$this->assertStringContainsString('2023-11-14 22:13', $html);
		$this->assertStringContainsString('6 deliveries have failed at least once.', $html);
		$this->assertStringContainsString('2 of them are close to being given up on.', $html);
	}

	public function testAnInstanceNeverTriedSaysSoRatherThanShowingTheEpoch(): void {
		$html = $this->render($this->summary([
			'failing' => 1,
			'instances' => [['host' => 'gone.example', 'requests' => 1, 'tries' => 3, 'last' => 0]],
		]));

		$this->assertStringContainsString('never', $html);
		$this->assertStringNotContainsString('1970-01-01', $html);
	}

	public function testACountThatStoppedShortSaysSo(): void {
		$html = $this->render($this->summary(['failing' => 500, 'truncated' => true]));

		$this->assertStringContainsString('only the first few hundred were counted', $html);
	}

	/**
	 * Moderating used to mean administering the whole server. The section is
	 * offered in Administration privileges so an administrator can hand it to
	 * a group and nothing else with it.
	 */
	public function testTheSectionCanBeDelegated(): void {
		$this->assertInstanceOf(IDelegatedSettings::class, $this->settings);
		$this->assertSame('Moderation', $this->settings->getName());
		$this->assertSame(AdminSection::SECTION_ID, $this->settings->getSection());
	}

	/**
	 * A delegate writes through ModerationController, which validates what it
	 * is given; core's own app-config route would let them write any key under
	 * `social` as a raw string.
	 */
	public function testDelegationCarriesNoAppConfigWriteAccess(): void {
		$this->assertSame([], $this->settings->getAuthorizedAppConfig());
	}
}
