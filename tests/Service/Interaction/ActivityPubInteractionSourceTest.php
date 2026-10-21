<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Interaction;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\Interaction\ActivityPubInteractionSource;
use OCA\Social\Service\RemoteFetchQueue;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ActivityPubInteractionSourceTest extends TestCase {
	public function testTheAccountsItsServerListsAreTakenAndTheUnknownOnesFetched(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$post->setSource((string)json_encode([
			'likes' => 'https://remote.example/notes/1/likes',
			'shares' => ['type' => 'Collection', 'totalItems' => 4],
		]));
		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveObject')->with('https://remote.example/notes/1/likes')->willReturn(['type' => 'OrderedCollection', 'orderedItems' => [
			['type' => 'Like', 'actor' => 'https://a.example/users/ann'],
			['type' => 'Like', 'actor' => ['id' => 'https://b.example/users/ben']],
			'https://c.example/users/cy',
			['type' => 'Like', 'actor' => 'javascript:alert(1)'],
		]]);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->exactly(2))->method('resolveActors');
		$source = new ActivityPubInteractionSource($curl, $queue, new NullLogger());

		$this->assertTrue($source->supports($post));
		$this->assertSame(['https://a.example/users/ann', 'https://b.example/users/ben', 'https://c.example/users/cy'], $source->actors($post, 'Like', 10));
		$this->assertSame([], $source->actors($post, 'Announce', 10), 'a count is nobody to name');
		$this->assertSame(0, $source->quotes($post, 10));
		$this->assertFalse($source->supports((new Note())->setId('https://social.test/@alice/1')->setLocal(true)));
	}

	public function testAVisibleReadBoundsTheRemoteCollectionRequest(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$post->setSource((string)json_encode(['likes' => 'https://remote.example/notes/1/likes']));
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->once())->method('retrieveObject')
			->with('https://remote.example/notes/1/likes', true, 3)
			->willReturn(['type' => 'OrderedCollection', 'orderedItems' => ['https://a.example/users/ann']]);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->once())->method('resolveActors')->with(['https://a.example/users/ann']);
		$source = new ActivityPubInteractionSource($curl, $queue, new NullLogger());

		$this->assertSame(['https://a.example/users/ann'], $source->actors($post, 'Like', 10, 3));
	}

	public function testFollowsActivityPubPageLinksEncodedAsObjects(): void {
		$post = (new Note())->setId('https://remote.example/notes/1');
		$post->setSource((string)json_encode(['likes' => [
			'type' => 'OrderedCollection',
			'first' => ['id' => 'https://remote.example/notes/1/likes?page=1'],
		]]));
		$requested = [];
		$curl = $this->createMock(CurlService::class);
		$curl->expects($this->exactly(2))->method('retrieveObject')->willReturnCallback(
			static function (string $id, bool $acceptActivityJson = true, ?int $timeout = null) use (&$requested): array {
				$requested[] = [$id, $acceptActivityJson, $timeout];

				return match ($id) {
					'https://remote.example/notes/1/likes?page=1' => [
						'type' => 'OrderedCollectionPage',
						'orderedItems' => ['https://a.example/users/ann'],
						'next' => ['id' => 'https://remote.example/notes/1/likes?page=2'],
					],
					'https://remote.example/notes/1/likes?page=2' => [
						'type' => 'OrderedCollectionPage',
						'orderedItems' => ['https://b.example/users/ben'],
					],
					default => throw new \UnexpectedValueException('Unexpected collection URL: ' . $id),
				};
			}
		);
		$queue = $this->createMock(RemoteFetchQueue::class);
		$queue->expects($this->once())->method('resolveActors')->with([
			'https://a.example/users/ann',
			'https://b.example/users/ben',
		]);
		$source = new ActivityPubInteractionSource($curl, $queue, new NullLogger());

		$this->assertSame([
			'https://a.example/users/ann',
			'https://b.example/users/ben',
		], $source->actors($post, 'Like', 10));
		$this->assertSame([
			['https://remote.example/notes/1/likes?page=1', true, null],
			['https://remote.example/notes/1/likes?page=2', true, null],
		], $requested);
	}
}
