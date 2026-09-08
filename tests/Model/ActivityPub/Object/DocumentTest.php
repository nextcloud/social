<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\Client\AttachmentMeta;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DocumentTest extends TestCase {
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;

	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $args): string => 'https://cloud.example.org/' . $route . '/' . $args['uuid']
		);
	}

	public function testConstructorSetsTheType(): void {
		$this->assertSame('Document', (new Document())->getType());
	}

	public function testImportReadsTheMediaTypeAndKeepsAGivenId(): void {
		$document = new Document();

		$document->import([
			'id' => 'https://files.mastodon.social/media/1',
			'type' => 'Document',
			'mediaType' => 'image/jpeg',
			'url' => 'https://files.mastodon.social/media/cat.jpg',
		]);

		$this->assertSame('https://files.mastodon.social/media/1', $document->getId());
		$this->assertSame('image/jpeg', $document->getMediaType());
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $document->getUrl());
	}

	public function testImportGeneratesAnIdUnderTheCloudUrlWhenMissing(): void {
		$document = new Document();
		$document->setUrlCloud('https://cloud.example.org');

		$document->import(['type' => 'Document', 'mediaType' => 'image/png', 'url' => 'https://a.example/x.png']);

		$this->assertMatchesRegularExpression('#^https://cloud\.example\.org/documents/g/[0-9a-f-]{36}$#', $document->getId());
	}

	public function testImportWithoutIdNeedsTheCloudUrl(): void {
		$this->expectException(UrlCloudException::class);

		(new Document())->import(['type' => 'Document', 'url' => 'https://a.example/x.png']);
	}

	public function testImportFromDatabaseReadsTheCacheRow(): void {
		$document = new Document();

		$document->importFromDatabase([
			'nid' => 8,
			'id' => 'https://files.mastodon.social/media/1',
			'type' => 'Document',
			'url' => 'https://files.mastodon.social/media/cat.jpg',
			'account' => 'alice@mastodon.social',
			'public' => 1,
			'error' => 0,
			'local_copy' => 'abc',
			'resized_copy' => 'abc_s',
			'blurhash' => 'UBL_:rOp',
			'description' => 'A cat',
			'media_type' => 'image/jpeg',
			'mime_type' => 'image/jpeg',
			'parent_id' => 'https://mastodon.social/users/alice/statuses/1',
			'caching' => '2024-05-01 12:00:00',
			'meta' => '{"original":{"width":1200,"height":800},"focus":{"x":0,"y":0}}',
		]);

		$this->assertSame(8, $document->getNid());
		$this->assertSame('alice@mastodon.social', $document->getAccount());
		$this->assertTrue($document->isPublic());
		$this->assertSame(0, $document->getError());
		$this->assertSame('abc', $document->getLocalCopy());
		$this->assertSame('abc_s', $document->getResizedCopy());
		$this->assertSame('UBL_:rOp', $document->getBlurHash());
		$this->assertSame('A cat', $document->getDescription());
		$this->assertSame('image/jpeg', $document->getMediaType());
		$this->assertSame('image/jpeg', $document->getMimeType());
		$this->assertSame('https://mastodon.social/users/alice/statuses/1', $document->getParentId());
		$this->assertSame((new \DateTime('2024-05-01 12:00:00'))->getTimestamp(), $document->getCaching());
		$this->assertInstanceOf(AttachmentMeta::class, $document->getMeta());
		$this->assertSame(1200, $document->getMeta()->getOriginal()->getWidth());
	}

	public function testImportFromDatabaseWithoutCachingDateOrMeta(): void {
		$document = new Document();

		$document->importFromDatabase(['id' => 'https://a.example/1', 'type' => 'Document', 'caching' => '', 'public' => 0]);

		$this->assertSame(0, $document->getCaching());
		$this->assertFalse($document->isPublic());
		$this->assertNull($document->getMeta());
	}

	public function testMediaUrlsPointToTheMediaRouteWithTheMimeExtension(): void {
		$document = new Document();
		$document->setLocalCopy('abc')
			->setResizedCopy('abc_s');

		$this->assertSame('https://cloud.example.org/social.Api.mediaOpen/abc.jpeg', $document->getMediaUrl($this->urlGenerator, 'image/jpeg'));
		$this->assertSame('https://cloud.example.org/social.Api.mediaOpen/abc', $document->getMediaUrl($this->urlGenerator));
		$this->assertSame('https://cloud.example.org/social.Api.mediaOpen/abc_s.png', $document->getResizedMediaUrl($this->urlGenerator, 'image/png'));
	}

	public function testConvertToMediaAttachmentBuildsTheMastodonEntity(): void {
		$document = new Document();
		$document->setNid(8)
			->setMediaType('image/jpeg')
			->setUrl('https://files.mastodon.social/media/cat.jpg')
			->setLocalCopy('abc')
			->setResizedCopy('abc_s')
			->setDescription('A cat')
			->setBlurHash('UBL_:rOp')
			->setLocalCopySize(1200, 800);
		$document->setResizedCopySize(600, 400);

		$media = $document->convertToMediaAttachment($this->urlGenerator);

		$this->assertSame('8', $media->getId());
		$this->assertSame('image', $media->getType());
		$this->assertSame('https://cloud.example.org/social.Api.mediaOpen/abc.jpeg', $media->getUrl());
		$this->assertSame('https://cloud.example.org/social.Api.mediaOpen/abc_s.jpeg', $media->getPreviewUrl());
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $media->getRemoteUrl());
		$this->assertSame('A cat', $media->getDescription());
		$this->assertSame('UBL_:rOp', $media->getBlurHash());
		$this->assertSame(ACore::FORMAT_LOCAL, $media->getExportFormat());
		$this->assertSame(1200, $media->getMeta()->getOriginal()->getWidth());
		$this->assertSame('600x400', $media->getMeta()->getSmall()->getSize());
		$this->assertSame(0.0, $media->getMeta()->getFocus()->getX());
		$this->assertSame($media->getMeta(), $document->getMeta(), 'the generated meta is kept on the document');
	}

	public function testConvertToMediaAttachmentWithoutUrlGeneratorLeavesLocalUrlsEmpty(): void {
		$document = new Document();
		$document->setMediaType('video/mp4')
			->setUrl('https://files.mastodon.social/media/clip.mp4');

		$media = $document->convertToMediaAttachment(null, ACore::FORMAT_ACTIVITYPUB);

		$this->assertSame('video', $media->getType());
		$this->assertNull($media->getUrl());
		$this->assertSame('', $media->getPreviewUrl());
		$this->assertSame(ACore::FORMAT_ACTIVITYPUB, $media->getExportFormat());
	}

	public function testJsonSerializeAddsTheDocumentFieldsAndParentIdOnlyWhenComplete(): void {
		$document = new Document();
		$document->setId('https://a.example/1')
			->setMediaType('image/png')
			->setMimeType('image/png')
			->setLocalCopy('abc')
			->setResizedCopy('abc_s')
			->setParentId('https://a.example/n/1');

		$json = $document->jsonSerialize();

		$this->assertSame('Document', $json['type']);
		$this->assertSame('image/png', $json['mediaType']);
		$this->assertSame('image/png', $json['mimeType']);
		$this->assertSame('abc', $json['localCopy']);
		$this->assertSame('abc_s', $json['resizedCopy']);
		$this->assertArrayNotHasKey('parentId', $json);

		$document->setCompleteDetails(true);
		$this->assertSame('https://a.example/n/1', $document->jsonSerialize()['parentId']);
	}

	public function testCopySizesDefaultToZero(): void {
		$document = new Document();

		$this->assertSame([0, 0], $document->getLocalCopySize());
		$this->assertSame([0, 0], $document->getResizedCopySize());
	}
}
