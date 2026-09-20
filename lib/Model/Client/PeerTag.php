<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * One hashtag, as several servers report it.
 *
 * Not a Mastodon `Tag`: that entity describes a tag on the server answering
 * for it and has nowhere to say *whose* view this is. The whole point of this
 * one is the `servers` list -- a tag four servers are busy with is a different
 * recommendation from a tag one server is busy with, and a reader deciding
 * whether to follow it is owed the difference.
 *
 * `uses` is deliberately not the headline. Instances differ in size by four
 * orders of magnitude, so adding their counts together ranks the biggest
 * server's opinion above everybody else's; it is kept because it is what the
 * servers said, and used only to break ties between tags that the same number
 * of servers named.
 */
class PeerTag implements JsonSerializable {
	/** Lower-cased, without the leading '#': the key a tag is merged on. */
	private string $name;

	/** host => the label to show for it, in the order the servers were asked. */
	private array $servers = [];

	/** What those servers said, added up. A tie-breaker, not a ranking. */
	private int $uses = 0;

	/** Whether this instance has seen the tag as well. */
	private bool $local = false;

	public function __construct(string $name) {
		$this->name = $name;
	}

	public function getName(): string {
		return $this->name;
	}

	/**
	 * Records that a server reports this tag.
	 *
	 * A server that names the same tag twice -- Mastodon's trends and its
	 * search can both answer for it -- counts once, because what is being
	 * counted is servers and not answers.
	 */
	public function reportedBy(string $host, string $label, int $uses, bool $local = false): self {
		if (!array_key_exists($host, $this->servers)) {
			$this->servers[$host] = $label === '' ? $host : $label;
			$this->uses += max(0, $uses);
		}

		$this->local = $this->local || $local;

		return $this;
	}

	/** @return string[] the labels, in the order the servers were asked */
	public function getServers(): array {
		return array_values($this->servers);
	}

	/** How many servers named it: the number this list is ranked by. */
	public function countServers(): int {
		return count($this->servers);
	}

	public function getUses(): int {
		return $this->uses;
	}

	public function isLocal(): bool {
		return $this->local;
	}

	/**
	 * Whether any server other than this one named it.
	 *
	 * What the "elsewhere" half of the hashtags page is: a tag only this
	 * instance has seen belongs in its own trending list, which is already on
	 * the screen above.
	 */
	public function isRemote(): bool {
		return $this->countServers() > ($this->local ? 1 : 0);
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'name' => $this->name,
			'servers' => $this->getServers(),
			'servers_count' => $this->countServers(),
			'uses' => $this->uses,
			'local' => $this->local,
		];
	}
}
