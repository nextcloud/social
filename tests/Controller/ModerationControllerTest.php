<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ModerationController;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\Report;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\ReportService;
use OCA\Social\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ModerationControllerTest extends TestCase {
	private ReportService|MockObject $reportService;
	private FediverseService|MockObject $fediverseService;
	private ConfigService|MockObject $configService;
	private ModerationService|MockObject $moderationService;
	private ModerationController $controller;

	protected function setUp(): void {
		$this->reportService = $this->createMock(ReportService::class);
		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->moderationService = $this->createMock(ModerationService::class);
		$this->controller = new ModerationController(
			$this->createMock(IRequest::class),
			$this->reportService,
			$this->fediverseService,
			$this->configService,
			$this->moderationService
		);
	}

	public function testModerationRoutesRequireASessionAndCsrf(): void {
		// no PublicPage/NoAdminRequired/NoCSRFRequired — neither as attribute
		// nor as legacy annotation: the server only dispatches these routes
		// for a logged-in session with a CSRF token
		$reflection = new \ReflectionClass(ModerationController::class);
		$doc = (string)$reflection->getDocComment();
		$attributes = [];
		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			$doc .= (string)$method->getDocComment();
			foreach ($method->getAttributes() as $attribute) {
				$attributes[] = $attribute->getName();
			}
		}

		foreach (['PublicPage', 'NoAdminRequired', 'NoCSRFRequired'] as $relaxation) {
			$this->assertStringNotContainsString('@' . $relaxation, $doc);
			foreach ($attributes as $attribute) {
				$this->assertStringNotContainsString($relaxation, $attribute);
			}
		}
	}

	/**
	 * Without the attribute a method here is admin-only, which is safe and
	 * wrong: the page around it opens for a delegated moderator and the
	 * buttons on it would answer 403.
	 */
	public function testEveryModerationActionIsOpenToADelegatedModerator(): void {
		$reflection = new \ReflectionClass(ModerationController::class);

		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== ModerationController::class) {
				continue;
			}

			$attributes = $method->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(
				1, $attributes, $method->getName() . '() carries no AuthorizedAdminSetting'
			);
			$this->assertSame(
				['settings' => AdminSettings::class],
				$attributes[0]->getArguments(),
				$method->getName() . '() is delegated through the wrong settings class'
			);
		}
	}

	public function testReportResolveMarksTheReport(): void {
		$report = (new Report())->setId(7)->setResolved(true);
		$this->reportService->expects($this->once())
			->method('setResolved')->with(7, true)->willReturn($report);

		$response = $this->controller->reportResolve(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($report, $response->getData());
	}

	public function testReportResolveCanReopen(): void {
		$this->reportService->expects($this->once())
			->method('setResolved')->with(7, false)->willReturn(new Report());

		$this->controller->reportResolve(7, false);
	}

	public function testReportResolveOfAnUnknownReportIsNotFound(): void {
		$this->reportService->method('setResolved')
			->willThrowException(new ReportNotFoundException());

		$response = $this->controller->reportResolve(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testFediverseAddNormalisesAndReturnsTheList(): void {
		$this->fediverseService->expects($this->once())->method('addAddress')->with('evil.example');
		$this->fediverseService->method('getListedAddresses')->willReturn(['evil.example']);

		$response = $this->controller->fediverseAdd('  EVIL.example ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['list' => ['evil.example']], $response->getData());
	}

	public function testFediverseAddRefusesAnInvalidAddress(): void {
		$this->fediverseService->expects($this->never())->method('addAddress');

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->fediverseAdd('not a hostname!')->getStatus()
		);
		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->fediverseAdd('')->getStatus()
		);
	}

	public function testFediverseRemoveReturnsTheRemainingList(): void {
		$this->fediverseService->expects($this->once())->method('removeAddress')->with('evil.example');
		$this->fediverseService->method('getListedAddresses')->willReturn([]);

		$response = $this->controller->fediverseRemove('evil.example');

		$this->assertSame(['list' => []], $response->getData());
	}

	public function testRetentionStoresTheConfiguredPeriod(): void {
		$this->configService->expects($this->once())
			->method('setAppValue')->with(ConfigService::SOCIAL_RETENTION_DAYS, '90');

		$response = $this->controller->retention(90);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['retentionDays' => 90], $response->getData());
	}

	public function testRetentionRefusesAnInvalidPeriod(): void {
		$this->configService->expects($this->never())->method('setAppValue');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->retention(-1)->getStatus());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->retention(99999)->getStatus());
	}

	public function testFediverseAccessRejectsAnUnknownType(): void {
		$this->fediverseService->method('setAccessType')
			->willThrowException(new \Exception('invalid type'));

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->fediverseAccess('everything')->getStatus()
		);
	}

	public function testFediverseAccessSwitchesTheMode(): void {
		$this->fediverseService->expects($this->once())->method('setAccessType')->with('none_but');
		$this->fediverseService->method('getAccessType')->willReturn('none_but');

		$response = $this->controller->fediverseAccess('none_but');

		$this->assertSame(['accessType' => 'none_but'], $response->getData());
	}
}
