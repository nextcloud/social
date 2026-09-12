<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\BlurService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CacheDocumentServiceTest extends TestCase {
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	private IAppData|MockObject $appData;
	private CurlService|MockObject $curlService;
	private BlurService|MockObject $blurService;
	private CacheDocumentService $service;

	protected function setUp(): void {
		$this->appData = $this->createMock(IAppData::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->blurService = $this->createMock(BlurService::class);
		$this->service = new CacheDocumentService(
			$this->appData,
			$this->curlService,
			$this->blurService,
			$this->createMock(ConfigService::class),
		);
	}

	/** A small real PNG, so mime detection and the resizer see a genuine image. */
	private function pngBytes(int $width = 16, int $height = 10): string {
		$image = imagecreatetruecolor($width, $height);
		imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 220));
		ob_start();
		imagepng($image);

		return ob_get_clean();
	}

	/**
	 * The bundled gumlet/php-image-resize still calls finfo_close() and
	 * imagedestroy(), both deprecated in PHP 8.5. Those notices are the
	 * library's, not the service's: keep them out of the test output.
	 */
	/**
	 * GD and the resize library announce malformed input with an E_WARNING before
	 * returning false, and some codecs emit E_DEPRECATED on PHP 8.5. Both are the
	 * expected path here: the assertions are about what the service does with the
	 * failure, and PHPUnit 10 counts anything printed during a test as risky.
	 */
	private function quietly(callable $call): void {
		$previous = error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
		try {
			$call();
		} finally {
			error_reporting($previous);
		}
	}

	/**
	 * Wire appData so every new file lands in $written (path => [name => bytes]).
	 * Folders are reported missing first, so the service has to create them.
	 */
	private function captureWrites(array &$written): void {
		$this->appData->method('getFolder')->willThrowException(new NotFoundException());
		$this->appData->method('newFolder')->willReturnCallback(function (string $path) use (&$written) {
			$folder = $this->createMock(ISimpleFolder::class);
			$folder->method('newFile')->willReturnCallback(function (string $name) use ($path, &$written) {
				$file = $this->createMock(ISimpleFile::class);
				$file->method('putContent')->willReturnCallback(function (string $content) use ($path, $name, &$written) {
					$written[$path][$name] = $content;
				});

				return $file;
			});

			return $folder;
		});
	}

	/** @return array<string, array{string}> */
	public static function allowedMimeProvider(): array {
		return [
			'jpeg' => ['image/jpeg'],
			'gif' => ['image/gif'],
			'png' => ['image/png'],
			'webp' => ['image/webp'],
			'mp4 video' => ['video/mp4'],
			'webm' => ['video/webm'],
			'quicktime' => ['video/quicktime'],
			'mp3' => ['audio/mpeg'],
			'aac' => ['audio/mp4'],
			'ogg' => ['audio/ogg'],
			'opus' => ['audio/opus'],
			'wav' => ['audio/wav'],
			'flac' => ['audio/flac'],
		];
	}

	#[DataProvider('allowedMimeProvider')]
	public function testFilterMimeTypesAcceptsImages(string $mime): void {
		$this->service->filterMimeTypes($mime);
		$this->addToAssertionCount(1);
	}

	/** @return array<string, array{string}> */
	public static function rejectedMimeProvider(): array {
		return [
			'svg' => ['image/svg+xml'],
			'html' => ['text/html'],
			'php' => ['application/x-httpd-php'],
			'mkv' => ['video/x-matroska'],
			'empty' => [''],
		];
	}

	#[DataProvider('rejectedMimeProvider')]
	public function testFilterMimeTypesRejectsEverythingElse(string $mime): void {
		$this->expectException(CacheContentMimeTypeException::class);
		$this->service->filterMimeTypes($mime);
	}

	public function testSaveContentToCacheStoresVideoAsIsWithoutResizeOrBlurhash(): void {
		$written = [];
		$this->captureWrites($written);
		$this->blurService->expects($this->never())->method('generateBlurHash');
		$document = new Document();
		// a minimal MP4: size + ftyp box is enough for content sniffing
		$mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64);

		$mime = '';
		$this->quietly(function () use ($document, $mp4, &$mime) {
			$this->service->saveContentToCache($document, $mp4, $mime);
		});

		$this->assertSame('video/mp4', $mime);
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
		$this->assertSame('', $document->getResizedCopy(), 'video keeps no resized copy');
		$this->assertSame('', $document->getBlurHash());
	}

	public function testSaveContentToCacheResizesWebp(): void {
		if (!function_exists('imagewebp')) {
			$this->markTestSkipped('gd without webp');
		}
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$gd = imagecreatetruecolor(1200, 900);
		ob_start();
		imagewebp($gd);
		$webp = ob_get_clean();
		$document = new Document();

		$mime = '';
		$this->quietly(function () use ($document, $webp, &$mime) {
			$this->service->saveContentToCache($document, $webp, $mime);
		});

		$this->assertSame('image/webp', $mime);
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getResizedCopy());
	}

	public function testSaveContentToCacheStoresOriginalAndResizedCopiesInHashedFolders(): void {
		$written = [];
		$this->captureWrites($written);
		$this->blurService->expects($this->once())
			->method('generateBlurHash')
			->with($this->isInstanceOf(\GdImage::class))
			->willReturn('LKO2?U%2Tw=w]~RBVZRi};RPxuwH');
		$document = new Document();
		$png = $this->pngBytes();

		$mime = '';
		$this->quietly(function () use ($document, $png, &$mime) {
			$this->service->saveContentToCache($document, $png, $mime);
		});

		$this->assertSame('image/png', $mime);
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getResizedCopy());
		$this->assertNotSame($document->getLocalCopy(), $document->getResizedCopy());
		$this->assertSame([16, 10], $document->getLocalCopySize());
		$this->assertSame([16, 10], $document->getResizedCopySize(), 'small images are not enlarged');
		$this->assertSame('LKO2?U%2Tw=w]~RBVZRi};RPxuwH', $document->getBlurHash());

		$this->assertCount(2, $written);
		foreach ($written as $path => $files) {
			$name = array_key_first($files);
			// folder aa/bb/cc/dd/ derives from the first 8 chars of the file name
			$this->assertSame(chunk_split(substr($name, 0, 8), 2, '/'), $path);
			$this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($files[$name]));
		}
		$this->assertSame($png, $written[chunk_split(substr($document->getLocalCopy(), 0, 8), 2, '/')][$document->getLocalCopy()]);
	}

	public function testSaveContentToCacheReusesAnExistingFolder(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);
		$folder->method('newFile')->willReturn($file);
		$this->appData->method('getFolder')->willReturn($folder);
		$this->appData->expects($this->never())->method('newFolder');
		$file->expects($this->exactly(2))->method('putContent');
		$this->blurService->method('generateBlurHash')->willReturn('hash');

		$this->quietly(fn () => $this->service->saveContentToCache(new Document(), $this->pngBytes()));
	}

	public function testSaveContentToCacheRefusesNonImagesBeforeTouchingStorage(): void {
		$this->appData->expects($this->never())->method($this->anything());
		$document = new Document();

		$mime = '';
		try {
			$this->service->saveContentToCache($document, '<html><body>not an image</body></html>', $mime);
			$this->fail('expected CacheContentMimeTypeException');
		} catch (CacheContentMimeTypeException $e) {
			$this->assertSame('text/html', $mime);
			$this->assertSame('', $document->getLocalCopy());
		}
	}

	public function testSaveLocalUploadToCacheIsAnAliasForSaveContent(): void {
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$document = new Document();

		$mime = '';
		$this->quietly(function () use ($document, &$mime) {
			$this->service->saveLocalUploadToCache($document, $this->pngBytes(), $mime);
		});

		$this->assertSame('image/png', $mime);
		$this->assertCount(2, $written);
	}

	public function testSaveFromTempToCacheReadsTheFileAndSetsTheMediaType(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($tmp, $this->pngBytes(12, 12));
		try {
			$written = [];
			$this->captureWrites($written);
			$this->blurService->method('generateBlurHash')->willReturn('hash');
			$document = new Document();

			$this->quietly(fn () => $this->service->saveFromTempToCache($document, $tmp));

			$this->assertSame('image/png', $document->getMediaType());
			$this->assertSame('image/png', $document->getMimeType());
			$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
			$this->assertSame([12, 12], $document->getLocalCopySize());
			$this->assertCount(2, $written);
		} finally {
			unlink($tmp);
		}
	}

	public function testSaveFromTempToCacheRejectsNonImages(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($tmp, 'plain text');
		try {
			$this->appData->expects($this->never())->method($this->anything());

			$this->expectException(CacheContentMimeTypeException::class);
			$this->service->saveFromTempToCache(new Document(), $tmp);
		} finally {
			unlink($tmp);
		}
	}

	public function testGetContentFromCacheReadsFromTheHashedFolder(): void {
		$file = $this->createMock(ISimpleFile::class);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->expects($this->once())->method('getFile')->with('2b5a7a87-8db1-445f-a17b-405790f91c80')->willReturn($file);
		$this->appData->expects($this->once())->method('getFolder')->with('2b/5a/7a/87/')->willReturn($folder);

		$this->assertSame($file, $this->service->getContentFromCache('2b5a7a87-8db1-445f-a17b-405790f91c80'));
	}

	public function testGetContentFromCacheWithoutFilenameIsAMissingDocument(): void {
		$this->appData->expects($this->never())->method('getFolder');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->getContentFromCache('');
	}

	public function testGetContentFromCacheDoesNotServeLocalAvatarPlaceholders(): void {
		$this->appData->expects($this->never())->method('getFolder');

		$this->expectException(CacheContentException::class);
		$this->service->getContentFromCache('avatar');
	}

	public function testGetContentFromCacheWrapsStorageErrors(): void {
		$this->appData->method('getFolder')->willThrowException(new NotFoundException());

		$this->expectException(CacheContentException::class);
		$this->service->getContentFromCache('2b5a7a87-8db1-445f-a17b-405790f91c80');
	}

	public function testGetFromUuidReadsTheFile(): void {
		$file = $this->createMock(ISimpleFile::class);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->with('2b5a7a87-8db1-445f-a17b-405790f91c80')->willReturn($file);
		$this->appData->method('getFolder')->with('2b/5a/7a/87/')->willReturn($folder);

		$this->assertSame($file, $this->service->getFromUuid('2b5a7a87-8db1-445f-a17b-405790f91c80'));
	}

	public function testGetFromUuidReportsAMissingDocument(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willThrowException(new NotFoundException('no file'));
		$this->appData->method('getFolder')->willReturn($folder);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('document not found');
		$this->service->getFromUuid('2b5a7a87-8db1-445f-a17b-405790f91c80');
	}

	public function testRetrieveContentIssuesAGetWithoutJsonHeaders(): void {
		$this->curlService->expects($this->once())
			->method('doRequest')
			->with('get', 'https://remote.example/files/pic.png', ['json_headers' => false])
			->willReturn('PNG-BYTES');

		$this->assertSame('PNG-BYTES', $this->service->retrieveContent('https://remote.example/files/pic.png'));
	}

	// content that only claims to be an image

	/** A PNG signature and IHDR that decode to nothing usable. */
	private function pngHeaderThenGarbage(): string {
		$ihdr = pack('N', 13) . 'IHDR' . pack('NN', 16, 16) . "\x08\x02\x00\x00\x00";
		$ihdr .= pack('N', crc32(substr($ihdr, 4)));

		return "\x89PNG\r\n\x1a\n" . $ihdr . str_repeat("\x41", 128);
	}

	/** A PNG header declaring dimensions no machine would decode. */
	private function hugePngHeader(): string {
		$ihdr = pack('N', 13) . 'IHDR' . pack('NN', 30000, 30000) . "\x08\x02\x00\x00\x00";
		$ihdr .= pack('N', crc32(substr($ihdr, 4)));

		return "\x89PNG\r\n\x1a\n" . $ihdr . str_repeat("\x00", 64);
	}

	#[WithoutErrorHandler]
	public function testUndecodableImageContentIsReportedNotFatal(): void {
		// this used to reach a method call on null and escape as an Error, which
		// abandoned document caching for every row queued behind it
		$written = [];
		$this->captureWrites($written);
		$document = new Document();

		$this->expectException(CacheContentDecodeException::class);
		$mime = '';
		$this->quietly(function () use ($document, &$mime) {
			$this->service->saveContentToCache($document, $this->pngHeaderThenGarbage(), $mime);
		});
	}

	public function testAnImageTooLargeToDecodeIsRefusedBeforeDecoding(): void {
		// 30000x30000 is a few hundred kilobytes on the wire and ~3.6 GB in GD
		$written = [];
		$this->captureWrites($written);
		$this->blurService->expects($this->never())->method('generateBlurHash');

		$this->expectException(CacheContentDecodeException::class);
		$this->expectExceptionMessage('too large to decode');
		$mime = '';
		$this->quietly(function () use (&$mime) {
			$this->service->saveContentToCache(new Document(), $this->hugePngHeader(), $mime);
		});
	}

	public function testAnImageWithinTheBudgetStillDecodes(): void {
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$document = new Document();

		$mime = '';
		$this->quietly(function () use ($document, &$mime) {
			$this->service->saveContentToCache($document, $this->pngBytes(64, 48), $mime);
		});

		$this->assertSame('image/png', $mime);
		$this->assertSame(64, $document->getLocalCopySize()[0]);
	}

	#[WithoutErrorHandler]
	public function testAnUploadedFileThatIsNotAnImageIsRefusedTheSameWay(): void {
		$written = [];
		$this->captureWrites($written);
		$tmp = tempnam(sys_get_temp_dir(), 'social_test_');
		file_put_contents($tmp, $this->pngHeaderThenGarbage());

		try {
			$this->expectException(CacheContentDecodeException::class);
			$this->quietly(function () use ($tmp) {
				$this->service->saveFromTempToCache(new Document(), $tmp);
			});
		} finally {
			@unlink($tmp);
		}
	}

	public function testRetrieveContentCarriesTheQueryString(): void {
		// a signed CDN link keeps its credentials there, byte for byte
		$this->curlService->expects($this->once())
			->method('doRequest')
			->with('get', 'https://remote.example/files/pic.png?sig=abc&exp=12', ['json_headers' => false])
			->willReturn('PNG-BYTES');

		$this->assertSame(
			'PNG-BYTES',
			$this->service->retrieveContent('https://remote.example/files/pic.png?sig=abc&exp=12')
		);
	}

	public function testRetrieveContentRejectsIncompleteUrls(): void {
		$this->curlService->expects($this->never())->method('doRequest');

		$this->expectException(RequestServerException::class);
		$this->service->retrieveContent('/files/pic.png');
	}

	#[DataProvider('provideNonWebUrls')]
	public function testRetrieveContentOnlyFetchesOverHttp(string $url): void {
		$this->curlService->expects($this->never())->method('doRequest');

		$this->expectException(RequestServerException::class);
		$this->service->retrieveContent($url);
	}

	public static function provideNonWebUrls(): iterable {
		yield 'file' => ['file:///etc/passwd'];
		yield 'gopher' => ['gopher://remote.example/1'];
		yield 'ftp' => ['ftp://remote.example/pic.png'];
	}

	public function testSaveRemoteFileToCacheDownloadsThenStores(): void {
		$this->curlService->method('doRequest')->willReturn($this->pngBytes());
		$written = [];
		$this->captureWrites($written);
		$this->blurService->method('generateBlurHash')->willReturn('hash');
		$document = new Document();
		$document->setUrl('https://remote.example/files/pic.png');

		$mime = '';
		$this->quietly(function () use ($document, &$mime) {
			$this->service->saveRemoteFileToCache($document, $mime);
		});

		$this->assertSame('image/png', $mime);
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $document->getLocalCopy());
	}
}
