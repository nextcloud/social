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
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * Class StreamDetails
 *
 * @package OCA\Social\Model
 */
class StreamDetails implements JsonSerializable {
	use TArrayTools;

	private Stream $stream;

	/** @var Person[] */
	private array $homeViewers = [];

	/** @var Person[] */
	private array $directViewers = [];
	private bool $public = false;
	private bool $federated = false;


	/**
	 * StreamDetails constructor.
	 */
	public function __construct(?Stream $stream = null) {
		$this->stream = $stream;
	}

	public function getStream(): Stream {
		return $this->stream;
	}

	public function setStream(Stream $stream): self {
		$this->stream = $stream;

		return $this;
	}

	/** @return Person[] */
	public function getHomeViewers(): array {
		return $this->homeViewers;
	}

	/** @param Person[] $viewers */
	public function setHomeViewers(array $viewers): self {
		$this->homeViewers = $viewers;

		return $this;
	}

	public function addHomeViewer(Person $viewer): self {
		$this->homeViewers[] = $viewer;

		return $this;
	}

	public function getDirectViewers(): array {
		return $this->directViewers;
	}

	/**
	 * @param Person[] $viewers
	 */
	public function setDirectViewers(array $viewers): self {
		$this->directViewers = $viewers;

		return $this;
	}

	public function addDirectViewer(Person $viewer): self {
		$this->directViewers[] = $viewer;

		return $this;
	}

	public function isPublic(): bool {
		return $this->public;
	}

	public function setPublic(bool $public): self {
		$this->public = $public;

		return $this;
	}

	public function isFederated(): bool {
		return $this->federated;
	}

	public function setFederated(bool $federated): self {
		$this->federated = $federated;

		return $this;
	}

	public function jsonSerialize(): array {
		return [
			'stream' => $this->getStream(),
			'homeViewers' => $this->getHomeViewers(),
			'directViewers' => $this->getDirectViewers(),
			'public' => $this->isPublic(),
			'federated' => $this->isFederated()
		];
	}
}
