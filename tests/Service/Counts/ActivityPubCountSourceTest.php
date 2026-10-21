<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Counts;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\Counts\ActivityPubCountSource;
use OCA\Social\Service\Counts\CountWriter;
use OCA\Social\Service\CurlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/** The counts of a fediverse post, as the document its server serves states them. */
#[AllowMockObjectsWithoutExpectations]
class ActivityPubCountSourceTest extends TestCase {
	private function note(string $id, bool $local = false): Note {
		$note = new Note();
		$note->setId($id);
		$note->setLocal($local);

		return $note;
	}

	public function testOnlyARemoteFediversePostIsAskedAbout(): void {
		$source = new ActivityPubCountSource($this->createStub(CurlService::class), $this->createStub(CountWriter::class));

		$this->assertTrue($source->supports($this->note('https://remote.example/notes/1')));
		$this->assertFalse($source->supports($this->note('https://here.example/notes/1', true)), 'this server counts its own');
		$this->assertFalse($source->supports($this->note('https://bsky.app/profile/did:plc:a/post/1')), 'Bluesky is asked through the AppView');
		$this->assertFalse($source->supports($this->note('https://bsky.app/profile/did:plc:a/convo/c/m')));
		$this->assertFalse($source->supports($this->note('urn:x:1')));
	}

	public function testTheStatedTotalsAreWrittenAndAnAnswerAboutSomethingElseOnlyStamped(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->once())->method('retrieveObjectsMany')
			->with(['https://remote.example/notes/1', 'https://remote.example/notes/2', 'https://remote.example/notes/3'], ['timeout' => 5])
			->willReturn([
				'https://remote.example/notes/1' => [
					'id' => 'https://remote.example/notes/1',
					'likes' => ['totalItems' => 10],
					'shares' => ['totalItems' => 4],
				],
				'https://remote.example/notes/2' => ['id' => 'https://remote.example/@x/2'],
				'https://remote.example/notes/3' => null,
			]);
		$writer = $this->createMock(CountWriter::class);
		$writer->expects($this->once())->method('write')->with('https://remote.example/notes/1', 10, 4, null)->willReturn(true);
		$stamped = [];
		$writer->method('stamp')->willReturnCallback(function (string $id) use (&$stamped): void {
			$stamped[] = $id;
		});

		$answered = (new ActivityPubCountSource($curl, $writer))->refresh([
			$this->note('https://remote.example/notes/1'),
			$this->note('https://remote.example/notes/2'),
			$this->note('https://remote.example/notes/3'),
		]);

		$this->assertSame(1, $answered);
		$this->assertSame(['https://remote.example/notes/2', 'https://remote.example/notes/3'], $stamped);
	}

	public function testCollectionUrlsAreFetchedTogetherAndMastodonQuoteCountIsKept(): void {
		$postId = 'https://remote.example/notes/1';
		$likes = $postId . '/likes';
		$replies = $postId . '/replies';
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->exactly(2))->method('retrieveObjectsMany')
			->willReturnCallback(static function (array $ids, array $options = [], ?array &$statuses = null) use ($postId, $likes, $replies): array {
				self::assertSame(['timeout' => 5], $options);
				if ($ids === [$postId]) {
					return [$postId => [
						'id' => $postId,
						'likes' => $likes,
						'shares' => ['totalItems' => 4],
						'replies' => ['id' => $replies],
						'quoteCount' => 2,
					]];
				}

				self::assertSame([$likes, $replies], $ids);

				return [
					$likes => ['id' => $likes, 'totalItems' => 10],
					$replies => ['id' => $replies, 'totalItems' => 3],
				];
			});
		$writer = $this->createMock(CountWriter::class);
		$writer->expects($this->once())->method('write')
			->willReturnCallback(static function (string $id, ?int $likesCount, ?int $boostsCount, ?int $repliesCount, array $merge = [], ?int $quotesCount = null) use ($postId): bool {
				self::assertSame($postId, $id);
				self::assertSame([10, 4, 3, 2], [$likesCount, $boostsCount, $repliesCount, $quotesCount]);

				return true;
			});

		$this->assertSame(1, (new ActivityPubCountSource($curl, $writer))->refresh([$this->note($postId)]));
	}

	/**
	 * A post its server says is not there (404) or gone (410) will say so
	 * again: it is stamped far ahead rather than asked about on every pass.
	 * A server that did not answer at all may yet, and is asked again in the
	 * usual interval.
	 */
	public function testAPostItsServerSaysIsGoneIsNotAskedAboutAgainForALongWhile(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->once())->method('retrieveObjectsMany')
			->willReturnCallback(static function (array $ids, array $options = [], ?array &$statuses = null): array {
				$statuses = [
					'https://remote.example/notes/1' => 404,
					'https://remote.example/notes/2' => 410,
				];

				return array_fill_keys($ids, null);
			});
		$writer = $this->createMock(CountWriter::class);
		$writer->expects($this->never())->method('write');
		$gone = [];
		$writer->method('stampGone')->willReturnCallback(function (string $id) use (&$gone): void {
			$gone[] = $id;
		});
		$stamped = [];
		$writer->method('stamp')->willReturnCallback(function (string $id) use (&$stamped): void {
			$stamped[] = $id;
		});

		(new ActivityPubCountSource($curl, $writer))->refresh([
			$this->note('https://remote.example/notes/1'),
			$this->note('https://remote.example/notes/2'),
			$this->note('https://remote.example/notes/3'),
		]);

		$this->assertSame(['https://remote.example/notes/1', 'https://remote.example/notes/2'], $gone);
		$this->assertSame(['https://remote.example/notes/3'], $stamped);
	}

	public function testDocumentsAreAskedForInRounds(): void {
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->exactly(2))->method('retrieveObjectsMany')->willReturn([]);
		$posts = [];
		for ($i = 0; $i < ActivityPubCountSource::PARALLEL + 1; $i++) {
			$posts[] = $this->note('https://remote.example/notes/' . $i);
		}

		$this->assertSame(0, (new ActivityPubCountSource($curl, $this->createStub(CountWriter::class)))->refresh($posts));
	}
}
