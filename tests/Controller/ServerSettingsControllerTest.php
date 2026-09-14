<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use InvalidArgumentException;
use OCA\Social\Controller\ServerSettingsController;
use OCA\Social\Service\ServerSettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint behind the Server card.
 *
 * Administrators only, and by omission rather than by a check: the controller
 * carries none of the attributes that relax the default, so the server
 * dispatches it for an administrator with a session and a CSRF token and for
 * nobody else — in particular not for a group the Social section was
 * delegated to, which passes `ModerationController` and not this.
 */
class ServerSettingsControllerTest extends TestCase {
	private ServerSettingsService|MockObject $serverSettingsService;
	private ServerSettingsController $controller;

	protected function setUp(): void {
		$this->serverSettingsService = $this->createMock(ServerSettingsService::class);
		$this->controller = new ServerSettingsController(
			$this->createMock(IRequest::class), $this->serverSettingsService
		);
	}

	public function testWhatTheCardSentReachesTheServiceUnchanged(): void {
		$this->serverSettingsService->expects($this->once())->method('save')
			->with('admin@instance.example', 'a friendly place', 20, 4096, 0, true, true, false)
			->willReturn(['contact_email' => 'admin@instance.example']);

		$response = $this->controller->save(
			'admin@instance.example', 'a friendly place', 20, 4096, 0, true, true, false
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['contact_email' => 'admin@instance.example'], $response->getData());
	}

	/**
	 * The field that was refused is the one thing that tells the
	 * administrator what to change, so it reaches the card rather than being
	 * flattened into "no".
	 */
	public function testARefusalNamesTheFieldAndIsAnUnprocessableEntity(): void {
		$this->serverSettingsService->method('save')
			->willThrowException(new InvalidArgumentException('max_size must be between 1 and 10240 MB'));

		$response = $this->controller->save(maxSize: 0);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'max_size must be between 1 and 10240 MB'], $response->getData());
	}

	/**
	 * A delegate moderates and does not administer. Were this route carrying
	 * `AuthorizedAdminSetting` like the rest of the panel, the group an
	 * administrator handed the Social section to could turn secure mode on
	 * for the whole instance.
	 */
	public function testTheRouteIsNotOneADelegateCanReach(): void {
		$method = new \ReflectionMethod(ServerSettingsController::class, 'save');

		foreach ([AuthorizedAdminSetting::class, NoAdminRequired::class, PublicPage::class] as $attribute) {
			$this->assertSame(
				[], $method->getAttributes($attribute),
				$attribute . ' would let somebody who is not an administrator write these'
			);
		}
	}

	/** The defaults are the app's own, so an older card cannot blank a field. */
	public function testTheDefaultsAreWhatTheAppShipsWith(): void {
		$this->serverSettingsService->expects($this->once())->method('save')
			->with('', '', 10, 2048, 300, false, false, false)->willReturn([]);

		$this->controller->save();
	}
}
