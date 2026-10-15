<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\StarterPacksRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `social_starter_pack` against the real database: a collection read twice
 * is one row with what was read last, its members come back in order and
 * whole, and a pack is removed by its id.
 */
class StarterPacksRequestTest extends TestCase {
	private const URI = 'https://packs.example/ap/users/1/collections/';

	private StarterPacksRequest $request;
	private string $run;

	protected function setUp(): void {
		$this->request = Server::get(StarterPacksRequest::class);
		$this->run = bin2hex(random_bytes(4));
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	/** @return array{uri: string, slug: string, origin: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, members: list<string>, fetched: int} */
	private function pack(string $n, string $name, array $members): array {
		return [
			'uri' => self::URI . $this->run . '-' . $n,
			'slug' => 'test-' . $this->run . '-' . $n,
			'origin' => 'bluesky',
			'curator' => 'https://packs.example/users/curator',
			'curator_acct' => 'curator@packs.example',
			'name' => $name,
			'summary' => 'Wörter & <mehr>',
			'topic' => 'news',
			'language' => 'de',
			'url' => 'https://packs.example/collections/' . $n,
			'members' => $members,
			'fetched' => 1_790_000_000,
		];
	}

	/** @return list<array<string, mixed>> this run's rows */
	private function mine(): array {
		return array_values(array_filter(
			$this->request->getAll(),
			fn (array $pack): bool => str_starts_with($pack['uri'], self::URI . $this->run)
		));
	}

	public function testACollectionReadAgainIsOneRowWithWhatWasReadLast(): void {
		$this->request->save($this->pack('1', 'News', ['https://a.example/users/one', 'https://b.example/users/two']));
		$this->request->save($this->pack('1', 'News, again', ['https://b.example/users/two', 'https://c.example/users/ünï']));

		$rows = $this->mine();
		$this->assertCount(1, $rows);
		$this->assertSame('News, again', $rows[0]['name']);
		$this->assertSame(['https://b.example/users/two', 'https://c.example/users/ünï'], $rows[0]['members']);
		$this->assertSame('Wörter & <mehr>', $rows[0]['summary']);
		$this->assertSame('curator@packs.example', $rows[0]['curator_acct']);
		$this->assertSame('bluesky', $rows[0]['origin']);
		$this->assertSame(1_790_000_000, $rows[0]['fetched']);
	}

	public function testPacksAreReadByNameAndRemovedById(): void {
		$this->request->save($this->pack('2', 'Zebras', ['https://a.example/users/z']));
		$this->request->save($this->pack('3', 'Artists', ['https://a.example/users/a']));

		$this->assertSame(['Artists', 'Zebras'], array_column($this->mine(), 'name'));

		$this->request->deleteById($this->mine()[0]['id']);
		$this->assertSame(['Zebras'], array_column($this->mine(), 'name'));
	}

	private function cleanUp(): void {
		foreach ($this->mine() as $pack) {
			$this->request->deleteById($pack['id']);
		}
	}
}
