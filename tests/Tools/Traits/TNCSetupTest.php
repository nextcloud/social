<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Traits;

use OCA\Social\Tools\Traits\TNCSetup;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class TNCSetupTest extends TestCase {
	private object $setup;

	protected function setUp(): void {
		$this->setup = new class {
			use TNCSetup;
		};
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testSetupStoresAValueAndReadsItBack(): void {
		$this->assertSame('social', $this->setup->setup('app', 'social'));
		$this->assertSame('social', $this->setup->setup('app'));
		$this->assertSame('social', $this->setup->setup('app', '', 'other'), 'an empty value does not overwrite');
		$this->assertSame('dflt', $this->setup->setup('missing', '', 'dflt'));
		$this->assertSame('', $this->setup->setup('missing'));
	}

	public function testSetupArrayStoresNonEmptyArrays(): void {
		$this->assertSame(['a', 'b'], $this->setup->setupArray('list', ['a', 'b']));
		$this->assertSame(['a', 'b'], $this->setup->setupArray('list'));
		$this->assertSame(['a', 'b'], $this->setup->setupArray('list', [], ['d']), 'an empty array does not overwrite');
		$this->assertSame(['d'], $this->setup->setupArray('missing', [], ['d']));
	}

	public function testSetupIntUsesMinus999AsTheNoValueMarker(): void {
		$this->assertSame(0, $this->setup->setupInt('count', 0));
		$this->assertSame(0, $this->setup->setupInt('count'));
		$this->assertSame(0, $this->setup->setupInt('count', -999, 7), 'the marker does not overwrite');
		$this->assertSame(7, $this->setup->setupInt('missing', -999, 7));
	}

	public function testAppConfigIsEmptyWithoutAnAppName(): void {
		$this->assertSame('', $this->setup->appConfig('key'));
	}

	public function testAppConfigReadsTheValueOfTheConfiguredApp(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('getAppValue')
			->with('social', 'address', '')
			->willReturn('https://cloud.example.org');
		\OC::$server->register(IConfig::class, $config);
		$this->setup->setup('app', 'social');

		$this->assertSame('https://cloud.example.org', $this->setup->appConfig('address'));
	}
}
