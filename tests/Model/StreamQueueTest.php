<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\StreamQueue;
use PHPUnit\Framework\TestCase;

class StreamQueueTest extends TestCase {
	public function testConstructorStoresTokenTypeAndStream(): void {
		$queue = new StreamQueue('tok', StreamQueue::TYPE_CACHE, 'https://a.example/n/1');

		$this->assertSame('tok', $queue->getToken());
		$this->assertSame('Cache', $queue->getType());
		$this->assertSame('https://a.example/n/1', $queue->getStreamId());
		$this->assertSame(StreamQueue::STATUS_STANDBY, $queue->getStatus());
		$this->assertSame(0, $queue->getTries());
		$this->assertSame(0, $queue->getLast());
	}

	public function testImportFromDatabaseReadsTheRow(): void {
		$queue = new StreamQueue();

		$queue->importFromDatabase([
			'id' => '4',
			'token' => 'tok',
			'stream_id' => 'https://a.example/n/1',
			'type' => StreamQueue::TYPE_VERIFY,
			'status' => '1',
			'tries' => '3',
			'last' => '2024-05-01 12:00:00',
		]);

		$this->assertSame(4, $queue->getId());
		$this->assertSame('tok', $queue->getToken());
		$this->assertSame('https://a.example/n/1', $queue->getStreamId());
		$this->assertSame('Signature', $queue->getType());
		$this->assertSame(StreamQueue::STATUS_RUNNING, $queue->getStatus());
		$this->assertSame(3, $queue->getTries());
		$this->assertSame((new \DateTime('2024-05-01 12:00:00'))->getTimestamp(), $queue->getLast());
	}

	public function testImportFromDatabaseWithoutLastRunSetsZero(): void {
		$queue = new StreamQueue();
		$queue->setLast(99);

		$queue->importFromDatabase(['id' => 1, 'last' => '']);

		$this->assertSame(0, $queue->getLast());
	}

	public function testJsonSerializeExposesTheQueueEntry(): void {
		$queue = (new StreamQueue('tok', StreamQueue::TYPE_CACHE, 'stream'))
			->setId(2)
			->setStatus(StreamQueue::STATUS_SUCCESS)
			->setTries(1)
			->setLast(1714564800);

		$this->assertSame([
			'id' => 2,
			'token' => 'tok',
			'streamId' => 'stream',
			'type' => 'Cache',
			'status' => 9,
			'tries' => 1,
			'last' => 1714564800,
		], $queue->jsonSerialize());
	}
}
