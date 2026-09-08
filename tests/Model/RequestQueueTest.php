<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use PHPUnit\Framework\TestCase;

class RequestQueueTest extends TestCase {
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	public function testConstructorStoresActivityAuthorAndInstanceAndPicksUpItsPriority(): void {
		$instance = new InstancePath('https://mastodon.social/inbox', InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_HIGH);

		$queue = new RequestQueue('{"type":"Create"}', $instance, 'https://cloud.example.org/apps/social/@alice');

		$this->assertSame('{"type":"Create"}', $queue->getActivity());
		$this->assertSame('https://cloud.example.org/apps/social/@alice', $queue->getAuthor());
		$this->assertSame($instance, $queue->getInstance());
		$this->assertSame(InstancePath::PRIORITY_HIGH, $queue->getPriority());
		$this->assertSame(RequestQueue::STATUS_STANDBY, $queue->getStatus());
		$this->assertSame(0, $queue->getTries());
		$this->assertSame(0, $queue->getLast());
	}

	public function testConstructorWithoutInstanceHasNoInstanceAndNoPriority(): void {
		$queue = new RequestQueue();

		$this->assertNull($queue->getInstance());
		$this->assertSame(0, $queue->getPriority());
	}

	public function testTokenIsAUuidThatChangesOnReset(): void {
		$queue = new RequestQueue();
		$first = $queue->getToken();

		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $first);

		$queue->resetToken();
		$this->assertMatchesRegularExpression(self::UUID_PATTERN, $queue->getToken());
		$this->assertNotSame($first, $queue->getToken());
	}

	public function testTimeoutDefaultsToFiveSeconds(): void {
		$queue = new RequestQueue();

		$this->assertSame(5, $queue->getTimeout());
		$this->assertSame(30, $queue->setTimeout(30)->getTimeout());
	}

	public function testImportFromDatabaseReadsTheRowAndTheSerializedInstance(): void {
		$queue = new RequestQueue();

		$queue->importFromDatabase([
			'id' => '12',
			'token' => 'tok',
			'author' => 'https://cloud.example.org/apps/social/@alice',
			'instance' => '{"uri":"https://mastodon.social/inbox","type":2,"priority":3}',
			'priority' => '3',
			'activity' => '{"type":"Create"}',
			'status' => '1',
			'tries' => '2',
			'last' => '2024-05-01 12:00:00',
		]);

		$this->assertSame(12, $queue->getId());
		$this->assertSame('tok', $queue->getToken());
		$this->assertSame('https://cloud.example.org/apps/social/@alice', $queue->getAuthor());
		$this->assertSame('https://mastodon.social/inbox', $queue->getInstance()->getUri());
		$this->assertSame(InstancePath::TYPE_GLOBAL, $queue->getInstance()->getType());
		$this->assertSame(3, $queue->getPriority());
		$this->assertSame('{"type":"Create"}', $queue->getActivity());
		$this->assertSame(RequestQueue::STATUS_RUNNING, $queue->getStatus());
		$this->assertSame(2, $queue->getTries());
		$this->assertSame((new \DateTime('2024-05-01 12:00:00'))->getTimestamp(), $queue->getLast());
	}

	public function testImportFromDatabaseWithoutLastRunSetsZero(): void {
		$queue = new RequestQueue();

		$queue->importFromDatabase(['id' => 1, 'last' => '']);

		$this->assertSame(0, $queue->getLast());
	}

	public function testJsonSerializeIncludesTheInstance(): void {
		$instance = new InstancePath('https://mastodon.social/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW);
		$queue = new RequestQueue('act', $instance, 'author');
		$queue->setId(3)->setToken('tok')->setStatus(RequestQueue::STATUS_SUCCESS)->setTries(1)->setLast(1714564800);

		$this->assertSame([
			'id' => 3,
			'token' => 'tok',
			'author' => 'author',
			'instance' => ['uri' => 'https://mastodon.social/inbox', 'type' => 1, 'priority' => 1],
			'priority' => 1,
			'status' => 9,
			'tries' => 1,
			'last' => 1714564800,
		], json_decode(json_encode($queue), true));
	}
}
