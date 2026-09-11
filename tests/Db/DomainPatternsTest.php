<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\DomainBlocksRequestBuilder;
use OCA\Social\Exceptions\InvalidResourceException;
use PHPUnit\Framework\TestCase;

/**
 * The patterns a domain purge deletes by.
 *
 * These are used to select rows for deletion, so a pattern that matches one
 * character too many deletes another instance's data. What they must match and
 * what they must not is checked here by applying them the way SQL would —
 * there is no database in this suite, and there does not need to be one to say
 * whether `good.example` matches `good.example.attacker.test`.
 */
class DomainPatternsTest extends TestCase {
	/** LIKE, as far as these patterns use it: `%` is anything, the rest is literal. */
	private function likeMatches(string $pattern, string $actorId): bool {
		$regex = '/^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$/';
		$regex = str_replace('\.\*', '.*', $regex);

		return preg_match($regex, strtolower($actorId)) === 1;
	}

	private function matchesAny(string $domain, string $actorId): bool {
		foreach (DomainBlocksRequestBuilder::domainPatterns($domain) as $pattern) {
			if ($this->likeMatches($pattern, $actorId)) {
				return true;
			}
		}

		return false;
	}

	public function testBothSchemesAnActorIdIsWrittenWithAreCovered(): void {
		$this->assertTrue($this->matchesAny('spam.example', 'https://spam.example/users/x'));
		$this->assertTrue($this->matchesAny('spam.example', 'http://spam.example/users/x'));
	}

	public function testAnActorIdIsMatchedWhateverItsCase(): void {
		$this->assertTrue($this->matchesAny('Spam.Example', 'HTTPS://SPAM.EXAMPLE/users/X'));
	}

	public function testALongerHostIsNotMatched(): void {
		// the pattern is closed by the `/` that ends the host, so somebody who
		// registers good.example.attacker.test is not purged by a block on
		// good.example — and, the other way round, cannot have a block on
		// their own domain reach the one they appended it to
		$this->assertFalse($this->matchesAny('good.example', 'https://good.example.attacker.test/users/x'));
	}

	public function testASubdomainIsNotPurgedWithItsParent(): void {
		// deliberately narrower than the block itself, which `isListed()`
		// widens to subdomains: refusing traffic from one instance too many is
		// undone by editing the list, and deleting one instance too many is
		// not. `occ social:domain:purge` takes whichever subdomain an admin
		// means to include
		$this->assertFalse($this->matchesAny('example.test', 'https://social.example.test/users/x'));
	}

	public function testTheHostIsNotMatchedHalfWayThrough(): void {
		$this->assertFalse($this->matchesAny('good.example', 'https://notgood.example/users/x'));
	}

	public function testAnotherInstanceNamedInThePathIsNotMatched(): void {
		$this->assertFalse($this->matchesAny('spam.example', 'https://other.test/users/spam.example/x'));
	}

	public function testAWildcardCannotReachThePattern(): void {
		// a `%` here would make the delete match every instance at once
		$this->expectException(InvalidResourceException::class);
		DomainBlocksRequestBuilder::domainPatterns('%');
	}

	public function testASingleCharacterWildcardCannotReachThePattern(): void {
		$this->expectException(InvalidResourceException::class);
		DomainBlocksRequestBuilder::domainPatterns('spam_example');
	}

	public function testAnEscapeCannotReachThePattern(): void {
		$this->expectException(InvalidResourceException::class);
		DomainBlocksRequestBuilder::domainPatterns('spam\\example');
	}

	public function testAnEmptyDomainIsRefused(): void {
		$this->expectException(InvalidResourceException::class);
		DomainBlocksRequestBuilder::domainPatterns('');
	}
}
