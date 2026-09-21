<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/** The five post fields that have columns of their own on `social_stream`. */
class StreamPostFieldColumnsTest extends TestCase {
	use ReadsTheSchema;

	public function testAllFiveFieldsHaveAColumn(): void {
		$columns = $this->columnNames('social_stream');

		foreach (['tags', 'language', 'updated', 'quote', 'quote_authorization'] as $field) {
			$this->assertContains($field, $columns);
		}
	}

	public function testTheTagArrayStaysJson(): void {
		// the `tag` array is heterogeneous — hashtags, mentions and emoji — and
		// its one queryable facet already has social_stream_tag. A column keeps
		// the re-export honest without a second source of truth for hashtags
		[$type] = $this->column('social_stream', 'tags');

		$this->assertSame(Types::TEXT, $type);
	}

	public function testTheLanguageIsBoundedAndIndexed(): void {
		[$type, $options] = $this->column('social_stream', 'language');

		$this->assertSame(Types::STRING, $type);
		// Stream::normalizeLanguage() cannot emit more than `xxx-Xxxx-XXX`
		$this->assertSame(15, $options['length']);

		$this->assertContains(
			[['language'], 'social_s_lang', false],
			$this->indexesOf('social_stream'),
			'the index is the only thing that makes a language filter work'
		);
	}

	public function testTheEditStampIsANullableDate(): void {
		[$type, $options] = $this->column('social_stream', 'updated');

		$this->assertSame(Types::DATETIME, $type);
		// a post that was never edited has no edit time, which is not the same
		// fact as one edited at the epoch
		$this->assertFalse($options['notnull']);
		$this->assertArrayNotHasKey('default', $options);
	}

	public function testTheTwoIdsAreStoredLikeEveryOtherActivityPubId(): void {
		foreach (['quote', 'quote_authorization'] as $column) {
			$this->assertSame(Types::TEXT, $this->column('social_stream', $column)[0]);
		}
	}

	public function testNeitherIdHasAPrimCompanion(): void {
		// `*_prim` exists so an existing lookup can be an indexed equality.
		// Nothing looks a post up by what it quotes, and quote_authorization is
		// only ever a URI a peer dereferences — an md5 column and its index on
		// the largest table in the app would cost every insert for no read
		$columns = $this->columnNames('social_stream');

		$this->assertNotContains('quote_prim', $columns);
		$this->assertNotContains('quote_authorization_prim', $columns);
	}
}
