<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoVideoRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\StoredMediaMetadataService;
use OCA\Social\Service\VideoTranscodeService;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IBinaryFinder;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A stored video or sound file rewritten without its container metadata.
 *
 * ffmpeg is a shell script standing in for it, which writes a fixed body to
 * the output it was given (or copies the input there, or fails); where a
 * real ffmpeg is installed a generated MP4 stating a location goes through
 * it as well. Pinned: the rewritten bytes are stored under a new uuid, the
 * document's size and the posts carrying it follow, the old file is
 * deleted — and a file that is a Bluesky blob, is missing, comes back the
 * same or cannot be rewritten is left exactly as it is.
 */
#[AllowMockObjectsWithoutExpectations]
class StoredMediaMetadataServiceTest extends TestCase {
	private const OLD = 'old-uuid';
	private const NEW = 'new-uuid';

	private CacheDocumentsRequest|MockObject $documents;
	private CacheDocumentService|MockObject $cache;
	private AtprotoBlobRequest|MockObject $blobs;
	private AtprotoVideoRequest|MockObject $videos;
	private StreamRequest|MockObject $streams;
	/** @var string[] */
	private array $tempFiles = [];
	/** what was handed to storeFile(), read while it was still there */
	private ?string $stored = null;

	protected function setUp(): void {
		if (!function_exists('proc_open') || !is_executable('/bin/sh')) {
			$this->markTestSkipped('no shell to stand in for ffmpeg');
		}

		$this->documents = $this->createMock(CacheDocumentsRequest::class);
		$this->cache = $this->createMock(CacheDocumentService::class);
		$this->blobs = $this->createMock(AtprotoBlobRequest::class);
		$this->videos = $this->createMock(AtprotoVideoRequest::class);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->cache->method('storeFile')->willReturnCallback(function (string $path): string {
			$this->stored = (string)file_get_contents($path);

			return self::NEW;
		});
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}
	}

	public function testAFileIsStoredAgainWithoutItsMetadataAndTheOldOneDeleted(): void {
		$this->storedFileIs('original bytes');
		$document = $this->document();
		$this->documents->expects($this->once())->method('replaceLocalCopy')->with('7', self::NEW, strlen("rewritten\n"));
		$this->streams->expects($this->once())->method('updateLocalAttachmentCopies')
			->with($this->callback(fn (Document $d): bool => $d->getLocalCopy() === self::NEW
				&& $d->getSizeBytes() === strlen("rewritten\n") && $d->getLaddered() === 0));
		$this->cache->expects($this->once())->method('removeFromCache')->with(self::OLD);

		$outcome = $this->service($this->ffmpeg('echo rewritten > "$last"'))->strip($document);

		$this->assertSame(StoredMediaMetadataService::STRIPPED, $outcome);
		$this->assertSame("rewritten\n", $this->stored);
		$this->assertSame(self::OLD, $document->getLocalCopy(), 'the document handed in is not changed');
	}

	/** A file whose rewrite is the same bytes is not stored a second time. */
	public function testAFileThatIsAlreadyCleanIsLeftAsItIs(): void {
		$this->storedFileIs('clean bytes');
		$this->nothingIsWritten();

		$outcome = $this->service($this->ffmpeg('cp "$input" "$last"'))->strip($this->document());

		$this->assertSame(StoredMediaMetadataService::ALREADY_CLEAN, $outcome);
	}

	public function testAFileFfmpegCannotRewriteIsLeftAsItIs(): void {
		$this->storedFileIs('original bytes');
		$this->nothingIsWritten();

		$outcome = $this->service($this->ffmpeg('exit 1'))->strip($this->document());

		$this->assertSame(StoredMediaMetadataService::FAILED, $outcome);
	}

	public function testWithoutFfmpegAFileIsLeftAsItIs(): void {
		$this->storedFileIs('original bytes');
		$this->nothingIsWritten();
		$finder = $this->createStub(IBinaryFinder::class);
		$finder->method('findBinaryPath')->willReturn(false);

		$this->assertSame(StoredMediaMetadataService::FAILED, $this->service($finder)->strip($this->document()));
	}

	public function testAFileThatIsNotStoredIsSaidToBeMissing(): void {
		$this->cache->method('getContentFromCache')->willThrowException(new \OCA\Social\Exceptions\CacheContentException());
		$this->nothingIsWritten();

		$outcome = $this->service($this->ffmpeg('echo rewritten > "$last"'))->strip($this->document());

		$this->assertSame(StoredMediaMetadataService::MISSING, $outcome);
	}

	/**
	 * A Bluesky blob's CID is the hash of its bytes: a file that is one, or
	 * that was handed to Bluesky's video service, is not even read.
	 */
	public function testABlueskyBlobIsLeftAsItIs(): void {
		$this->blobs->method('hasDocument')->with('https://cloud.example/documents/7')->willReturn(true);
		$this->cache->expects($this->never())->method('getContentFromCache');
		$this->nothingIsWritten();

		$outcome = $this->service($this->ffmpeg('echo rewritten > "$last"'))->strip($this->document());

		$this->assertSame(StoredMediaMetadataService::ON_BLUESKY, $outcome);
	}

	public function testAVideoHandedToBlueskysVideoServiceIsLeftAsItIs(): void {
		$this->videos->method('hasDocument')->willReturn(true);
		$this->cache->expects($this->never())->method('getContentFromCache');
		$this->nothingIsWritten();

		$this->assertSame(
			StoredMediaMetadataService::ON_BLUESKY,
			$this->service($this->ffmpeg('echo rewritten > "$last"'))->strip($this->document())
		);
	}

	/** The real thing: a stored H.264 MP4 stating a location is stored again without it. */
	public function testARealMp4IsStoredAgainWithoutItsLocation(): void {
		$ffmpeg = $this->installed('ffmpeg');
		$ffprobe = $this->installed('ffprobe');
		$source = $this->tempFile('.mp4');
		exec(escapeshellarg($ffmpeg) . ' -v error -nostdin -f lavfi -i testsrc=duration=1:size=64x64:rate=5'
			. ' -c:v libx264 -pix_fmt yuv420p -metadata location=+52.5200+013.4050/'
			. ' -y ' . escapeshellarg($source) . ' 2>&1', $out, $status);
		if ($status !== 0) {
			$this->markTestSkipped('this ffmpeg cannot write an H.264 MP4: ' . implode(' ', $out));
		}
		$probed = '';
		$this->cache = $this->createMock(CacheDocumentService::class);
		$this->storedFileIs((string)file_get_contents($source));
		$this->cache->method('storeFile')->willReturnCallback(function (string $path) use ($ffprobe, &$probed): string {
			exec(escapeshellarg($ffprobe) . ' -v error -show_entries format_tags:stream=codec_name -of json ' . escapeshellarg($path), $lines);
			$probed = implode("\n", $lines);

			return self::NEW;
		});
		$finder = $this->createStub(IBinaryFinder::class);
		$finder->method('findBinaryPath')->willReturnCallback(fn (string $name) => ($name === 'ffmpeg') ? $ffmpeg : $ffprobe);

		$this->assertSame(StoredMediaMetadataService::STRIPPED, $this->service($finder)->strip($this->document()));
		$this->assertStringContainsString('h264', $probed);
		$this->assertStringNotContainsString('52.5200', $probed);
	}

	private function document(): Document {
		$document = new Document();
		$document->setNid(7);
		$document->setId('https://cloud.example/documents/7');
		$document->setMediaType('video/mp4');
		$document->setLocalCopy(self::OLD);
		$document->setAccount('alice');

		return $document;
	}

	private function storedFileIs(string $bytes): void {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('read')->willReturnCallback(static function () use ($bytes) {
			$stream = fopen('php://temp', 'r+');
			fwrite($stream, $bytes);
			rewind($stream);

			return $stream;
		});
		$this->cache->method('getContentFromCache')->with(self::OLD)->willReturn($file);
	}

	private function nothingIsWritten(): void {
		$this->cache->expects($this->never())->method('storeFile');
		$this->documents->expects($this->never())->method('replaceLocalCopy');
		$this->cache->expects($this->never())->method('removeFromCache');
	}

	/** A stand-in ffmpeg running $body with `$input` and `$last` (the output) set. */
	private function ffmpeg(string $body): IBinaryFinder {
		$binary = $this->tempFile();
		file_put_contents($binary, "#!/bin/sh\ninput=''\nprev=''\nfor arg in \"\$@\"; do [ \"\$prev\" = '-i' ] && input=\"\$arg\"; prev=\"\$arg\"; last=\"\$arg\"; done\n" . $body . "\n");
		chmod($binary, 0o755);
		$finder = $this->createStub(IBinaryFinder::class);
		$finder->method('findBinaryPath')->willReturn($binary);

		return $finder;
	}

	private function service(IBinaryFinder $finder): StoredMediaMetadataService {
		$temp = $this->createStub(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn (string $suffix = ''): string => $this->tempFile($suffix));
		$config = $this->createStub(ConfigService::class);

		return new StoredMediaMetadataService(
			$this->documents, $this->cache,
			new VideoTranscodeService($finder, $temp, $config, new NullLogger()),
			$this->blobs, $this->videos, $this->streams, $temp, new NullLogger()
		);
	}

	private function installed(string $name): string {
		$path = trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
		if ($path === '' || !is_executable($path)) {
			$this->markTestSkipped($name . ' is not installed');
		}

		return $path;
	}

	private function tempFile(string $suffix = ''): string {
		$base = (string)tempnam(sys_get_temp_dir(), 'social-strip-');
		$path = $base . $suffix;
		array_push($this->tempFiles, $base, $path);

		return $path;
	}
}
