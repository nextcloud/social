<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use PHPUnit\Framework\TestCase;

/**
 * Which rows each read is allowed to return.
 *
 * Every one of these is a WHERE clause, and a WHERE clause needs a database to
 * exercise: the unit suite has none, and it cannot even build the query —
 * `IExpressionBuilder` is declared in terms of a Doctrine class the standalone
 * suite does not carry (see tests/Helper/doctrine-parameter-types.php), so a
 * mocked query builder cannot hand one back. The predicates are therefore read
 * out of the source, the way `StreamPostFieldsWriteTest` and `StreamNidTest`
 * read theirs, and the rows they select are exercised by the integration suite.
 */
class StreamReadPredicatesTest extends TestCase {
	private const SOURCE = __DIR__ . '/../../lib/Db/StreamRequest.php';
	private const TAGS_SOURCE = __DIR__ . '/../../lib/Db/StreamTagsRequest.php';

	/** The body of one method, from its signature to the next one at the same indentation. */
	private function methodBody(string $source, string $name): string {
		$file = (string)file_get_contents($source);
		$this->assertMatchesRegularExpression(
			'/function ' . preg_quote($name, '/') . '\(/',
			$file,
			$name . '() is gone; this test is about what it selects'
		);

		$body = preg_split('/function ' . preg_quote($name, '/') . '\(/', $file, 2)[1] ?? '';

		return preg_split('/\n\t\}\n/', $body, 2)[0] ?? '';
	}

	/**
	 * A direct message's recipient row is keyed on the viewer's own id, not on
	 * the public collection, so a descendants query that allows only public
	 * rows can never match one: `GET /statuses/{id}/context` on a direct thread
	 * answered with the ancestors and no replies at all, and every reply sent
	 * to the viewer vanished from the conversation in every client. The single
	 * status and the ancestor walk have always allowed them.
	 */
	public function testTheRepliesOfAThreadAreReadTheWayASingleStatusIs(): void {
		$this->assertStringContainsString(
			"limitToViewer('sd', 'f', true, true)",
			$this->methodBody(self::SOURCE, 'getRepliesTo'),
			'the descendants of a direct thread cannot match a public-only predicate'
		);
	}

	/**
	 * An accepted follower reads a followers-only post in their home timeline;
	 * the author's profile used to deny it exists, so
	 * `/api/v1/accounts/{id}/statuses` looked emptier than home and a pinned
	 * followers-only post was unreachable.
	 */
	public function testAProfileIsReadAsTheViewerRatherThanAsTheAnonymousInternet(): void {
		$body = $this->methodBody(self::SOURCE, 'accountTimelineNids');

		$this->assertStringContainsString(
			"limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT)",
			$body,
			'a profile read by somebody else forces the public collection'
		);
		$this->assertStringNotContainsString(
			'ACore::CONTEXT_PUBLIC',
			$body,
			'the public collection is still forced somewhere in this page'
		);
		$this->assertStringContainsString(
			'$this->getStreamNidsSelectSql($this->viewer !== null)',
			$body,
			'a post matches several recipient rows for a reader, so the page has to be DISTINCT'
		);
	}

	/**
	 * A tag used only inside a private team thread was written into the trend
	 * counters and surfaced in `/api/v1/trends/tags`, `tagHistory()` and search
	 * with its usage count. `HashtagsRequest::related()` restricts to public
	 * posts for exactly this reason.
	 */
	public function testTrendsAreCountedOverPublicPostsOnly(): void {
		$body = $this->methodBody(self::SOURCE, 'countHashtagsSince');

		$this->assertStringContainsString(
			"->andWhere(\$expr->eq(\n\t\t\t\t's.visibility', \$qb->createNamedParameter(Stream::TYPE_PUBLIC)\n\t\t\t))",
			$body,
			'a followers-only post or a direct message still counts towards the trends'
		);
		$this->assertStringContainsString(
			'$qb->limitToStatusTypes()',
			$body,
			'anything with a tag row counts, not only a status'
		);
	}

	/**
	 * A poll is a `Question`, which extends `Note` and carries hashtags,
	 * attachments and a media kind like any other post. Comparing the type name
	 * stored a poll with all of them at their defaults, so `#election who
	 * wins?` reached neither the tag timeline nor a followed-tag home page.
	 */
	public function testEveryKindOfStatusIsStoredWithItsNoteFields(): void {
		foreach ([self::SOURCE, self::TAGS_SOURCE] as $source) {
			$this->assertDoesNotMatchRegularExpression(
				'/getType\(\)\s*[!=]==\s*Note::TYPE/',
				(string)file_get_contents($source),
				'a poll is judged by its type name, so its hashtags and attachments are dropped'
			);
		}

		$this->assertStringContainsString(
			'if ($stream instanceof Note) {',
			$this->methodBody(self::SOURCE, 'save'),
			'save() does not write the note fields of a poll'
		);
	}

	/**
	 * `social_stream_tag` is what puts a post in a hashtag timeline, and an
	 * edit rewrites the `hashtags` column without it: a tag added by an edit
	 * rendered as dead text and reached nobody, and a tag removed left the post
	 * in that timeline for ever. Remote edits come through the same method.
	 */
	public function testAnEditRewritesTheTagRowsInTheSameStatementGroup(): void {
		$body = $this->methodBody(self::SOURCE, 'update');

		$this->assertStringContainsString('$this->dbConnection->beginTransaction()', $body);
		$this->assertStringContainsString('$this->streamTagsRequest->replaceStreamTags($stream)', $body);
		$this->assertStringContainsString('$this->dbConnection->rollBack()', $body);
	}
}
