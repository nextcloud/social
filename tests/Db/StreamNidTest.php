<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `nid` is the primary key of social_stream and the status id Mastodon clients
 * are given. It is drawn, not allocated, so two posts in the same second can
 * draw the same number -- and save() used to swallow the constraint violation
 * that follows without even logging it, so the post was silently lost.
 *
 * The draw itself needs a database to exercise, which the unit suite has none
 * of, so the arithmetic properties are checked directly and the rest is read
 * out of the source the way StreamSensitiveColumnTest does.
 */
class StreamNidTest extends TestCase {
	private const SOURCE = __DIR__ . '/../../lib/Db/StreamRequest.php';

	private function constant(string $name): int {
		return (int)(new ReflectionClass(StreamRequest::class))->getConstant($name);
	}

	public function testTheIdSpaceIsWideEnoughToMakeACollisionRare(): void {
		// birthday bound: an even chance of a collision arrives at about
		// sqrt(2 * limit * ln 2) posts sharing one second. At 1e6 that is ~1,200,
		// which a busy instance can reach.
		$limit = $this->constant('NID_LIMIT');
		$evenOdds = sqrt(2 * $limit * M_LN2);

		$this->assertGreaterThanOrEqual(
			1000000000,
			$limit,
			'the random half of a nid is too narrow to rely on'
		);
		$this->assertGreaterThan(30000, $evenOdds);
	}

	public function testNidsStayOrderedByPublicationTimeAcrossTheWidening(): void {
		// cursor pagination orders on nid, so ids issued under the old 1e6 width
		// must still sort before ids issued under the new one.
		$old = static fn (int $t, int $r): int => $t * 1000000 + $r;
		$new = fn (int $t, int $r): int => $t * $this->constant('NID_LIMIT') + $r;

		$earlier = $old(1_700_000_000, 999_999);
		$later = $new(1_700_000_001, 1);

		$this->assertLessThan($later, $earlier);
		$this->assertLessThan($new(1_700_000_002, 1), $new(1_700_000_001, 999_999_999));
	}

	public function testTheTopOfTheIdSpaceStillFitsABigint(): void {
		// published_time * NID_LIMIT must not overflow the BIGINT column, with
		// room left for dates well beyond now.
		$year2100 = 4_102_444_800;
		$highest = $year2100 * $this->constant('NID_LIMIT') + $this->constant('NID_LIMIT');

		$this->assertLessThan(9223372036854775807, $highest);
	}

	public function testTheDrawIsNotPredictable(): void {
		$source = (string)file_get_contents(self::SOURCE);

		$this->assertStringContainsString(
			'random_int(1, self::NID_LIMIT)',
			$source,
			'the nid is drawn with rand(), which is seeded per process and predictable'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/[^_a-zA-Z]rand\(/',
			$source,
			'rand() is still used somewhere in this file'
		);
	}

	public function testACollidingNidIsRetriedRatherThanDropped(): void {
		$source = (string)file_get_contents(self::SOURCE);

		$this->assertGreaterThan(
			1,
			$this->constant('NID_ATTEMPTS'),
			'a single attempt cannot recover from a collision'
		);
		$this->assertStringContainsString(
			'$stream->setNid(0);',
			$source,
			'save() does not draw a fresh nid after a constraint violation, so the post is lost'
		);
		$this->assertMatchesRegularExpression(
			'/\$this->has\(\$stream->getId\(\)\)/',
			$source,
			'save() cannot tell a duplicate delivery from a nid collision, so it will '
			. 'either lose posts or retry idempotent saves'
		);
	}
}
