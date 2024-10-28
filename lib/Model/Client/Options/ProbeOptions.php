<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client\Options;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IRequest;

/**
 * Class ProbeOptions
 *
 * @package OCA\Social\Model\Client\Options
 */
class ProbeOptions extends CoreOptions implements JsonSerializable {
	use TArrayTools;

	public const HOME = 'home';
	public const PUBLIC = 'public';
	public const DIRECT = 'direct';
	public const ACCOUNT = 'account';
	public const FAVOURITES = 'favourites';
	public const HASHTAG = 'hashtag';
	public const NOTIFICATIONS = 'notifications';

	public const FOLLOWERS = 'followers';
	public const FOLLOWING = 'following';

	private string $probe = '';
	private bool $local = false;
	private bool $remote = false;
	private bool $onlyMedia = false;
	private int $minId = 0;
	private int $maxId = 0;
	private int $since = 0;
	private int $limit = 20;
	private bool $inverted = false;
	private string $argument = '';
	private array $types = [];
	private array $excludeTypes = [];
	private string $accountId = '';


	/**
	 * ProbeOptions constructor.
	 *
	 * @param IRequest|null $request
	 */
	public function __construct(?IRequest $request = null) {
		if ($request !== null) {
			$this->fromArray($request->getParams());
		}
	}


	/**
	 * @return string
	 */
	public function getProbe(): string {
		return $this->probe;
	}

	/**
	 * @param string $probe
	 *
	 * @return ProbeOptions
	 */
	public function setProbe(string $probe): self {
		$this->probe = strtolower($probe);

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isLocal(): bool {
		return $this->local;
	}

	/**
	 * @param bool $local
	 *
	 * @return ProbeOptions
	 */
	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isRemote(): bool {
		return $this->remote;
	}

	/**
	 * @param bool $remote
	 *
	 * @return ProbeOptions
	 */
	public function setRemote(bool $remote): self {
		$this->remote = $remote;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isOnlyMedia(): bool {
		return $this->onlyMedia;
	}

	/**
	 * @param bool $onlyMedia
	 *
	 * @return ProbeOptions
	 */
	public function setOnlyMedia(bool $onlyMedia): self {
		$this->onlyMedia = $onlyMedia;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getMinId(): int {
		return $this->minId;
	}

	/**
	 * @param int $minId
	 *
	 * @return ProbeOptions
	 */
	public function setMinId(int $minId): self {
		$this->minId = $minId;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getMaxId(): int {
		return $this->maxId;
	}

	/**
	 * @param int $maxId
	 *
	 * @return ProbeOptions
	 */
	public function setMaxId(int $maxId): self {
		$this->maxId = $maxId;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getSince(): int {
		return $this->since;
	}

	/**
	 * @param int $since
	 *
	 * @return ProbeOptions
	 */
	public function setSince(int $since): self {
		$this->since = $since;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getLimit(): int {
		return $this->limit;
	}

	/**
	 * @param int $limit
	 *
	 * @return ProbeOptions
	 */
	public function setLimit(int $limit): self {
		$this->limit = $limit;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isInverted(): bool {
		return $this->inverted;
	}

	/**
	 * @param bool $inverted
	 *
	 * @return ProbeOptions
	 */
	public function setInverted(bool $inverted): self {
		$this->inverted = $inverted;

		return $this;
	}


	/**
	 * @param string $argument
	 *
	 * @return ProbeOptions
	 */
	public function setArgument(string $argument): self {
		$this->argument = $argument;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getArgument(): string {
		return $this->argument;
	}


	/**
	 * @param array $types
	 *
	 * @return ProbeOptions
	 */
	public function setTypes(array $types): self {
		$this->types = $types;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getTypes(): array {
		return $this->types;
	}


	/**
	 * @param array $excludeTypes
	 *
	 * @return ProbeOptions
	 */
	public function setExcludeTypes(array $excludeTypes): self {
		$this->excludeTypes = $excludeTypes;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getExcludeTypes(): array {
		return $this->excludeTypes;
	}


	/**
	 * @param string $accountId
	 *
	 * @return ProbeOptions
	 */
	public function setAccountId(string $accountId): self {
		$this->accountId = $accountId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAccountId(): string {
		return $this->accountId;
	}


	/**
	 * @param array $arr
	 *
	 * @return ProbeOptions
	 */
	public function fromArray(array $arr): self {
		$this->setLocal($this->getBool('local', $arr, $this->isLocal()));
		$this->setRemote($this->getBool('remote', $arr, $this->isRemote()));
		$this->setOnlyMedia($this->getBool('only_media', $arr, $this->isOnlyMedia()));
		$this->setMinId($this->getInt('min_id', $arr, $this->getMinId()));
		$this->setMaxId($this->getInt('max_id', $arr, $this->getMaxId()));
		$this->setSince($this->getInt('since', $arr, $this->getSince()));
		$this->setLimit($this->getInt('limit', $arr, $this->getLimit()));
		$this->setArgument($this->get('argument', $arr, $this->getArgument()));

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return
			[
				'probe' => $this->getProbe(),
				'accountId' => $this->getAccountId(),
				'local' => $this->isLocal(),
				'remote' => $this->isRemote(),
				'only_media' => $this->isOnlyMedia(),
				'min_id' => $this->getMinId(),
				'max_id' => $this->getMaxId(),
				'since' => $this->getSince(),
				'limit' => $this->getLimit(),
				'argument' => $this->getArgument()
			];
	}
}
