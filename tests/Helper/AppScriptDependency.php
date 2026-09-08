<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

/**
 * Stand-in for the server's `OC\AppScriptDependency`, which `Util::addScript()`
 * builds and which is private to the server, so absent from the OCP stubs the
 * suite runs against.
 *
 * It only has to exist and hold what it is given: nothing under test reads the
 * ordering back — that is the server's job at render time.
 */
class AppScriptDependency {
	/** @var string[] */
	private array $deps;

	public function __construct(
		private string $id,
		array $deps = [],
	) {
		$this->deps = $deps;
	}

	public function getId(): string {
		return $this->id;
	}

	/** @return string[] */
	public function getDeps(): array {
		return $this->deps;
	}

	public function addDep(string $dep): void {
		if (!in_array($dep, $this->deps, true)) {
			$this->deps[] = $dep;
		}
	}
}
