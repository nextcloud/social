<?php

declare(strict_types=1);


/**
 * Some tools for myself.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
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

/**
 * Trait TPathTools
 *
 * @deprecated - 19
 * @package OCA\Social\Tools\Traits
 */
trait TPathTools {
	/**
	 * @param string $path
	 *
	 * @return string
	 */
	protected function withEndSlash(string $path): string {
		$path .= '/';
		$path = str_replace('//', '/', $path);

		return trim($path);
	}


	/**
	 * @param string $path
	 * @param bool $force
	 * @param bool $clean
	 *
	 * @return string
	 */
	protected function withoutEndSlash(string $path, bool $force = false, bool $clean = true
	): string {
		if ($clean) {
			$path = str_replace('//', '/', $path);
		}

		if ($path === '/' && !$force) {
			return $path;
		}

		$path = rtrim($path, '/');

		return trim($path);
	}


	/**
	 * @param string $path
	 *
	 * @return string
	 */
	protected function withBeginSlash(string $path): string {
		$path = '/' . $path;
		$path = str_replace('//', '/', $path);

		return trim($path);
	}


	/**
	 * @param string $path
	 * @param bool $force
	 * @param bool $clean
	 *
	 * @return string
	 */
	protected function withoutBeginSlash(string $path, bool $force = false, bool $clean = true) {
		if ($clean) {
			$path = str_replace('//', '/', $path);
		}

		if ($path === '/' && !$force) {
			return $path;
		}

		$path = ltrim($path, '/');

		return trim($path);
	}


	/**
	 * @param string $path
	 * @param bool $force
	 * @param bool $clean
	 *
	 * @return string
	 */
	protected function withoutBeginAt(string $path) {
		$path = ltrim($path, '@');

		return trim($path);
	}
}
