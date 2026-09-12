<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Actor;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * This instance's own actor: the identity that signs outbound fetches.
 *
 * A signed GET names a `keyId`, and the peer answers it only after
 * dereferencing that key's owner. Whoever that owner is therefore appears in
 * every peer's logs as this instance's reader, and if their account is blocked
 * or suspended anywhere, every signed fetch from here fails there. Neither is
 * true of an actor that belongs to the server rather than to a person — which
 * is what Mastodon serves at `/actor`, and what this is.
 *
 * The document is written out here rather than inherited from `Person` because
 * almost nothing `Person` emits is true of it: it has no outbox, no followers,
 * no following, no bio, no aliases and no webfinger account. An actor document
 * naming collections that no route serves is worse than one that omits them —
 * a peer that dereferences `outbox` gets a 404 and may conclude the actor is
 * gone.
 */
class InstanceActor extends Application implements JsonSerializable {
	public function __construct() {
		parent::__construct();

		// Person's constructor sets `self::TYPE`, resolved at compile time
		// against Person: without this the document says `Person`.
		$this->setType(self::TYPE);
	}

	/**
	 * The `keyId` an outbound signature names, and the `publicKey.id` a peer
	 * has to find in this document for the two to be the same key.
	 */
	public function getKeyId(): string {
		return $this->getId() . '#main-key';
	}

	#[\Override]
	public function exportAsActivityPub(): array {
		return [
			'@context' => [
				ACore::CONTEXT_ACTIVITYSTREAMS,
				ACore::CONTEXT_SECURITY,
				ACore::CONTEXT_EXTENSIONS,
			],
			'id' => $this->getId(),
			'type' => self::TYPE,
			'preferredUsername' => $this->getPreferredUsername(),
			'name' => $this->getName(),
			'url' => $this->getId(),
			// An actor with no inbox is not an actor: Mastodon drops a fetched
			// actor document whose `inbox` is blank, and would then have no key
			// to check our signature against. The shared inbox is a real
			// endpoint and the right one — anything addressed to this actor is
			// addressed to the server.
			'inbox' => $this->getInbox(),
			'endpoints' => ['sharedInbox' => $this->getSharedInbox()],
			'publicKey' => [
				'id' => $this->getKeyId(),
				'owner' => $this->getId(),
				'publicKeyPem' => $this->getPublicKey(),
			],
			// Not a person: nothing here accepts a follow, nothing lists it in
			// a directory, nothing indexes it. `manuallyApprovesFollowers` is
			// the only way to say "do not show a Follow button" that every
			// implementation reads, and no Follow addressed here is ever
			// answered — the inbox has no local account to resolve it to.
			'manuallyApprovesFollowers' => true,
			'discoverable' => false,
			'indexable' => false,
		];
	}

	/**
	 * The instance actor has no client-facing form: it is not an account, and
	 * nothing in the Mastodon API should ever return it.
	 */
	#[\Override]
	public function exportAsLocal(): array {
		return [];
	}

	#[\Override]
	public function jsonSerialize(): array {
		return $this->exportAsActivityPub();
	}
}
