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


use daita\Traits\TArrayTools;
use JsonSerializable;

class Instance implements JsonSerializable {

	use TArrayTools;


	/** @var string */
	private $address;

	/** @var InstancePath[] */
	private $instancePaths = [];

	public function __construct(string $address = '') {
		$this->address = $address;
	}


	/**
	 * @return string
	 */
	public function getAddress(): string {
		return $this->address;
	}


	/**
	 * @param InstancePath $path
	 *
	 * @return Instance
	 */
	public function addPath(InstancePath $path): Instance {
		$this->instancePaths[] = $path;

		return $this;
	}

	/**
	 * @return InstancePath[]
	 */
	public function getInstancePaths(): array {
		return $this->instancePaths;
	}


	public function jsonSerialize(): array {
		return [
			'address'       => $this->address,
			'instancePaths' => $this->getInstancePaths()
		];
	}


}

