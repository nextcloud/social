<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\RetryMedia;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\DocumentService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RetryMediaTest extends TestCase {
	private const URL = 'https://cdn.remote.example/media/ab12.jpg';
	private const ID = 'https://remote.example/objects/ab12';

	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private DocumentService|MockObject $documentService;
	private CommandTester $tester;

	protected function setUp(): void {
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->tester = new CommandTester(new RetryMedia($this->cacheDocumentsRequest, $this->documentService));
	}

	public function testOnlyTheExactFailedRemoteUrlCanBeRetried(): void {
		$this->cacheDocumentsRequest->expects($this->never())->method('resetRemoteErrorForRetry');
		$this->cacheDocumentsRequest->expects($this->never())->method('getFailedUncachedByUrl');
		$this->documentService->expects($this->never())->method('cacheRemoteDocumentInBackground');

		$this->assertSame(1, $this->tester->execute(['remote_url' => 'file:///etc/passwd']));
		$this->assertStringContainsString('HTTP or HTTPS', $this->tester->getDisplay());
	}

	public function testReportsWhenNoFailedUncachedRowMatches(): void {
		$document = new Document();
		$document->setId(self::ID);
		$this->cacheDocumentsRequest->expects($this->once())
			->method('getFailedUncachedByUrl')
			->with(self::URL)
			->willReturn($document);
		$this->cacheDocumentsRequest->expects($this->once())
			->method('resetRemoteErrorForRetry')
			->with(self::ID)
			->willReturn(false);
		$this->documentService->expects($this->never())->method('cacheRemoteDocumentInBackground');

		$this->assertSame(1, $this->tester->execute(['remote_url' => self::URL]));
		$this->assertStringContainsString('No failed, uncached remote attachment', $this->tester->getDisplay());
	}

	public function testCachesTheSelectedDocumentAfterResettingItsError(): void {
		$document = new Document();
		$document->setId(self::ID);
		$this->cacheDocumentsRequest->expects($this->once())
			->method('getFailedUncachedByUrl')
			->with(self::URL)
			->willReturn($document);
		$this->cacheDocumentsRequest->expects($this->once())
			->method('resetRemoteErrorForRetry')
			->with(self::ID)
			->willReturn(true);
		$document = new Document();
		$document->setId(self::ID);
		$document->setLocalCopy('cached-file');
		$this->documentService->expects($this->once())
			->method('cacheRemoteDocumentInBackground')
			->with(self::ID)
			->willReturn($document);

		$this->assertSame(0, $this->tester->execute(['remote_url' => self::URL]));
		$this->assertStringContainsString('cached successfully', $this->tester->getDisplay());
	}

	public function testReportsWhenTheRetryIsStillRejected(): void {
		$document = new Document();
		$document->setId(self::ID);
		$this->cacheDocumentsRequest->expects($this->once())
			->method('getFailedUncachedByUrl')
			->with(self::URL)
			->willReturn($document);
		$this->cacheDocumentsRequest->method('resetRemoteErrorForRetry')->willReturn(true);
		$this->documentService->expects($this->once())
			->method('cacheRemoteDocumentInBackground')
			->with(self::ID)
			->willThrowException(new CacheDocumentDoesNotExistException());

		$this->assertSame(1, $this->tester->execute(['remote_url' => self::URL]));
		$this->assertStringContainsString('remains subject to', $this->tester->getDisplay());
	}
}
