<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
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

namespace daita\Traits;


trait TArrayTools {


	/**
	 * @param string $k
	 * @param array $arr
	 * @param string $default
	 *
	 * @return string
	 */
	private function get(string $k, array $arr, string $default = ''): string {
		if ($arr === null) {
			return $default;
		}

		if (!key_exists($k, $arr) || $arr[$k] === null) {
			return $default;
		}

		return $arr[$k];
	}


	/**
	 * @param string $k
	 * @param array $arr
	 * @param int $default
	 *
	 * @return int
	 */
	private function getInt(string $k, array $arr, int $default = 0): int {
		if ($arr === null) {
			return $default;
		}

		if (!key_exists($k, $arr) || $arr[$k] === null) {
			return $default;
		}

		return intval($arr[$k]);
	}


	/**
	 * @param string $k
	 * @param array $arr
	 * @param bool $default
	 *
	 * @return bool
	 */
	private function getBool(string $k, array $arr, bool $default = false): bool {
		if ($arr === null) {
			return $default;
		}

		if (!key_exists($k, $arr)) {
			return $default;
		}

		return $arr[$k];
	}


	/**
	 * @param string $k
	 * @param array $arr
	 * @param array $default
	 *
	 * @return array
	 */
	private function getArray(string $k, array $arr, array $default = []): array {
		if ($arr === null) {
			return $default;
		}

		if (!key_exists($k, $arr)) {
			return $default;
		}

		$r = $arr[$k];
		if ($r === null || !is_array($r)) {
			return $default;
		}

		return $r;
	}


}

