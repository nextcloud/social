<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\MediaStripMetadata;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\StoredMediaMetadataService;
use OCA\Social\Service\VideoTranscodeService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ social:media:strip-metadata`: the stored files are walked a page at a
 * time after the last nid seen, each outcome is counted, a dry run changes
 * nothing, and without ffmpeg nothing is touched at all.
 */
#[AllowMockObjectsWithoutExpectations]
class MediaStripMetadataTest extends TestCase {
	private CacheDocumentsRequest|MockObject $documents;
	private StoredMediaMetadataService|MockObject $stripper;
	private VideoTranscodeService|MockObject $transcoder;

	protected function setUp(): void {
		$this->documents = $this->createMock(CacheDocumentsRequest::class);
		$this->stripper = $this->createMock(StoredMediaMetadataService::class);
		$this->transcoder = $this->createMock(VideoTranscodeService::class);
		$this->transcoder->method('isAvailable')->willReturn(true);
	}

	public function testEveryPageIsWalkedAndEachOutcomeCounted(): void {
		$pages = [];
		$this->documents->method('getLocalMediaToStrip')->willReturnCallback(function (int $limit, int|string $after) use (&$pages): array {
			$pages[] = [$limit, (string)$after];

			return match ((string)$after) {
				'0' => array_map($this->document(...), range(1, 50)),
				'50' => [$this->document(51), $this->document(52), $this->document(53)],
				default => [],
			};
		});
		$this->stripper->method('strip')->willReturnCallback(fn (Document $d): string => match ((int)$d->getNid()) {
			51 => StoredMediaMetadataService::ON_BLUESKY,
			52 => StoredMediaMetadataService::FAILED,
			53 => StoredMediaMetadataService::ALREADY_CLEAN,
			default => StoredMediaMetadataService::STRIPPED,
		});

		$tester = $this->executed();

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame([[50, '0'], [50, '50'], [50, '53']], $pages);
		$this->assertStringContainsString('left as it is, its bytes are a Bluesky blob [51] https://cloud.example/documents/51', $tester->getDisplay());
		$this->assertStringContainsString(
			'50 rewritten, 1 already clean, 1 left because they are published on Bluesky, 0 without a stored file, 1 could not be rewritten',
			$tester->getDisplay()
		);
	}

	public function testADryRunRewritesNothing(): void {
		$this->documents->method('getLocalMediaToStrip')->willReturnCallback(
			fn (int $limit, int|string $after): array => ((string)$after === '0') ? [$this->document(1), $this->document(2)] : []
		);
		$this->stripper->expects($this->never())->method('strip');
		$this->stripper->method('isOnBluesky')->willReturnCallback(fn (Document $d): bool => (int)$d->getNid() === 2);

		$tester = $this->executed(['--dry-run' => true]);

		$this->assertStringContainsString('would rewrite https://cloud.example/documents/1', $tester->getDisplay());
		$this->assertStringContainsString('would leave (on Bluesky) https://cloud.example/documents/2', $tester->getDisplay());
		$this->assertStringContainsString('1 file(s) would be rewritten, 1 left as they are because they are published on Bluesky', $tester->getDisplay());
	}

	public function testTheLimitStopsTheWalk(): void {
		$this->documents->expects($this->once())->method('getLocalMediaToStrip')->with(2, '0')
			->willReturn([$this->document(1), $this->document(2)]);
		$this->stripper->expects($this->exactly(2))->method('strip')->willReturn(StoredMediaMetadataService::STRIPPED);

		$tester = $this->executed(['--limit' => '2']);

		$this->assertStringContainsString('2 rewritten', $tester->getDisplay());
	}

	public function testWithoutFfmpegNothingIsTouched(): void {
		$this->transcoder = $this->createMock(VideoTranscodeService::class);
		$this->transcoder->method('isAvailable')->willReturn(false);
		$this->documents->expects($this->never())->method('getLocalMediaToStrip');

		$tester = $this->executed();

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('ffmpeg was not found', $tester->getDisplay());
	}

	private function executed(array $input = []): CommandTester {
		$tester = new CommandTester(new MediaStripMetadata($this->documents, $this->stripper, $this->transcoder));
		$tester->execute($input);

		return $tester;
	}

	private function document(int $nid): Document {
		$document = new Document();
		$document->setNid($nid);
		$document->setId('https://cloud.example/documents/' . $nid);

		return $document;
	}
}
