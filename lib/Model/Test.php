<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Tools\Model\SimpleDataStore;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class Test
 *
 * @package OCA\Social\Model
 */
class Test extends SimpleDataStore implements JsonSerializable {
	use TArrayTools;


	public const SEVERITY_USELESS = 'useless';
	public const SEVERITY_OPTIONAL = 'optional';
	public const SEVERITY_MANDATORY = 'mandatory';


	private string $name;

	private string $severity;

	private bool $success = false;

	private array $messages = [];


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
				'name' => $this->getName(),
				'severity' => $this->getSeverity(),
				'details' => $this->gAll(),
				'message' => $this->getMessages()
			]
		);

		$result['success'] = $this->isSuccess();

		return $result;
	}
}
