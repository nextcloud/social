<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Helper;

use OC\AppFramework\DependencyInjection\DIContainer;
use OC\AppFramework\Utility\TimeFactory;
use OCA\Social\Tests\Helper\FrozenClock;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Puts the real server on a clock the test moves.
 *
 * The clock is registered twice: on the server container, for the code that
 * asks `Server::get(ITimeFactory::class)`, and on a fresh app container for
 * each service built through `clocked()`, so a service that took its clock
 * in the constructor gets this one instead of the one it was booted with.
 * `thawClock()` puts the server's own clock back and must run in tearDown.
 */
trait FreezesTheClock {
	private ?FrozenClock $frozenClock = null;
	private ?DIContainer $clockBootContainer = null;

	protected function freezeClock(FrozenClock $clock): FrozenClock {
		$this->frozenClock = $clock;
		$this->clockBootContainer ??= \OC::$server->getRegisteredAppContainer('social');
		\OC::$server->registerService(ITimeFactory::class, static fn (): ITimeFactory => $clock);

		return $clock;
	}

	protected function thawClock(): void {
		if ($this->frozenClock === null) {
			return;
		}

		\OC::$server->registerAlias(ITimeFactory::class, TimeFactory::class);
		if ($this->clockBootContainer !== null) {
			\OC::$server->registerAppContainer('social', $this->clockBootContainer);
		}
		$this->frozenClock = null;
		$this->clockBootContainer = null;
	}

	/** Registers the frozen clock, when there is one, on an app container being built. */
	protected function registerFrozenClock(DIContainer $container): void {
		$clock = $this->frozenClock;
		if ($clock !== null) {
			$container->registerService(ITimeFactory::class, static fn (): ITimeFactory => $clock);
		}
	}

	/**
	 * A service of the app built by a fresh app container that hands out the
	 * frozen clock.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	protected function clocked(string $class): object {
		$container = new DIContainer('social');
		$this->registerFrozenClock($container);

		/** @var T */
		return $container->get($class);
	}
}
