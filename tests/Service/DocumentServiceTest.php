<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheContentDecodeException;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DocumentServiceTest extends TestCase {
	private const DOC_ID = 'https://remote.example/media/1';
	private const UUID = '2b5a7a87-8db1-445f-a17b-405790f91c80';

	private IURLGenerator|MockObject $urlGenerator;
	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private ActorsRequest|MockObject $actorsRequest;
	private StreamRequest|MockObject $streamRequest;
	private CacheDocumentService|MockObject $cacheService;
	private ConfigService|MockObject $configService;
	private MiscService|MockObject $miscService;
	private DocumentService $service;

	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheService = $this->createMock(CacheDocumentService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->miscService = $this->createMock(MiscService::class);

		$this->service = new DocumentService(
			$this->urlGenerator,
			$this->cacheDocumentsRequest,
			$this->actorsRequest,
			$this->streamRequest,
			$this->cacheService,
			$this->configService,
			$this->miscService,
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	private function document(string $localCopy = '', int $error = 0): Document {
		$document = new Document();
		$document->setId(self::DOC_ID);
		$document->setUrl('https://remote.example/files/pic.png');
		$document->setMimeType('image/png');
		$document->setLocalCopy($localCopy);
		$document->setResizedCopy($localCopy === '' ? '' : 'resized-' . $localCopy);
		$document->setError($error);

		return $document;
	}

	private function viewer(string $username = 'alice', string $id = 'https://cloud.example/@alice'): Person {
		$person = new Person();
		$person->setId($id);
		$person->setPreferredUsername($username);

		return $person;
	}

	// viewer-scoped lookups: a document id is not a capability

	public function testAPublicDocumentIsReadableByAnyone(): void {
		$doc = $this->document('copy-1');
		$doc->setPublic(true);
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$file = $this->createMock(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->with('copy-1')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getFromCacheAsViewer(self::DOC_ID, null, $mime));
		$this->assertSame('image/png', $mime);
	}

	public function testANonPublicDocumentIsNotReadableWithoutASession(): void {
		$this->cacheDocumentsRequest->method('getById')->willReturn($this->document('copy-1'));
		$this->cacheService->expects($this->never())->method('getContentFromCache');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->expectExceptionMessage('unknown document');
		$mime = '';
		$this->service->getFromCacheAsViewer(self::DOC_ID, null, $mime);
	}

	public function testAViewerReadsTheirOwnUpload(): void {
		$doc = $this->document('copy-1');
		$doc->setAccount('alice');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$file = $this->createMock(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->willReturn($file);

		$mime = '';
		$this->assertSame(
			$file, $this->service->getFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime)
		);
	}

	public function testAnotherAccountsUploadIsRefused(): void {
		// the finding: any session could name any id and read anybody's media
		$doc = $this->document('copy-1');
		$doc->setAccount('bob');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->expects($this->never())->method('getContentFromCache');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$mime = '';
		$this->service->getFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime);
	}

	public function testAnAttachmentOfAPostTheViewerCanSeeIsReadable(): void {
		$doc = $this->document('copy-1');
		$doc->setParentId('https://remote.example/notes/1');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$viewer = $this->viewer();
		$this->streamRequest->expects($this->once())->method('setViewer')->with($viewer);
		$this->streamRequest->expects($this->once())->method('getStreamById')
			->with('https://remote.example/notes/1', true)
			->willReturn(new Stream());
		$file = $this->createMock(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getFromCacheAsViewer(self::DOC_ID, $viewer, $mime));
	}

	public function testAnAttachmentOfAPostTheViewerCannotSeeIsRefused(): void {
		// a direct message's attachment: the timeline check is what keeps it out
		$doc = $this->document('copy-1');
		$doc->setParentId('https://remote.example/notes/secret');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->cacheService->expects($this->never())->method('getContentFromCache');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$mime = '';
		$this->service->getFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime);
	}

	public function testAViewerReadsTheirOwnActorsAvatar(): void {
		$doc = $this->document('copy-1');
		$doc->setParentId('https://cloud.example/@alice');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->streamRequest->expects($this->never())->method('getStreamById');
		$file = $this->createMock(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->willReturn($file);

		$mime = '';
		$this->assertSame(
			$file, $this->service->getFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime)
		);
	}

	public function testTheResizedCopyIsScopedTheSameWay(): void {
		$this->cacheDocumentsRequest->method('getById')->willReturn($this->document('copy-1'));
		$this->cacheService->expects($this->never())->method('getContentFromCache');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$mime = '';
		$this->service->getResizedFromCacheAsViewer(self::DOC_ID, $this->viewer(), $mime);
	}

	public function testCachingOnBehalfOfAViewerIsScopedTheSameWay(): void {
		$this->cacheDocumentsRequest->method('getById')->willReturn($this->document('copy-1'));

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->cacheRemoteDocumentAsViewer(self::DOC_ID, $this->viewer());
	}

	public function testAnUnknownIdIsRefusedTheSameWayAsAForbiddenOne(): void {
		// probing must not tell the caller which ids are real
		$this->cacheDocumentsRequest->method('getById')
			->willThrowException(new CacheDocumentDoesNotExistException());

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$mime = '';
		$this->service->getFromCacheAsViewer('https://remote.example/media/none', $this->viewer(), $mime);
	}

	// the cached copy of a remote url

	public function testGetCachedFromUrlServesTheStoredCopy(): void {
		$this->cacheDocumentsRequest->method('getByUrl')
			->with('https://remote.example/header.jpg')->willReturn($this->document('copy-9'));
		$file = $this->createMock(ISimpleFile::class);
		$this->cacheService->method('getContentFromCache')->with('copy-9')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getCachedFromUrl('https://remote.example/header.jpg', $mime));
		$this->assertSame('image/png', $mime);
	}

	public function testGetCachedFromUrlReportsAnUncachedUrl(): void {
		$this->cacheDocumentsRequest->method('getByUrl')->willReturn($this->document());

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->expectExceptionMessage('not cached');
		$this->service->getCachedFromUrl('https://remote.example/header.jpg');
	}

	public function testGetMediaFromArrayDelegates(): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->expects($this->once())
			->method('getFromArray')
			->with(['1', '2'], 'alice@cloud.example.com')
			->willReturn([$doc]);

		$this->assertSame([$doc], $this->service->getMediaFromArray(['1', '2'], 'alice@cloud.example.com'));
	}

	public function testGetFromUuidRejectsMalformedIds(): void {
		$this->cacheService->expects($this->never())->method('getFromUuid');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('invalid document');
		$this->service->getFromUuid('../../etc/passwd');
	}

	public function testGetFromUuidReturnsThePublicCopyAndItsRow(): void {
		$file = $this->createMock(ISimpleFile::class);
		$document = $this->createMock(Document::class);
		$document->method('isPublic')->willReturn(true);
		$this->cacheDocumentsRequest->expects($this->once())
			->method('getByCopy')->with(self::UUID)->willReturn($document);
		$this->cacheService->expects($this->once())->method('getFromUuid')->with(self::UUID)->willReturn($file);

		$this->assertSame([$file, $document], $this->service->getFromUuid(self::UUID));
	}

	public function testGetFromUuidServesTheAttachmentOfAFollowersOnlyPostByItsUuid(): void {
		// the uuid is the capability: Mastodon fetches media unsigned, so a copy
		// that is only handed out when its row says `public` is a broken image
		// on every followers-only post with a picture
		$file = $this->createMock(ISimpleFile::class);
		$document = $this->createMock(Document::class);
		$document->method('isPublic')->willReturn(false);
		$this->cacheDocumentsRequest->method('getByCopy')->with(self::UUID)->willReturn($document);
		$this->cacheService->method('getFromUuid')->with(self::UUID)->willReturn($file);

		$this->assertSame([$file, $document], $this->service->getFromUuid(self::UUID));
	}

	public function testGetFromUuidOfAnUnknownUuidIsNotFound(): void {
		$this->cacheDocumentsRequest->method('getByCopy')->with(self::UUID)
			->willThrowException(new CacheDocumentDoesNotExistException());
		$this->cacheService->expects($this->never())->method('getFromUuid');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('unknown document');
		$this->service->getFromUuid(self::UUID);
	}

	public function testCacheRemoteDocumentReturnsAnAlreadyCachedDocument(): void {
		$doc = $this->document('local-1');
		$this->cacheDocumentsRequest->method('getById')->with(self::DOC_ID, true)->willReturn($doc);
		$this->cacheDocumentsRequest->expects($this->never())->method('initCaching');
		$this->cacheService->expects($this->never())->method('saveRemoteFileToCache');

		$this->assertSame($doc, $this->service->cacheRemoteDocument(self::DOC_ID, true));
	}

	public function testCacheRemoteDocumentHidesDocumentsInError(): void {
		$this->cacheDocumentsRequest->method('getById')->willReturn($this->document('', DocumentService::ERROR_MIMETYPE));

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->cacheRemoteDocument(self::DOC_ID);
	}

	public function testCacheRemoteDocumentDoesNotRetryWhileACachingIsInProgress(): void {
		$doc = $this->document();
		$doc->setCaching(time() - 60);
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheDocumentsRequest->expects($this->never())->method('initCaching');

		$this->assertSame($doc, $this->service->cacheRemoteDocument(self::DOC_ID));
	}

	public function testCacheRemoteDocumentDownloadsAndAttachesTheFile(): void {
		$doc = $this->document();
		$doc->setCaching(time() - CacheDocumentsRequest::CACHING_TIMEOUT * 60 - 10);
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheDocumentsRequest->expects($this->once())->method('initCaching')->with($this->identicalTo($doc));
		$this->cacheService->expects($this->once())
			->method('saveRemoteFileToCache')
			->with($this->identicalTo($doc))
			->willReturnCallback(function (Document $document) {
				$document->setLocalCopy('fresh');
			});
		$this->cacheDocumentsRequest->expects($this->once())->method('endCaching')->with($this->identicalTo($doc));
		$this->streamRequest->expects($this->once())->method('updateAttachments')->with($this->identicalTo($doc));

		$this->assertSame($doc, $this->service->cacheRemoteDocument(self::DOC_ID));
		$this->assertSame('fresh', $doc->getLocalCopy());
	}

	/** @return array<string, array{\Exception, int}> */
	public static function cachingErrorProvider(): array {
		return [
			'wrong mime type' => [new CacheContentMimeTypeException(), DocumentService::ERROR_MIMETYPE],
			'too big' => [new RequestResultSizeException(), DocumentService::ERROR_SIZE],
			'storage not found' => [new NotFoundException(), DocumentService::ERROR_PERMISSION],
			'storage not permitted' => [new NotPermittedException(), DocumentService::ERROR_PERMISSION],
		];
	}

	#[DataProvider('cachingErrorProvider')]
	public function testCacheRemoteDocumentRecordsPermanentErrors(\Exception $failure, int $error): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')->willThrowException($failure);
		$this->cacheDocumentsRequest->expects($this->once())->method('endCaching')->with($this->identicalTo($doc));
		$this->cacheDocumentsRequest->expects($this->never())->method('deleteById');
		$this->streamRequest->expects($this->never())->method('updateAttachments');

		try {
			$this->service->cacheRemoteDocument(self::DOC_ID);
			$this->fail('expected CacheDocumentDoesNotExistException');
		} catch (CacheDocumentDoesNotExistException $e) {
			$this->assertSame($error, $doc->getError());
		}
	}

	/** @return array<string, array{\Exception}> */
	public static function goneProvider(): array {
		return [
			'remote says gone' => [new RequestContentException('gone', 410)],
			'blocked instance' => [new UnauthorizedFediverseException()],
		];
	}

	#[DataProvider('goneProvider')]
	public function testCacheRemoteDocumentDeletesUnreachableDocuments(\Exception $failure): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')->willThrowException($failure);
		$this->cacheDocumentsRequest->expects($this->once())->method('deleteById')->with(self::DOC_ID);
		$this->cacheDocumentsRequest->expects($this->never())->method('endCaching');

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->cacheRemoteDocument(self::DOC_ID);
	}

	public function testCacheRemoteDocumentLeavesTransientErrorsRetryable(): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')->willThrowException(new RequestNetworkException('timeout'));
		$this->cacheDocumentsRequest->expects($this->once())->method('endCaching');
		$this->cacheDocumentsRequest->expects($this->never())->method('deleteById');

		try {
			$this->service->cacheRemoteDocument(self::DOC_ID);
			$this->fail('expected CacheDocumentDoesNotExistException');
		} catch (CacheDocumentDoesNotExistException $e) {
			$this->assertSame(0, $doc->getError());
		}
	}

	public function testAnUndecodableDocumentIsRecordedSoItIsNotRetriedForever(): void {
		$doc = $this->document();
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')
			->willThrowException(new CacheContentDecodeException('not an image'));
		$this->cacheDocumentsRequest->expects($this->once())->method('endCaching')->with($doc);
		$this->cacheDocumentsRequest->expects($this->never())->method('deleteById');

		try {
			$this->service->cacheRemoteDocument(self::DOC_ID);
			$this->fail('expected CacheDocumentDoesNotExistException');
		} catch (CacheDocumentDoesNotExistException $e) {
			// a non-zero error is what keeps getNotCachedDocuments() from
			// handing the same row back on the next run
			$this->assertSame(DocumentService::ERROR_CONTENT, $doc->getError());
		}
	}

	public function testTheSniffedMimeTypeIsCarriedOntoTheStoredRow(): void {
		$doc = $this->document();
		$doc->setMimeType('');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')
			->willReturnCallback(function (Document $document, string &$mime): void {
				$document->setLocalCopy('local-1');
				$mime = 'image/webp';
			});

		$this->service->cacheRemoteDocument(self::DOC_ID);

		$this->assertSame('image/webp', $doc->getMimeType());
		$this->assertSame('image/webp', $doc->getMediaType());
	}

	public function testAnAlreadyKnownMediaTypeIsNotOverwritten(): void {
		$doc = $this->document();
		$doc->setMediaType('image/png');
		$this->cacheDocumentsRequest->method('getById')->willReturn($doc);
		$this->cacheService->method('saveRemoteFileToCache')
			->willReturnCallback(function (Document $document, string &$mime): void {
				$document->setLocalCopy('local-1');
				$mime = 'image/jpeg';
			});

		$this->service->cacheRemoteDocument(self::DOC_ID);

		$this->assertSame('image/png', $doc->getMediaType());
	}

	public function testOneUnusableRowDoesNotEndTheCachingRun(): void {
		// the poison pill: an Error escaping one row used to abandon caching for
		// every row queued behind it, silently, on every run
		$poison = $this->document();
		$poison->setId('https://remote.example/media/poison');
		$good = $this->document();
		$good->setId('https://remote.example/media/good');
		$this->cacheDocumentsRequest->method('getNotCachedDocuments')->willReturn([$poison, $good]);
		$this->cacheDocumentsRequest->method('getById')->willReturnCallback(
			fn (string $id) => $id === $poison->getId() ? $poison : $good
		);
		$this->cacheService->method('saveRemoteFileToCache')->willReturnCallback(
			function (Document $document, string &$mime): void {
				if ($document->getId() === 'https://remote.example/media/poison') {
					throw new \Error('Call to a member function on null');
				}
				$document->setLocalCopy('local-1');
				$mime = 'image/png';
			}
		);

		$this->assertSame(1, $this->service->manageCacheDocuments());
	}

	public function testGetFromCacheReturnsTheLocalCopyAndItsMimeType(): void {
		$doc = $this->document('local-1');
		$this->cacheDocumentsRequest->method('getById')->with(self::DOC_ID, false)->willReturn($doc);
		$file = $this->createMock(ISimpleFile::class);
		$this->cacheService->expects($this->once())->method('getContentFromCache')->with('local-1')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getFromCache(self::DOC_ID, $mime));
		$this->assertSame('image/png', $mime);
	}

	public function testGetResizedFromCacheReturnsTheResizedCopy(): void {
		$doc = $this->document('local-1');
		$this->cacheDocumentsRequest->method('getById')->with(self::DOC_ID, true)->willReturn($doc);
		$file = $this->createMock(ISimpleFile::class);
		$this->cacheService->expects($this->once())->method('getContentFromCache')->with('resized-local-1')->willReturn($file);

		$mime = '';
		$this->assertSame($file, $this->service->getResizedFromCache(self::DOC_ID, $mime, true));
		$this->assertSame('image/png', $mime);
	}

	public function testGetFromCachePropagatesAMissingDocument(): void {
		$this->cacheDocumentsRequest->method('getById')->willThrowException(new CacheDocumentDoesNotExistException());

		$this->expectException(CacheDocumentDoesNotExistException::class);
		$this->service->getFromCache(self::DOC_ID);
	}

	public function testManageCacheDocumentsSkipsLocalAvatarsAndCountsSuccesses(): void {
		$avatar = $this->document('avatar');
		$header = $this->document('header');
		$pending = $this->document();
		$pending->setId('https://remote.example/media/2');
		$failing = $this->document();
		$failing->setId('https://remote.example/media/3');
		$this->cacheDocumentsRequest->method('getNotCachedDocuments')->willReturn([$avatar, $header, $pending, $failing]);
		$this->cacheDocumentsRequest->method('getById')->willReturnCallback(fn (string $id) => $id === $pending->getId() ? $pending : $failing);
		$this->cacheService->method('saveRemoteFileToCache')->willReturnCallback(function (Document $document) {
			if ($document->getId() === 'https://remote.example/media/3') {
				throw new RequestNetworkException('timeout');
			}
		});

		$this->assertSame(1, $this->service->manageCacheDocuments());
	}

	/**
	 * A streamed file is played from the instance that published it, and the
	 * cache job passes over it the way it passes over an avatar.
	 *
	 * Belt and braces, and deliberately so: such a row should never reach the
	 * loop, because `getNotCachedDocuments()` asks for an empty `local_copy`
	 * and `PeerTubeService` names it `stream` before the row is written. This
	 * pins the guard that holds if either of those ever changes, because the
	 * cost is not symmetric -- the file on the other side of it is a video of
	 * hundreds of megabytes.
	 */
	public function testManageCacheDocumentsDoesNotDownloadAStreamedFile(): void {
		$streamed = $this->document(Document::COPY_STREAMED);
		$streamed->setId('https://peertube.example/videos/watch/abc');
		$this->cacheDocumentsRequest->method('getNotCachedDocuments')->willReturn([$streamed]);
		$this->cacheDocumentsRequest->expects($this->never())->method('getById');
		$this->cacheService->expects($this->never())->method('saveRemoteFileToCache');

		$this->assertSame(0, $this->service->manageCacheDocuments());
	}

	private function alice(int $avatarVersion): Person {
		$alice = new Person();
		$alice->setId('https://cloud.example.com/apps/social/@alice');
		$alice->setUserId('alice');
		$alice->setAvatarVersion($avatarVersion);

		return $alice;
	}

	private function avatarUrl(): string {
		$url = 'https://cloud.example.com/avatar/alice/128';
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('core.avatar.getAvatar', ['userId' => 'alice', 'size' => 128])
			->willReturn($url);

		return $url;
	}

	public function testCacheLocalAvatarCreatesANewImageWhenTheAvatarChanged(): void {
		$url = $this->avatarUrl();
		$alice = $this->alice(1);
		$this->configService->method('getUserValue')->with('version', 'alice', 'avatar')->willReturn('2');
		$icon = new Image();
		$icon->setUrlCloud('https://cloud.example.com');
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromType')->with(Image::TYPE)->willReturn($icon);
		$imageInterface = $this->createMock(ImageInterface::class);
		$imageInterface->expects($this->once())->method('save')->with($this->identicalTo($icon));
		$ap->method('getInterfaceFromType')->with(Image::TYPE)->willReturn($imageInterface);
		AP::set($ap);
		$this->actorsRequest->expects($this->once())->method('update')->with($this->identicalTo($alice));
		$this->cacheDocumentsRequest->expects($this->never())->method('getByUrl');

		$id = $this->service->cacheLocalAvatarByUsername($alice);

		$this->assertSame($icon->getId(), $id);
		$this->assertStringStartsWith('https://cloud.example.com/documents/avatar/', $id);
		$this->assertSame($url, $icon->getUrl());
		$this->assertSame('avatar', $icon->getLocalCopy());
		$this->assertSame(2, $alice->getAvatarVersion());
	}

	public function testCacheLocalAvatarReusesTheCachedImageWhenUnchanged(): void {
		$url = $this->avatarUrl();
		$alice = $this->alice(2);
		$this->configService->method('getUserValue')->willReturn('2');
		$cached = new Image();
		$cached->setId('https://cloud.example.com/documents/avatar/existing');
		$this->cacheDocumentsRequest->expects($this->once())->method('getByUrl')->with($url)->willReturn($cached);
		$this->actorsRequest->expects($this->never())->method('update');

		$this->assertSame('https://cloud.example.com/documents/avatar/existing', $this->service->cacheLocalAvatarByUsername($alice));
	}

	public function testCacheLocalAvatarIsEmptyWhenNothingIsCachedYet(): void {
		$this->avatarUrl();
		$this->configService->method('getUserValue')->willReturn('0');
		$this->cacheDocumentsRequest->method('getByUrl')->willThrowException(new CacheDocumentDoesNotExistException());

		$this->assertSame('', $this->service->cacheLocalAvatarByUsername($this->alice(0)));
	}

	public function testCacheLocalHeaderStoresTheUploadAndPointsTheActorAtIt(): void {
		$alice = $this->alice(0);
		$image = new Image();
		$image->setUrlCloud('https://cloud.example.com');
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromType')->with(Image::TYPE)->willReturn($image);
		$imageInterface = $this->createMock(ImageInterface::class);
		$imageInterface->expects($this->once())->method('save')->with($this->identicalTo($image));
		$ap->method('getInterfaceFromType')->willReturn($imageInterface);
		AP::set($ap);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(fn (string $route, array $args) => match ($route) {
				'social.Local.globalActorHeader' => 'https://cloud.example.com/apps/social/header/' . rawurlencode($args['id']),
				'social.Api.mediaOpen' => 'https://cloud.example.com/apps/social/media/' . $args['uuid'],
			});
		$this->cacheService->expects($this->once())
			->method('saveFromTempToCache')
			->with($this->identicalTo($image), '/tmp/upload.jpg')
			->willReturnCallback(function (Image $image) {
				$image->setLocalCopy('stored-uuid');
			});

		$id = $this->service->cacheLocalHeaderByUsername($alice, '/tmp/upload.jpg', 'image/jpeg');

		$this->assertSame($image->getId(), $id);
		$this->assertStringStartsWith('https://cloud.example.com/documents/header/', $id);
		$this->assertSame('image/jpeg', $image->getMimeType());
		$this->assertTrue($image->isPublic());
		$this->assertSame('https://cloud.example.com/apps/social/media/stored-uuid.jpeg', $image->getUrl());
		$this->assertSame($image->getUrl(), $alice->getHeader());
	}
}
