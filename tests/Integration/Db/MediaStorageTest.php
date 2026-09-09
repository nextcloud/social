<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The media upload path end to end against the real appdata storage and
 * database: content sniffing, the cached original and resized copies, the
 * ownership scoping mediaGet/mediaUpdate rely on, and the alt-text update.
 */
class MediaStorageTest extends TestCase {
	private const OWNER = 'mediastore-itest';

	private CacheDocumentService $cacheDocumentService;
	private CacheDocumentsRequest $cacheDocumentsRequest;
	private DocumentService $documentService;

	/** @var string[] document ids to remove */
	private array $documents = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cacheDocumentService = Server::get(CacheDocumentService::class);
		$this->cacheDocumentsRequest = Server::get(CacheDocumentsRequest::class);
		$this->documentService = Server::get(DocumentService::class);
	}

	protected function tearDown(): void {
		foreach ($this->documents as $id) {
			$this->cacheDocumentsRequest->deleteById($id);
		}
		parent::tearDown();
	}

	/** a real 40x20 PNG on disk, like an uploaded temp file */
	private function pngFile(): string {
		$path = tempnam(sys_get_temp_dir(), 'social-media-itest');
		$image = imagecreatetruecolor(40, 20);
		imagefilledrectangle($image, 0, 0, 39, 19, imagecolorallocate($image, 200, 60, 30));
		imagepng($image, $path);
		imagedestroy($image);

		return $path;
	}

	private function upload(string $description = ''): Document {
		$document = new Document();
		$document->setLocal(true);
		$document->setAccount(self::OWNER);
		$document->setUrlCloud('https://cloud.example.org');
		$document->generateUniqueId('/documents/local');
		$document->setPublic(true);
		$document->setDescription($description);

		$tmp = $this->pngFile();
		try {
			$this->cacheDocumentService->saveFromTempToCache($document, $tmp);
		} finally {
			unlink($tmp);
		}
		$this->cacheDocumentsRequest->save($document);
		$this->documents[] = $document->getId();

		return $document;
	}

	public function testAnUploadIsSniffedCachedAndResized(): void {
		$document = $this->upload('a red rectangle');

		$this->assertSame('image/png', $document->getMediaType(), 'type comes from the content, not the client');
		$this->assertNotSame('', $document->getLocalCopy());
		$this->assertNotSame('', $document->getResizedCopy());
		$this->assertGreaterThan(0, $document->getNid(), 'the id clients use in media_ids');

		$file = $this->cacheDocumentService->getFromUuid($document->getLocalCopy());
		$this->assertSame('image/png', mime_content_type('data://application/octet-stream;base64,' . base64_encode($file->getContent())));
	}

	/**
	 * A preview link carries the *resized* uuid, so both copies have to resolve
	 * to their document. While the lookup knew only `local_copy`, every preview
	 * in every timeline answered 404 and no picture rendered.
	 */
	public function testEitherCopyOfADocumentResolvesByItsUuid(): void {
		$document = $this->upload('a red rectangle');
		$this->assertNotSame(
			$document->getLocalCopy(),
			$document->getResizedCopy(),
			'the two copies are distinct files'
		);

		[$full, $fromFull] = $this->documentService->getFromUuid($document->getLocalCopy());
		[$preview, $fromResized] = $this->documentService->getFromUuid($document->getResizedCopy());

		$this->assertSame($document->getId(), $fromFull->getId());
		$this->assertSame(
			$document->getId(),
			$fromResized->getId(),
			'the resized uuid names the same document'
		);
		$this->assertNotSame('', $full->getContent());
		$this->assertNotSame('', $preview->getContent());
	}

	public function testOwnershipScopingAndAltTextUpdate(): void {
		$document = $this->upload('before');
		$nid = (string)$document->getNid();

		$mine = $this->documentService->getMediaFromArray([$nid], self::OWNER);
		$this->assertCount(1, $mine);
		$this->assertSame('before', $mine[0]->getDescription());

		$this->assertSame(
			[],
			$this->documentService->getMediaFromArray([$nid], 'someone-else'),
			'an attachment never resolves for another account'
		);

		$document->setDescription('after');
		$this->documentService->updateDescription($document);
		$this->assertSame('after', $this->documentService->getMediaFromArray([$nid], self::OWNER)[0]->getDescription());
	}

	public function testTheAttachmentCarriesTheAltTextOnTheWire(): void {
		$document = $this->upload('a red rectangle');

		$attachment = $document->convertToMediaAttachment(null, Document::FORMAT_ACTIVITYPUB);

		$this->assertSame('a red rectangle', $attachment->asDocument()['name']);
	}
}
