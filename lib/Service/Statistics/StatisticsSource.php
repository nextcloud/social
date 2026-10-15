<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Statistics;

use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * What one network adds to the statistics page.
 *
 * The page counts from this server's rows, and most of what it counts reads
 * the same whatever network it came from. Two things do not: where an
 * account lives, which for a fediverse account is the host of its id and for
 * an account elsewhere is not, and where a post went, which the instance
 * paths of a post record only for the servers it was delivered to.
 */
interface StatisticsSource {
	/**
	 * The server each of these accounts lives on, for the accounts this
	 * network holds; the others are left out.
	 *
	 * @param list<string> $actorIds
	 *
	 * @return array<string, string> host by actor id
	 */
	public function homeServers(array $actorIds): array;

	/**
	 * How many of an account's posts went out on this network as well, which
	 * no instance path records; null when the network is not in use here.
	 *
	 * @param list<string> $postIds the account's posts that were counted
	 *
	 * @return array{network: string, posts: int}|null
	 */
	public function reached(Person $actor, array $postIds): ?array;
}
