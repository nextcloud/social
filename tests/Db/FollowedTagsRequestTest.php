<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\FollowedTagsRequest;
use PHPUnit\Framework\TestCase;

/**
 * The one thing about a followed tag that can be decided without a database:
 * which string a tag the user typed is stored and compared as.
 *
 * It has to be the form `social_stream_tag` holds and the form the hashtag
 * timeline compares by, or a followed tag matches no post — which is the
 * whole feature.
 */
class FollowedTagsRequestTest extends TestCase {
	public function testTheHashIsNotPartOfTheTag(): void {
		// `social_stream_tag` stores what Note::fillHashtags() produced, and
		// that strips the leading '#'
		$this->assertSame('nextcloud', FollowedTagsRequest::normalise('#nextcloud'));
		$this->assertSame('nextcloud', FollowedTagsRequest::normalise('nextcloud'));
	}

	public function testCaseIsNotPartOfTheTag(): void {
		// getTimelineHashtag() compares LOWER() to LOWER(), so #NextCloud and
		// #nextcloud are one tag to a reader and have to be one tag to follow
		$this->assertSame('nextcloud', FollowedTagsRequest::normalise('#NextCloud'));
		$this->assertSame('nextcloud', FollowedTagsRequest::normalise('NEXTCLOUD'));
	}

	public function testNonAsciiIsLoweredToo(): void {
		// strtolower() would leave these alone and store a tag that can never
		// equal the LOWER() the database computes
		$this->assertSame('österreich', FollowedTagsRequest::normalise('#Österreich'));
		$this->assertSame('ελλάδα', FollowedTagsRequest::normalise('#Ελλάδα'));
	}

	public function testSurroundingSpaceIsNotPartOfTheTag(): void {
		$this->assertSame('nextcloud', FollowedTagsRequest::normalise("  #nextcloud \n"));
	}

	public function testATagIsCutToWhatAPostCanCarry(): void {
		// social_stream_tag.hashtag is VARCHAR(127): a longer tag could be
		// followed and could never match a row, and on a strict MySQL the
		// insert would fail outright
		$long = str_repeat('a', 200);

		$normalised = FollowedTagsRequest::normalise('#' . $long);

		$this->assertSame(127, mb_strlen($normalised));
		$this->assertSame(str_repeat('a', 127), $normalised);
	}

	public function testTheCutCountsCharactersRatherThanBytes(): void {
		// VARCHAR(127) is 127 characters; substr() would cut a multi-byte tag
		// mid-character and store a broken one
		$normalised = FollowedTagsRequest::normalise(str_repeat('é', 200));

		$this->assertSame(127, mb_strlen($normalised));
		$this->assertSame(str_repeat('é', 127), $normalised);
	}

	public function testSomethingThatIsNotATagNormalisesToNothing(): void {
		// the controller turns this into a 422 rather than storing a row
		// nothing can ever match
		$this->assertSame('', FollowedTagsRequest::normalise('#'));
		$this->assertSame('', FollowedTagsRequest::normalise('   '));
		$this->assertSame('', FollowedTagsRequest::normalise(''));
	}
}
