<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\HashtagsRequest;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * The trends table has to hold a hashtag as long as the table it counts.
 *
 * `social_stream_tag.hashtag` is 127 characters and `social_hashtag.hashtag`
 * was 63, so a tag between the two lengths could be stored on a post and never
 * on its trend: the write failed outright, and since the failure was not
 * caught it ended the trends pass — leaving every hashtag after it in
 * iteration order unwritten, on that run and on every run after it.
 */
class HashtagLengthTest extends TestCase {
	use ReadsTheSchema;

	public function testATrendHoldsATagAsLongAsAPostCanCarry(): void {
		[$type, $options] = $this->column('social_hashtag', 'hashtag');

		$this->assertSame(Types::STRING, $type);
		$this->assertSame(HashtagsRequest::HASHTAG_MAX_LENGTH, $options['length']);
	}

	/** Both tables hold the same string, and once held it at two widths. */
	public function testBothTablesHoldATagAtTheSameWidth(): void {
		[, $trend] = $this->column('social_hashtag', 'hashtag');
		[, $onAPost] = $this->column('social_stream_tag', 'hashtag');

		$this->assertSame(
			$onAPost['length'],
			$trend['length'],
			'a followable tag could be one no post can carry'
		);
	}
}
