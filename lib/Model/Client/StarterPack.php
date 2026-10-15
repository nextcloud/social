<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
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
 * The exception is a pack read in from another server, a fediverse
 * collection or a starter pack Bluesky suggests (`CollectionPackService`),
 * which is named by actor ids, carries who curated it and where it lives,
 * and is kept in `social_starter_pack` between reads.
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
	/** A collection another server publishes, read in (FEP-7aa9). */
	public const SOURCE_FEDIVERSE = 'fediverse';
	/** A starter pack Bluesky suggests, read in. */
	public const SOURCE_BLUESKY = 'bluesky';

	/** Who curated a pack read in, as `user@host`; '' for the others. */
	private string $curator = '';

	/** Where a pack read in can be seen on its own server; '' for the others. */
	private string $url = '';

	/** The hashtag a pack read in is about, without the `#`. */
	private string $topic = '';

	/** The language a pack read in is written in, where it says. */
	private string $language = '';

	/** @var Person[] filled in only when the pack is opened */
	private array $accounts = [];

	/** @var string[] the handles that could not be resolved */
	private array $unresolved = [];

	/**
	 * @param string[] $handles `user@host`, without a leading `@`, or the
	 *                          actor ids of a pack read in
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

	public function getCurator(): string {
		return $this->curator;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function getTopic(): string {
		return $this->topic;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	/** Where a pack read in came from. */
	public function setOrigin(string $curator, string $url, string $topic, string $language): self {
		$this->curator = $curator;
		$this->url = $url;
		$this->topic = $topic;
		$this->language = $language;

		return $this;
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
		// A `Person` serialises as an ActivityPub actor unless it is told
		// otherwise, and what asks for a pack is a client reading Mastodon
		// `Account` entities -- `acct`, `display_name`, `avatar`. Handing it
		// `preferredUsername` and an `@context` is not a thinner answer but a
		// different one: every field it reads is absent, so an opened pack drew
		// a row per account with no name, no handle and no picture in it.
		foreach ($this->accounts as $account) {
			$account->setExportFormat(ACore::FORMAT_LOCAL);
		}

		return [
			'id' => $this->getSlug(),
			'name' => $this->getName(),
			'description' => $this->getDescription(),
			'source' => $this->getSource(),
			'curator' => ($this->curator !== '') ? $this->curator : null,
			'url' => ($this->url !== '') ? $this->url : null,
			'topic' => ($this->topic !== '') ? $this->topic : null,
			'language' => ($this->language !== '') ? $this->language : null,
			'size' => count($this->getHandles()),
			'accounts' => $this->getAccounts(),
			'unresolved' => $this->getUnresolved(),
		];
	}
}
