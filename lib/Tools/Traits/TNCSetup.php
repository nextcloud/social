<?php

declare(strict_types=1);


/**
 * Some tools for myself.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2020, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
