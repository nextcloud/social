<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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


	public function getDetailInt(string $detail, int $default = 0): int {
		return $this->details[$detail] ?? $default;
	}

	/**
	 * @param string $detail
	 * @param string $value
	 */
	public function addDetail(string $detail, string $value) {
		if (!array_key_exists($detail, $this->details) || !is_array($this->details[$detail])) {
			$this->details[$detail] = [];
		} elseif (in_array($value, $this->details[$detail])) {
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
