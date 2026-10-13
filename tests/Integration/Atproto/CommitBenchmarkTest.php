<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Db\AtprotoRepoRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What one commit costs on a repository of twenty thousand records,
 * against what rebuilding the tree from every record costs — the price
 * every commit paid before the tree was edited in place. Prints both;
 * runs only when SOCIAL_BENCHMARK is set, as it seeds and drops twenty
 * thousand rows.
 */
class CommitBenchmarkTest extends TestCase {
	private const DID = 'did:plc:integrationbench0000000';
	private const RECORDS = 20000;

	private RepositoryService $repositories;
	private AtprotoRepoRequest $repoRequest;

	protected function setUp(): void {
		if ((string)getenv('SOCIAL_BENCHMARK') === '') {
			$this->markTestSkipped('SOCIAL_BENCHMARK is not set');
		}
		$this->repositories = Server::get(RepositoryService::class);
		$this->repoRequest = Server::get(AtprotoRepoRequest::class);
		$this->repositories->delete(self::DID);
	}

	protected function tearDown(): void {
		if (isset($this->repositories)) {
			$this->repositories->delete(self::DID);
		}
	}

	public function testOneCommitOnALargeRepository(): void {
		$key = PrivateKey::generate(Curve::K256);
		$records = [];
		for ($i = 0; $i < self::RECORDS; $i++) {
			$records['app.bsky.feed.like/3kznmn7x' . str_pad(base_convert((string)$i, 10, 32), 5, '0', STR_PAD_LEFT)] = DagCbor::encode([
				'$type' => 'app.bsky.feed.like',
				'subject' => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', 'cid' => 'bafyreie5cvv4h45feadgeuwhbcutmh6t2ceseocckahdoe6uat64zmz454'],
				'createdAt' => '2026-10-08T10:00:00.000Z',
			]);
		}
		$this->repositories->import(self::DID, $key, $records);
		$this->assertSame(self::RECORDS, $this->repositories->getHead(self::DID)?->recordCount);

		$started = hrtime(true);
		$leaves = $this->repoRequest->getLeaves(self::DID);
		$tree = (new Mst($leaves))->build();
		$stored = $this->repoRequest->getBlockCids(self::DID, AtprotoRepoRequest::BLOCK_MST);
		$rebuild = (hrtime(true) - $started) / 1e6;
		$this->assertCount(count($tree->blocks), $stored);

		$started = hrtime(true);
		$this->repositories->write(self::DID, $key, [RepoWrite::create('app.bsky.feed.post', ['$type' => 'app.bsky.feed.post', 'text' => 'one more', 'createdAt' => '2026-10-08T10:00:00.000Z'], '', '3kznmn7xqxl22')]);
		$commit = (hrtime(true) - $started) / 1e6;

		fwrite(STDOUT, sprintf("\ncommit on %d records: %.1f ms (reading and rebuilding the whole tree: %.1f ms)\n", self::RECORDS, $commit, $rebuild));
		$this->assertSame(self::RECORDS + 1, $this->repositories->getHead(self::DID)?->recordCount);
		$this->assertLessThan($rebuild, $commit, 'a commit costs less than a rebuild of the tree');
	}
}
