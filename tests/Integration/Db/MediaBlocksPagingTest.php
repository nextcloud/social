<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\MediaBlocksRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The refused files against a real database: every one of them reachable a
 * page at a time, newest first, and one on an older page can be lifted.
 */
class MediaBlocksPagingTest extends TestCase {
	private MediaBlocksRequest $request;

	/** @var string[] oldest first */
	private array $hashes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(MediaBlocksRequest::class);
		foreach (['first', 'second', 'third'] as $name) {
			$this->hashes[] = hash('sha256', 'mbtest/' . $name);
		}
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ($this->hashes as $hash) {
			$this->request->unblock($hash);
		}
	}

	public function testEveryRefusedFileIsReachableAPageAtATime(): void {
		$before = $this->request->count();
		foreach ($this->hashes as $hash) {
			$this->request->block($hash, 'mbtest', 'alice');
		}
		$this->assertSame($before + 3, $this->request->count());

		$first = $this->request->getPage(2);
		$this->assertSame(
			[$this->hashes[2], $this->hashes[1]],
			array_column($first, 'hash'),
			'the newest first'
		);
		$this->assertGreaterThan($first[1]['id'], $first[0]['id']);

		$second = $this->request->getPage(2, $first[1]['id']);
		$this->assertSame($this->hashes[0], $second[0]['hash'], 'the next page starts right after the last row');

		$this->assertTrue($this->request->unblock($second[0]['hash']));
		$this->assertFalse($this->request->isBlocked($this->hashes[0]));
		$this->assertSame($before + 2, $this->request->count());
	}
}
