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


use daita\MySmallPhpTools\Model\SimpleDataStore;
use daita\MySmallPhpTools\Traits\TArrayTools;
use JsonSerializable;


/**
 * Class Test
 *
 * @package OCA\Social\Model
 */
class Test extends SimpleDataStore implements JsonSerializable {


	use TArrayTools;


	const SEVERITY_USELESS = 'useless';
	const SEVERITY_OPTIONAL = 'optional';
	const SEVERITY_MANDATORY = 'mandatory';


	/** @var string */
	private $name;

	/** @var string */
	private $severity;

	/** @var bool */
	private $success = false;

	/** @var array */
	private $messages = [];


	/**
	 * Test constructor.
	 *
	 * @param string $name
	 * @param string $severity
	 */
	public function __construct(string $name = '', string $severity = self::SEVERITY_OPTIONAL) {
		$this->name = $name;
		$this->severity = $severity;
	}


	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}


	/**
	 * @return string
	 */
	public function getSeverity(): string {
		return $this->severity;
	}


	/**
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * @param bool $success
	 *
	 * @return Test
	 */
	public function setSuccess(bool $success): self {
		$this->success = $success;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getMessages(): array {
		return $this->messages;
	}

	/**
	 * @param string $message
	 *
	 * @return Test
	 */
	public function addMessage(string $message): self {
		$this->messages[] = $message;

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$result = array_filter(
			[
				'name'     => $this->getName(),
				'severity' => $this->getSeverity(),
				'details'  => $this->gAll(),
				'message'  => $this->getMessages()
			]
		);

		$result['success'] = $this->isSuccess();

		return $result;
	}

}

