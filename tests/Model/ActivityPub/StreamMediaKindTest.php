<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What goes into `social_stream.media_kind`, and why the set of answers is
 * closed.
 *
 * The column is seven characters wide and what fills it is read out of stored
 * attachment JSON — written by older versions of this app and by every server
 * it federates with. `Document::convertToMediaAttachment()` has narrowed an
 * attachment's type to four words since "Files as attachments", but a row
 * written before that kept the whole first half of the MIME type. A PDF
 * attached in 0.23.0 is stored as `"type":"application"`, which is eleven
 * characters, and the backfill wrote it straight into the column: on MySQL in
 * strict mode, *Data too long for column 'media_kind'*, and an instance with a
 * PDF in its history could not be upgraded at all.
 */
class StreamMediaKindTest extends TestCase {
	/** What `Version1000Date20260917000003` declares. */
	private const COLUMN_WIDTH = 7;

	/** @param array<int, array<string, string>> $attachments */
	private function kindOf(array $attachments, string $subType = ''): string {
		return Stream::mediaKindOf((string)json_encode($attachments), $subType);
	}

	/**
	 * The one that broke the upgrade. Anything this can return has to fit the
	 * column, whatever a stranger's server or an older version of this app put
	 * in the row.
	 *
	 * @param array<int, array<string, string>> $attachments
	 */
	#[DataProvider('storedAttachments')]
	public function testEveryAnswerFitsTheColumn(array $attachments): void {
		$kind = $this->kindOf($attachments);

		$this->assertLessThanOrEqual(
			self::COLUMN_WIDTH,
			strlen($kind),
			'media_kind is ' . self::COLUMN_WIDTH . ' characters and this answer is "' . $kind . '"'
		);
	}

	/** @return array<string, array{array<int, array<string, string>>}> */
	public static function storedAttachments(): array {
		return [
			// exactly what an instance upgrading from the app store had in it
			'a PDF, as 0.23.0 stored one' => [[['type' => 'application', 'media_type' => 'application/pdf']]],
			'a plain text file, as 0.23.0 stored one' => [[['type' => 'text', 'media_type' => 'text/plain']]],
			'something a peer made up' => [[['type' => 'application/vnd.oasis.opendocument.text']]],
			'a picture' => [[['type' => 'image']]],
			'a picture and a video' => [[['type' => 'image'], ['type' => 'video']]],
			'nothing at all' => [[]],
		];
	}

	/**
	 * A file a client cannot render is not a kind of media, which is what this
	 * app decides for a PDF attached *today* — the conversion calls it
	 * `unknown` and `mediaKindOf()` skips it. An old row now gets the same
	 * answer as a new one about the same file.
	 */
	public function testALegacyFileTypeIsTreatedExactlyAsUnknownIs(): void {
		$this->assertSame(
			$this->kindOf([['type' => 'unknown']]),
			$this->kindOf([['type' => 'application']])
		);
	}

	public function testAPictureBesideAFileIsAPicture(): void {
		$this->assertSame('image', $this->kindOf([['type' => 'application'], ['type' => 'image']]));
	}

	public function testTwoKindsAreMixed(): void {
		$this->assertSame(
			Stream::MEDIA_KIND_MIXED, $this->kindOf([['type' => 'image'], ['type' => 'video']])
		);
	}

	/** Its whole existence is the video, attachment or none. */
	public function testAPeerTubeVideoIsAVideo(): void {
		$this->assertSame('video', $this->kindOf([], 'Video'));
	}

	/** The vocabulary is the contract the column width was chosen against. */
	public function testTheDeclaredVocabularyFitsTheColumn(): void {
		$vocabulary = array_merge(
			Stream::MEDIA_KINDS, [Stream::MEDIA_KIND_NONE, Stream::MEDIA_KIND_MIXED]
		);

		foreach ($vocabulary as $kind) {
			$this->assertLessThanOrEqual(self::COLUMN_WIDTH, strlen($kind), $kind);
		}
	}

	/** Malformed JSON is a post with nothing attached, not an exception. */
	public function testARowThatWillNotDecodeIsNotMedia(): void {
		$this->assertSame(Stream::MEDIA_KIND_NONE, Stream::mediaKindOf('not json', ''));
	}
}
