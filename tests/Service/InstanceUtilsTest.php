<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Tests\Service;

use OCA\Social\InstanceUtils;
use OCP\IURLGenerator;
use Test\TestCase;

class InstanceUtilsTest extends TestCase {
	private InstanceUtils $instanceUtils;

	public function setUp(): void {
		parent::setUp();
		$generator = $this->createMock(IURLGenerator::class);
		$generator->expects($this->once())
			->method('getAbsoluteUrl')
			->willReturn('https://hello.world.social/');
		$this->instanceUtils = new InstanceUtils($generator);
	}

	public function testInstanceName(): void {
		$this->assertSame('hello.world.social', $this->instanceUtils->getLocalInstanceName('/'));
	}

	public function testInstanceUrl(): void {
		$this->assertSame('https://hello.world.social', $this->instanceUtils->getLocalInstanceUrl('/'));
	}
}
