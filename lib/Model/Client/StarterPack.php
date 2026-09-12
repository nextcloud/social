<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * A named handful of accounts worth following, as Mastodon's starter packs are.
 *
 * The problem it answers is the one every new account has and no algorithm here
 * can: a timeline is empty until you follow somebody, and "who?" is unanswerable
 * from a graph you are not yet part of. `SuggestionService` needs a friend of a
 * friend to work with; on day one there are none, so it falls back to whoever
 * posted recently, which is a list of strangers sorted by luck.
 *
 * A pack is just a list of `user@host` handles. That is the whole data model,
 * and it is deliberately not a table of its own: the accounts are not this
 * instance's to own, the handles are the only durable reference to them, and
 * keeping rows would mean keeping rows about accounts that may never be fetched.
 *
 * The handles resolve to real profiles when a pack is opened -- never in the
 * index, because resolving an unknown remote handle means a WebFinger lookup and
 * an actor fetch, and doing six of those to draw a list of pack *names* would
 * make the page wait on other people's servers for nothing.
 */
class StarterPack implements JsonSerializable {
	/** Shipped with the app. */
	public const SOURCE_BUILTIN = 'builtin';
	/** Written by an administrator of this instance. */
	public const SOURCE_LOCAL = 'local';

	/** @var Person[] filled in only when the pack is opened */
	private array $accounts = [];

	/** @var string[] the handles that could not be resolved */
	private array $unresolved = [];

	/**
	 * @param string[] $handles `user@host`, without a leading `@`
	 */
	public function __construct(
		private string $slug,
		private string $name,
		private string $description,
		private array $handles,
		private string $source = self::SOURCE_BUILTIN,
	) {
	}

	public function getSlug(): string {
		return $this->slug;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getDescription(): string {
		return $this->description;
	}

	/** @return string[] */
	public function getHandles(): array {
		return $this->handles;
	}

	public function getSource(): string {
		return $this->source;
	}

	/** @return Person[] */
	public function getAccounts(): array {
		return $this->accounts;
	}

	/** @param Person[] $accounts */
	public function setAccounts(array $accounts): self {
		$this->accounts = $accounts;

		return $this;
	}

	/** @return string[] */
	public function getUnresolved(): array {
		return $this->unresolved;
	}

	/**
	 * The handles this instance could not reach.
	 *
	 * Reported rather than hidden: a pack that silently shrinks looks like a
	 * pack somebody wrote badly, when what actually happened is that a server
	 * was down or an account moved. An administrator curating packs needs to be
	 * able to tell those apart.
	 *
	 * @param string[] $unresolved
	 */
	public function setUnresolved(array $unresolved): self {
		$this->unresolved = $unresolved;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getSlug(),
			'name' => $this->getName(),
			'description' => $this->getDescription(),
			'source' => $this->getSource(),
			'size' => count($this->getHandles()),
			'accounts' => $this->getAccounts(),
			'unresolved' => $this->getUnresolved(),
		];
	}
}
