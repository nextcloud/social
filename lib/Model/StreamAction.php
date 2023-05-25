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
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TStringTools;

/**
 * Class StreamAction
 *
 * @package OCA\Social\Model
 */
class StreamAction implements JsonSerializable {
	use TArrayTools;
	use TStringTools;


	public const LIKED = 'liked';
	public const BOOSTED = 'boosted';
	public const REPLIED = 'replied';

	private int $id = 0;
	private string $actorId = '';
	private string $streamId = '';
	private array $values = [];


	/**
	 * StreamAction constructor.
	 */
	public function __construct(string $actorId = '', string $streamId = '') {
		$this->actorId = $actorId;
		$this->streamId = $streamId;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): StreamAction {
		$this->id = $id;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setActorId(string $actorId): StreamAction {
		$this->actorId = $actorId;

		return $this;
	}

	public function getStreamId(): string {
		return $this->streamId;
	}

	public function setStreamId(string $streamId): StreamAction {
		$this->streamId = $streamId;

		return $this;
	}

	public function updateValue(string $key, string $value): void {
		$this->values[$key] = $value;
	}

	public function updateValueInt(string $key, int $value): void {
		$this->values[$key] = $value;
	}

	public function updateValueBool(string $key, bool $value): void {
		$this->values[$key] = $value;
	}

	public function hasValue(string $key): bool {
		return (array_key_exists($key, $this->values));
	}

	public function getValue(string $key): string {
		return $this->values[$key];
	}

	public function getValueInt(string $key): int {
		return $this->values[$key];
	}

	public function getValueBool(string $key): bool {
		return $this->values[$key];
	}

	public function getValues(): array {
		return $this->values;
	}

	public function setValues(array $values): StreamAction {
		$this->values = $values;

		return $this;
	}

	public function setDefaultValues(array $default): StreamAction {
		$keys = array_keys($default);
		foreach ($keys as $k) {
			if (!array_key_exists($k, $this->values)) {
				$this->values[$k] = $default[$k];
			}
		}

		return $this;
	}

	public function importFromDatabase(array $data): void {
		$this->setId($this->getInt('id', $data, 0));
		$this->setActorId($this->get('actor_id', $data, ''));
		$this->setStreamId($this->get('stream_id', $data, ''));
		$this->setValues($this->getArray('values', $data, []));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'actorId' => $this->getActorId(),
			'streamId' => $this->getStreamId(),
			'values' => $this->getValues(),
		];
	}
}
