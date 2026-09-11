<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\DomainBlocksRequestBuilder;
use OCA\Social\Db\MuteExpiryRequestBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The two decisions the timeline filters make that are not SQL.
 *
 * The SQL itself — the anti-join against the blocked instances, and the expiry
 * join that makes a mute stop applying — needs a real database and is exercised
 * by the integration suite — the standalone suite has no DBAL, so the query
 * builder these are handed cannot even be mocked here. What is checked is which
 * rows each of them is pointed at, which is where both of them would silently
 * stop working: a filter that only looks at the author of a post lets a blocked
 * instance and a muted account through anybody who boosts them.
 */
class RelationFiltersTest extends TestCase {
	public function testADomainBlockIsAppliedToTheBoosterAndToWhatWasBoosted(): void {
		$this->assertSame(
			['s.attributed_to', 'hd_o.attributed_to'],
			DomainBlocksRequestBuilder::authorColumns('s', 'hd_o')
		);
	}

	public function testAQueryWithNoBoostedRowIsFilteredOnItsOwnAuthorAlone(): void {
		$this->assertSame(
			['s.attributed_to'],
			DomainBlocksRequestBuilder::authorColumns('s', '')
		);
	}

	public function testAnActorIdIsMatchedUnderBothSchemesItCanBeWrittenWith(): void {
		// an instance reachable over http only is an instance that can be
		// blocked, and matching one scheme would leave it unblockable
		$this->assertSame(['https://', 'http://'], DomainBlocksRequestBuilder::SCHEMES);
	}

	public function testAMuteExpiryIsLookedUpForTheBoosterAndForWhatWasBoosted(): void {
		$this->assertSame(
			['s.attributed_to_prim', 'hd_o.attributed_to_prim'],
			MuteExpiryRequestBuilder::authorColumns('s', 'hd_o')
		);
	}
}
