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
use OCA\Social\Model\Moderation;

/**
 * Mastodon's `Admin::Account`: what a moderator is shown about an account,
 * which is the ordinary `Account` entity plus what the instance has decided
 * about it.
 *
 * Every key Mastodon documents is emitted, including the ones this app has
 * nothing behind — a client that declares a field non-optional cannot decode
 * the entity when a key is missing, and Ivory, Mona and everything else built
 * on Swift's Codable declare most of them that way. Each such key carries the
 * empty value of its type rather than an invented one:
 *
 *  - `email`, `ip`, `ips`, `locale`, `invite_request`: this instance holds no
 *    address, no address history and no application text *for the fediverse
 *    account*. A local account belongs to a Nextcloud user whose email the
 *    admin can read where Nextcloud keeps it; republishing it through the
 *    fediverse API would put it in the cache of every client that asks.
 *  - `role`: `null`, which is also what Mastodon sends for an account it does
 *    not hold the user of. This app has no roles — being a Nextcloud
 *    administrator is a property of the Nextcloud account, not of the actor —
 *    so there is no role to report for anybody.
 *  - `disabled`, `sensitized`: no state in this app corresponds to either.
 *    Neither can ever be true, and the routes that would set them say so.
 *  - `created_by_application_id`, `invited_by_account_id`: accounts are not
 *    created through the client API here and there are no invites.
 *
 * `confirmed` and `approved` are `true` for a local account and `false` for a
 * remote one, which is Mastodon's own rule: both describe a *user* of this
 * instance, and a cached remote actor has none. A local actor exists because a
 * Nextcloud user exists, so there is nothing left to confirm or approve.
 *
 * `id` is the numeric id every other entity of this API emits — except for an
 * account whose cached actor a suspension purged, where it is the actor's
 * ActivityPub id: the numeric id is a column of the cached copy that is gone,
 * and an id that cannot be sent back to `/unsuspend` would leave the
 * suspension impossible to lift over the API. Both forms are accepted
 * wherever a route takes an account.
 */
class AdminAccount implements JsonSerializable {
	private string $id = '';
	private string $actorId = '';
	private string $username = '';
	private ?string $domain = null;
	private int $creation = 0;
	private bool $local = true;
	private string $level = '';
	private ?Person $account = null;

	/**
	 * The account as it is known here, under the decision that stands against
	 * it.
	 *
	 * @param string $level one of Moderation::LEVELS, or '' for none
	 */
	public static function fromPerson(Person $actor, string $level = ''): self {
		$actor->setExportFormat(ACore::FORMAT_LOCAL);

		$admin = new self();
		$admin->setActorId($actor->getId())
			->setId($actor->getNid() > 0 ? (string)$actor->getNid() : $actor->getId())
			->setUsername($actor->getPreferredUsername())
			->setLocal($actor->isLocal())
			->setDomain($actor->isLocal() ? null : self::hostOf($actor->getAccount(), $actor->getId()))
			->setCreation($actor->getCreation())
			->setLevel($level)
			->setAccount($actor);

		return $admin;
	}

	/**
	 * An account known only by the decision standing against it.
	 *
	 * A suspension deletes the cached actor, so this is what a suspended
	 * account looks like once that has happened: the actor's id, what can be
	 * read off it, and nothing else. The `account` entity is built from the
	 * same facts rather than sent as null — Mastodon declares it non-optional,
	 * and every field this instance no longer holds comes out of it empty.
	 */
	public static function fromDecision(Moderation $decision, bool $local = false): self {
		$actorId = $decision->getActorId();
		$host = self::hostOf('', $actorId);

		$actor = new Person();
		$actor->setPreferredUsername(self::usernameOf($actorId))
			->setAccount(self::usernameOf($actorId) . ($host === null ? '' : '@' . $host))
			->setCreation($decision->getCreation());
		$actor->setId($actorId);
		$actor->setLocal($local);
		$actor->setExportFormat(ACore::FORMAT_LOCAL);

		$admin = new self();
		$admin->setActorId($actorId)
			->setId($actorId)
			->setUsername($actor->getPreferredUsername())
			->setLocal($local)
			->setDomain($local ? null : $host)
			->setCreation($decision->getCreation())
			->setLevel($decision->getLevel())
			->setAccount($actor);

		return $admin;
	}

	public function setId(string $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): string {
		return $this->id;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setUsername(string $username): self {
		$this->username = $username;

		return $this;
	}

	public function getUsername(): string {
		return $this->username;
	}

	public function setDomain(?string $domain): self {
		$this->domain = $domain;

		return $this;
	}

	public function getDomain(): ?string {
		return $this->domain;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}

	public function isLocal(): bool {
		return $this->local;
	}

	public function setLevel(string $level): self {
		$this->level = $level;

		return $this;
	}

	public function getLevel(): string {
		return $this->level;
	}

	public function isSilenced(): bool {
		return $this->level === Moderation::SILENCE;
	}

	public function isSuspended(): bool {
		return $this->level === Moderation::SUSPEND;
	}

	public function setAccount(?Person $account): self {
		$this->account = $account;

		return $this;
	}

	public function getAccount(): ?Person {
		return $this->account;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'username' => $this->getUsername(),
			'domain' => $this->getDomain(),
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getCreation()) . '.000Z',
			'email' => '',
			'ip' => null,
			'ips' => [],
			'locale' => '',
			'invite_request' => null,
			'role' => null,
			'confirmed' => $this->isLocal(),
			'approved' => $this->isLocal(),
			'disabled' => false,
			'silenced' => $this->isSilenced(),
			'suspended' => $this->isSuspended(),
			'sensitized' => false,
			'created_by_application_id' => null,
			'invited_by_account_id' => null,
			'account' => $this->getAccount(),
		];
	}

	/**
	 * The instance an account belongs to: the host of its handle, or of its
	 * actor id when there is no handle to read one from.
	 */
	private static function hostOf(string $account, string $actorId): ?string {
		$account = trim($account);
		$at = strrpos($account, '@');
		if ($at !== false && $at < strlen($account) - 1) {
			return strtolower(substr($account, $at + 1));
		}

		$host = strtolower((string)parse_url($actorId, PHP_URL_HOST));

		return ($host === '') ? null : $host;
	}

	/**
	 * The username of an actor known only by its id: the last segment of the
	 * path, which is where every implementation puts it.
	 */
	private static function usernameOf(string $actorId): string {
		$path = (string)parse_url($actorId, PHP_URL_PATH);
		$name = substr($path, (int)strrpos($path, '/') + 1);

		return ltrim($name, '@');
	}
}
