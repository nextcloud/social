<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\VideoPosterBackfill;
use OCA\Social\Service\VideoThumbnailService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Poster frames for the stored videos that have none, a few a run, each video
 * once, and only the posts carrying each rewritten to show it.
 */
#[AllowMockObjectsWithoutExpectations]
class VideoPosterBackfillTest extends TestCase {
	private CacheDocumentsRequest&MockObject $documents;
	private CacheDocumentService&MockObject $cacheDocumentService;
	private VideoThumbnailService&MockObject $thumbnails;
	private StreamRequest&MockObject $streamRequest;
	private ConfigService&MockObject $config;
	/** @var array<string, string> the app settings as the run leaves them */
	private array $settings = [];
	private VideoPosterBackfill $backfill;

	protected function setUp(): void {
		$this->documents = $this->createMock(CacheDocumentsRequest::class);
		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);
		$this->thumbnails = $this->createMock(VideoThumbnailService::class);
		$this->thumbnails->method('isAvailable')->willReturn(true);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->settings[$key] ?? '');
		$this->config->method('setAppValue')->willReturnCallback(function (string $key, $value): void {
			$this->settings[$key] = (string)$value;
		});

		$this->backfill = new VideoPosterBackfill(
			$this->documents, $this->cacheDocumentService, $this->thumbnails, $this->streamRequest, $this->config, new NullLogger()
		);
	}

	private function video(int $nid, string $parent = ''): Document {
		$document = new Document();
		$document->setNid($nid);
		$document->setId('https://cloud.example/documents/' . $nid);
		$document->setParentId($parent);
		$document->setMediaType('video/mp4');
		$document->setLocalCopy('uuid-' . $nid);

		return $document;
	}

	public function testTheNextVideosGetAPosterAndThePostsCarryingThemShowIt(): void {
		$upload = $this->video(11);
		$cached = $this->video(12, 'https://remote.example/notes/1');
		$this->documents->expects($this->once())->method('getVideosWithoutPoster')
			->with(VideoPosterBackfill::BATCH, '0')->willReturn([$upload, $cached]);
		$this->cacheDocumentService->method('generatePoster')->willReturn(true);
		$this->documents->expects($this->exactly(2))->method('updatePoster');
		$this->streamRequest->expects($this->exactly(2))->method('updateLocalAttachmentCopies');
		$this->streamRequest->expects($this->once())->method('updateAttachments')->with($cached);

		$this->assertSame(2, $this->backfill->next());
		$this->assertSame('12', $this->settings[ConfigService::SOCIAL_POSTER_CURSOR]);
	}

	/** One unreadable video is passed over, not tried again every run, and stops nothing. */
	public function testAVideoWithoutAFrameIsLeftBehind(): void {
		$this->settings[ConfigService::SOCIAL_POSTER_CURSOR] = '10';
		$this->documents->expects($this->once())->method('getVideosWithoutPoster')
			->with(VideoPosterBackfill::BATCH, '10')
			->willReturn([$this->video(11), $this->video(12), $this->video(13)]);
		$this->cacheDocumentService->method('generatePoster')->willReturnCallback(static function (Document $document): bool {
			if ($document->getNid() === 12) {
				throw new \RuntimeException('not a video at all');
			}

			return $document->getNid() !== 11;
		});
		$this->documents->expects($this->once())->method('updatePoster');

		$this->assertSame(1, $this->backfill->next());
		$this->assertSame('13', $this->settings[ConfigService::SOCIAL_POSTER_CURSOR]);
	}

	/** Without ffmpeg nothing is walked, so the videos are done once it is installed. */
	public function testNothingMovesWhileTheServerHasNoFfmpeg(): void {
		$thumbnails = $this->createStub(VideoThumbnailService::class);
		$thumbnails->method('isAvailable')->willReturn(false);
		$this->documents->expects($this->never())->method('getVideosWithoutPoster');
		$this->config->expects($this->never())->method('setAppValue');

		$backfill = new VideoPosterBackfill(
			$this->documents, $this->cacheDocumentService, $thumbnails, $this->streamRequest, $this->config, new NullLogger()
		);

		$this->assertSame(0, $backfill->next());
	}

	public function testACursorThatIsNotANidStartsFromTheBeginning(): void {
		$this->settings[ConfigService::SOCIAL_POSTER_CURSOR] = 'garbage';
		$this->documents->expects($this->once())->method('getVideosWithoutPoster')
			->with(VideoPosterBackfill::BATCH, '0')->willReturn([]);

		$this->assertSame(0, $this->backfill->next());
		$this->assertSame('0', $this->settings[ConfigService::SOCIAL_POSTER_CURSOR]);
	}
}
