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
use daita\MySmallPhpTools\Traits\TStringTools;
use JsonSerializable;


/**
 * Class StreamAction
 *
 * @package OCA\Social\Model
 */
class StreamAction implements JsonSerializable {


	use TArrayTools;
	use TStringTools;


	const LIKED = 'liked';
	const BOOSTED = 'boosted';
	const REPLIED = 'replied';


	/** @var integer */
	private $id = 0;

	/** @var string */
	private $actorId = '';

	/** @var string */
	private $streamId = '';

	/** @var array */
	private $values = [];


	/**
	 * StreamAction constructor.
	 *
	 * @param string $actorId
	 * @param string $streamId
	 */
	public function __construct(string $actorId = '', string $streamId = '') {
		$this->actorId = $actorId;
		$this->streamId = $streamId;
	}


	/**
	 * @return int
	 */
	public function getId(): int {
		return $this->id;
	}

	/**
	 * @param int $id
	 *
	 * @return StreamAction
	 */
	public function setId(int $id): StreamAction {
		$this->id = $id;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getActorId(): string {
		return $this->actorId;
	}

	/**
	 * @param string $actorId
	 *
	 * @return StreamAction
	 */
	public function setActorId(string $actorId): StreamAction {
		$this->actorId = $actorId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getStreamId(): string {
		return $this->streamId;
	}

	/**
	 * @param string $streamId
	 *
	 * @return StreamAction
	 */
	public function setStreamId(string $streamId): StreamAction {
		$this->streamId = $streamId;

		return $this;
	}


	/**
	 * @param string $key
	 * @param string $value
	 */
	public function updateValue(string $key, string $value) {
		$this->values[$key] = $value;
	}

	/**
	 * @param string $key
	 * @param int $value
	 */
	public function updateValueInt(string $key, int $value) {
		$this->values[$key] = $value;
	}

	/**
	 * @param string $key
	 * @param bool $value
	 */
	public function updateValueBool(string $key, bool $value) {
		$this->values[$key] = $value;
	}

	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	public function hasValue(string $key): bool {
		return (array_key_exists($key, $this->values));
	}

	/**
	 * @param string $key
	 *
	 * @return string
	 */
	public function getValue(string $key): string {
		return $this->values[$key];
	}

	/**
	 * @param string $key
	 *
	 * @return int
	 */
	public function getValueInt(string $key): int {
		return $this->values[$key];
	}

	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	public function getValueBool(string $key): bool {
		return $this->values[$key];
	}

	/**
	 * @return array
	 */
	public function getValues(): array {
		return $this->values;
	}

	/**
	 * @param array $values
	 *
	 * @return StreamAction
	 */
	public function setValues(array $values): StreamAction {
		$this->values = $values;

		return $this;
	}


	/**
	 * @param array $default
	 *
	 * @return StreamAction
	 */
	public function setDefaultValues(array $default): StreamAction {
		$keys = array_keys($default);
		foreach ($keys as $k) {
			if (!array_key_exists($k, $this->values)) {
				$this->values[$k] = $default[$k];
			}
		}

		return $this;
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		$this->setId($this->getInt('id', $data, 0));
		$this->setActorId($this->get('actor_id', $data, ''));
		$this->setStreamId($this->get('stream_id', $data, ''));
		$this->setValues($this->getArray('values', $data, []));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'id'       => $this->getId(),
			'actorId'  => $this->getActorId(),
			'streamId' => $this->getStreamId(),
			'values'   => $this->getValues(),
		];
	}

}

