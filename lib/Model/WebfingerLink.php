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


namespace OCA\Social\Model;

use JsonSerializable;


/**
 * Class WebfingerLink
 *
 * @package OCA\Social\Model
 */
class WebfingerLink implements JsonSerializable {


	/** @var string */
	private $href = '';

	/** @var string */
	private $rel = '';

	/** @var string */
	private $template = '';

	/** @var string */
	private $type = '';


	/**
	 * @return string
	 */
	public function getHref(): string {
		return $this->href;
	}

	/**
	 * @param string $value
	 *
	 * @return WebfingerLink
	 */
	public function setHref(string $value): self {
		$this->href = $value;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @param string $value
	 *
	 * @return WebfingerLink
	 */
	public function setType(string $value): self {
		$this->type = $value;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getRel(): string {
		return $this->rel;
	}

	/**
	 * @param string $value
	 *
	 * @return WebfingerLink
	 */
	public function setRel(string $value): self {
		$this->rel = $value;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getTemplate(): string {
		return $this->template;
	}

	/**
	 * @param string $value
	 *
	 * @return WebfingerLink
	 */
	public function setTemplate(string $value): self {
		$this->template = $value;

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$data = [
			'rel'      => $this->getRel(),
			'type'     => $this->getType(),
			'template' => $this->getTemplate(),
			'href'     => $this->getHref()
		];

		return array_filter($data);
	}

}

