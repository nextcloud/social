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
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
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
		AP::$activityPub = null;
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

	public function testGetFromUuidRefusesANonPublicCopyByDefault(): void {
		$document = $this->createMock(Document::class);
		$document->method('isPublic')->willReturn(false);
		$this->cacheDocumentsRequest->method('getByCopy')->with(self::UUID)->willReturn($document);
		$this->cacheService->expects($this->never())->method('getFromUuid');

		$this->expectException(NotFoundException::class);
		$this->service->getFromUuid(self::UUID);
	}

	public function testGetFromUuidServesANonPublicCopyOnlyWhenNotRestricted(): void {
		$file = $this->createMock(ISimpleFile::class);
		$document = $this->createMock(Document::class);
		$document->method('isPublic')->willReturn(false);
		$this->cacheDocumentsRequest->method('getByCopy')->with(self::UUID)->willReturn($document);
		$this->cacheService->method('getFromUuid')->with(self::UUID)->willReturn($file);

		$this->assertSame([$file, $document], $this->service->getFromUuid(self::UUID, false));
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
	public function cachingErrorProvider(): array {
		return [
			'wrong mime type' => [new CacheContentMimeTypeException(), DocumentService::ERROR_MIMETYPE],
			'too big' => [new RequestResultSizeException(), DocumentService::ERROR_SIZE],
			'storage not found' => [new NotFoundException(), DocumentService::ERROR_PERMISSION],
			'storage not permitted' => [new NotPermittedException(), DocumentService::ERROR_PERMISSION],
		];
	}

	/** @dataProvider cachingErrorProvider */
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
	public function goneProvider(): array {
		return [
			'remote says gone' => [new RequestContentException('gone', 410)],
			'blocked instance' => [new UnauthorizedFediverseException()],
		];
	}

	/** @dataProvider goneProvider */
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
		AP::$activityPub = $ap;
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
		AP::$activityPub = $ap;
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
