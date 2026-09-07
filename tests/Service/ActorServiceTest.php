<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ActorServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example.com/apps/social/@alice';
	private const ICON_URL = 'https://cloud.example.com/avatar/alice/128';

	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private AP|MockObject $ap;
	private ActorService $service;

	protected function setUp(): void {
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->ap = $this->createMock(AP::class);
		AP::$activityPub = $this->ap;

		$this->service = new ActorService(
			$this->cacheActorsRequest,
			$this->cacheDocumentsRequest,
			$this->createMock(CurlService::class),
			$this->createMock(ConfigService::class),
			$this->createMock(MiscService::class),
		);
	}

	protected function tearDown(): void {
		AP::$activityPub = null;
	}

	private function alice(bool $withIcon = false): Person {
		$alice = new Person();
		$alice->setId(self::ALICE);
		$alice->setPreferredUsername('alice');
		if ($withIcon) {
			$icon = new Image();
			$icon->setUrl(self::ICON_URL);
			$icon->setMediaType('image/png');
			$alice->setIcon($icon);
		}

		return $alice;
	}

	public function testCacheLocalActorUpdatesAnAlreadyCachedActor(): void {
		$alice = $this->alice();
		$this->cacheActorsRequest->method('getFromId')->with(self::ALICE)->willReturn(new Person());
		$this->cacheActorsRequest->expects($this->once())->method('update')->with($this->identicalTo($alice))->willReturn(1);
		$this->cacheActorsRequest->expects($this->never())->method('save');

		$this->service->cacheLocalActor($alice);

		$this->assertTrue($alice->isLocal());
		$source = json_decode($alice->getSource(), true);
		$this->assertSame(self::ALICE, $source['id']);
		$this->assertSame('alice', $source['preferredUsername']);
	}

	public function testCacheLocalActorSavesANewActor(): void {
		$alice = $this->alice();
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->cacheActorsRequest->expects($this->once())->method('save')->with($this->identicalTo($alice));
		$this->cacheActorsRequest->expects($this->never())->method('update');

		$this->service->cacheLocalActor($alice);
	}

	public function testCacheLocalActorDetailsDelegates(): void {
		$alice = $this->alice();
		$this->cacheActorsRequest->expects($this->once())->method('updateDetails')->with($this->identicalTo($alice));

		$this->service->cacheLocalActorDetails($alice);
	}

	public function testSaveReusesAnAlreadyCachedIcon(): void {
		$alice = $this->alice(true);
		$cachedIcon = new Image();
		$cachedIcon->setUrl(self::ICON_URL);
		$cachedIcon->setLocalCopy('local-copy');
		$this->cacheDocumentsRequest->expects($this->once())->method('getByUrl')->with(self::ICON_URL)->willReturn($cachedIcon);
		$this->ap->expects($this->never())->method('getInterfaceFromType');
		$this->cacheActorsRequest->expects($this->once())->method('save')->with($this->identicalTo($alice));

		$this->service->save($alice);

		$this->assertSame($cachedIcon, $alice->getIcon());
		$this->assertSame($alice, $cachedIcon->getParent());
	}

	public function testSaveCachesAnUnknownIconThroughItsInterface(): void {
		$alice = $this->alice(true);
		$originalIcon = $alice->getIcon();
		$this->cacheDocumentsRequest->method('getByUrl')->willThrowException(new CacheDocumentDoesNotExistException());
		$imageInterface = $this->createMock(ImageInterface::class);
		$imageInterface->expects($this->once())->method('save')->with($this->identicalTo($originalIcon));
		$this->ap->expects($this->once())->method('getInterfaceFromType')->with(Image::TYPE)->willReturn($imageInterface);
		$this->cacheActorsRequest->expects($this->once())->method('save');

		$this->service->save($alice);

		$this->assertSame($originalIcon, $alice->getIcon());
	}

	public function testSaveToleratesAnIconTypeWithoutInterface(): void {
		$alice = $this->alice(true);
		$this->cacheDocumentsRequest->method('getByUrl')->willThrowException(new CacheDocumentDoesNotExistException());
		$this->ap->method('getInterfaceFromType')->willThrowException(new ItemUnknownException());
		$this->cacheActorsRequest->expects($this->once())->method('save');

		$this->service->save($alice);
	}

	public function testSaveWithoutIconSkipsTheDocumentCache(): void {
		$this->cacheDocumentsRequest->expects($this->never())->method('getByUrl');
		$this->cacheActorsRequest->expects($this->once())->method('save');

		$this->service->save($this->alice());
	}

	public function testUpdateReturnsTheNumberOfTouchedRows(): void {
		$alice = $this->alice(true);
		$this->cacheDocumentsRequest->method('getByUrl')->willReturn(new Image());
		$this->cacheActorsRequest->expects($this->once())->method('update')->with($this->identicalTo($alice))->willReturn(1);

		$this->assertSame(1, $this->service->update($alice));
	}

	public function testGetCachedHeaderReadsTheLocalCache(): void {
		$cached = new Person();
		$cached->setHeader('https://cloud.example.com/apps/social/media/header.jpg');
		$this->cacheActorsRequest->expects($this->once())->method('getFromLocalAccount')->with('alice')->willReturn($cached);

		$this->assertSame('https://cloud.example.com/apps/social/media/header.jpg', $this->service->getCachedHeader($this->alice()));
	}

	public function testGetCachedHeaderIsEmptyWhenNoneIsSet(): void {
		$this->cacheActorsRequest->method('getFromLocalAccount')->willReturn(new Person());

		$this->assertSame('', $this->service->getCachedHeader($this->alice()));
	}

	public function testGetCachedHeaderIsEmptyWhenTheActorIsNotCached(): void {
		$this->cacheActorsRequest->method('getFromLocalAccount')
			->willThrowException(new CacheActorDoesNotExistException());

		$this->assertSame('', $this->service->getCachedHeader($this->alice()));
	}
}
