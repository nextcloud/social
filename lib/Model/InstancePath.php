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


use daita\MySmallPhpTools\Traits\TArrayTools;
use JsonSerializable;


/**
 * Class InstancePath
 *
 * @package OCA\Social\Model
 */
class InstancePath implements JsonSerializable {


	use TArrayTools;

	const TYPE_PUBLIC = 0;
	const TYPE_INBOX = 1;
	const TYPE_GLOBAL = 2;
	const TYPE_FOLLOWERS = 3;


	/** @var string */
	private $uri = '';

	/** @var int */
	private $type = 0;


	/**
	 * InstancePath constructor.
	 *
	 * @param string $uri
	 * @param int $type
	 */
	public function __construct(string $uri = '', int $type = 0) {
		$this->uri = $uri;
		$this->type = $type;
	}


	/**
	 * @return string
	 */
	public function getUri(): string {
		return $this->uri;
	}


	/**
	 * @return int
	 */
	public function getType(): int {
		return $this->type;
	}


	/**
	 * @return string
	 */
	public function getAddress(): string {
		$info = parse_url($this->uri);

		return $this->get('host', $info, '');
	}


	/**
	 * @return string
	 */
	public function getPath(): string {
		$info = parse_url($this->uri);

		return $this->get('path', $info, '');
	}


	public function import(array $data) {


	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'uri'  => $this->getUri(),
			'type' => $this->getType()
		];
	}


}

