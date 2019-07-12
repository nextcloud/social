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


namespace OCA\Social\Traits;


use OCA\Social\Model\ActivityPub\Item;

/**
 * Trait TDetails
 *
 * @package OCA\Social\Traits
 */
trait TDetails {


	/** @var array */
	private $details = [];


	/**
	 * @return array
	 */
	public function getDetailsAll(): array {
		return $this->details;
	}

	/**
	 * @param array $details
	 */
	public function setDetailsAll(array $details) {
		$this->details = $details;
	}


	/**
	 * @param string $detail
	 * @param string $value
	 */
	public function setDetail(string $detail, string $value) {
		$this->details[$detail] = $value;
	}

	/**
	 * @param string $detail
	 * @param int $value
	 */
	public function setDetailInt(string $detail, int $value) {
		$this->details[$detail] = $value;
	}

	/**
	 * @param string $detail
	 * @param array $value
	 */
	public function setDetailArray(string $detail, array $value) {
		$this->details[$detail] = $value;
	}

	/**
	 * @param string $detail
	 * @param bool $value
	 */
	public function setDetailBool(string $detail, bool $value) {
		$this->details[$detail] = $value;
	}

	/**
	 * @param string $detail
	 * @param Item $value
	 */
	public function setDetailItem(string $detail, Item $value) {
		$this->details[$detail] = $value;
	}


	/**
	 * @param string $detail
	 *
	 * @return array
	 */
	public function getDetails(string $detail): array {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			return [];
		}

		return $this->details[$detail];
	}


	/**
	 * @param string $detail
	 * @param string $value
	 */
	public function addDetail(string $detail, string $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			$this->details[$detail] = [];
		} else if (in_array($value, $this->details[$detail])) {
			return;
		}

		$this->details[$detail][] = $value;
	}

	/**
	 * @param string $detail
	 * @param int $value
	 */
	public function addDetailInt(string $detail, int $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			$this->details[$detail] = [];
		}

		$this->details[$detail][] = $value;
	}

	/**
	 * @param string $detail
	 * @param array $value
	 */
	public function addDetailArray(string $detail, array $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			$this->details[$detail] = [];
		}

		$this->details[$detail][] = $value;
	}

	/**
	 * @param string $detail
	 * @param bool $value
	 */
	public function addDetailBool(string $detail, bool $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			$this->details[$detail] = [];
		}

		$this->details[$detail][] = $value;
	}


	/**
	 * @param string $detail
	 * @param string $value
	 */
	public function removeDetail(string $detail, string $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			return;
		}

		$this->details[$detail] = array_diff($this->details[$detail], [$value]);
	}

	/**
	 * @param string $detail
	 * @param int $value
	 */
	public function removeDetailInt(string $detail, int $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			return;
		}

		$this->details[$detail] = array_diff($this->details, [$value]);
	}

	/**
	 * @param string $detail
	 * @param array $value
	 */
	public function removeDetailArray(string $detail, array $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			return;
		}

		$this->details[$detail] = array_diff($this->details, [$value]);
	}

	/**
	 * @param string $detail
	 * @param bool $value
	 */
	public function removeDetailBool(string $detail, bool $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			return;
		}

		$this->details[$detail] = array_diff($this->details, [$value]);
	}

}

