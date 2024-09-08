<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

use OCP\IConfig;
use OCP\Server;

/**
 * Trait TNCSetup
 */
trait TNCSetup {
	use TArrayTools;

	private array $_setup = [];

	public function setup(string $key, string $value = '', string $default = ''): string {
		if ($value !== '') {
			$this->_setup[$key] = $value;
		}

		return $this->get($key, $this->_setup, $default);
	}

	public function setupArray(string $key, array $value = [], array $default = []): array {
		if (!empty($value)) {
			$this->_setup[$key] = $value;
		}

		return $this->getArray($key, $this->_setup, $default);
	}

	public function setupInt(string $key, int $value = -999, int $default = 0): int {
		if ($value !== -999) {
			$this->_setup[$key] = $value;
		}

		return $this->getInt($key, $this->_setup, $default);
	}

	public function appConfig(string $key): string {
		$app = $this->setup('app');
		if ($app === '') {
			return '';
		}

		$config = Server::get(IConfig::class);
		return $config->getAppValue($app, $key, '');
	}
}
