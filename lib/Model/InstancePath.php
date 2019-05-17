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

	const PRIORITY_NONE = 0;
	const PRIORITY_LOW = 1;
	const PRIORITY_MEDIUM = 2;
	const PRIORITY_HIGH = 3;
	const PRIORITY_TOP = 4;

	/** @var string */
	private $uri = '';

	/** @var int */
	private $type = 0;

	/** @var int */
	private $priority = 0;


	/**
	 * InstancePath constructor.
	 *
	 * @param string $uri
	 * @param int $type
	 * @param int $priority
	 */
	public function __construct(string $uri = '', int $type = 0, int $priority = 0) {
		$this->uri = $uri;
		$this->type = $type;
		$this->priority = $priority;
	}


	/**
	 * @param string $uri
	 *
	 * @return InstancePath
	 */
	public function setUri(string $uri): InstancePath {
		$this->uri = $uri;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getUri(): string {
		return $this->uri;
	}


	/**
	 * @param int $type
	 *
	 * @return InstancePath
	 */
	public function setType(int $type): InstancePath {
		$this->type = $type;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getType(): int {
		return $this->type;
	}


	/**
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}

	/**
	 * @param int $priority
	 *
	 * @return InstancePath
	 */
	public function setPriority(int $priority): InstancePath {
		$this->priority = $priority;

		return $this;
	}


	public function getProtocol(): string {
		$info = parse_url($this->getUri());

		return $this->get('scheme', $info, '');
	}


	/**
	 * @return string
	 */
	public function getAddress(): string {
		$info = parse_url($this->getUri());

		return $this->get('host', $info, '');
	}


	/**
	 * @return string
	 */
	public function getPath(): string {
		$info = parse_url($this->getUri());

		return $this->get('path', $info, '');
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		$this->setUri($this->get('uri', $data, ''));
		$this->setType($this->getInt('type', $data, 0));
		$this->setPriority($this->getInt('priority', $data, 0));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'uri'      => $this->getUri(),
			'type'     => $this->getType(),
			'priority' => $this->getPriority()
		];
	}


}

