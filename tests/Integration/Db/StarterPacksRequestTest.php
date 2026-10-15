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
 * is one row with what was read last and the slug it was first stored with,
 * its members come back in order and whole, the index reads counts rather
 * than member lists, and the packs a reader no longer publishes are found
 * and removed in SQL.
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

	/** @return list<array<string, mixed>> this run's rows in the index */
	private function mine(): array {
		return array_values(array_filter(
			$this->request->getIndex(),
			fn (array $pack): bool => str_starts_with($pack['slug'], 'test-' . $this->run)
		));
	}

	public function testACollectionReadAgainIsOneRowWithWhatWasReadLastAndItsFirstSlug(): void {
		$this->assertTrue($this->request->save($this->pack('1', 'News', ['https://a.example/users/one', 'https://b.example/users/two'])));
		$again = $this->pack('1', 'News, again', ['https://b.example/users/two', 'https://c.example/users/ünï']);
		$again['slug'] = 'test-' . $this->run . '-1-longer-hash';
		$this->assertTrue($this->request->save($again));

		$this->assertCount(1, $this->mine());
		$row = $this->request->getByUri(self::URI . $this->run . '-1');
		$this->assertSame('test-' . $this->run . '-1', $row['slug']);
		$this->assertSame('News, again', $row['name']);
		$this->assertSame(['https://b.example/users/two', 'https://c.example/users/ünï'], $row['members']);
		$this->assertSame('Wörter & <mehr>', $row['summary']);
		$this->assertSame('curator@packs.example', $row['curator_acct']);
		$this->assertSame('bluesky', $row['origin']);
		$this->assertSame(1_790_000_000, $row['fetched']);
		$this->assertSame($row, $this->request->getBySlug('test-' . $this->run . '-1'));
		$this->assertNull($this->request->getBySlug('test-' . $this->run . '-1-longer-hash'));
	}

	public function testASlugAnotherPackHoldsIsRefusedWithoutAnError(): void {
		$this->request->save($this->pack('4', 'First', ['https://a.example/users/a']));
		$taken = $this->pack('5', 'Second', ['https://a.example/users/b']);
		$taken['slug'] = 'test-' . $this->run . '-4';

		$this->assertFalse($this->request->save($taken));
		$this->assertNull($this->request->getByUri($taken['uri']));
		$this->assertSame(['First'], array_column($this->mine(), 'name'));
	}

	public function testTheIndexIsByNameWithCountsAndLeavesOutAnEmptyPack(): void {
		$this->request->save($this->pack('2', 'Zebras', ['https://a.example/users/z']));
		$this->request->save($this->pack('3', 'Artists', ['https://a.example/users/a', 'https://a.example/users/b']));
		$this->request->save($this->pack('6', 'Nobody', []));

		$rows = $this->mine();
		$this->assertSame(['Artists', 'Zebras'], array_column($rows, 'name'));
		$this->assertSame([2, 1], array_column($rows, 'member_count'));
		$this->assertArrayNotHasKey('members', $rows[0]);
	}

	public function testThePacksNoLongerPublishedAreFoundByOriginAndRemoved(): void {
		$this->request->save($this->pack('7', 'Kept', ['https://a.example/users/k']));
		$this->request->save($this->pack('8', 'Gone', ['https://a.example/users/g']));
		$fediverse = $this->pack('9', 'Other network', ['https://a.example/users/o']);
		$fediverse['origin'] = 'fediverse';
		$this->request->save($fediverse);

		$stale = array_values(array_filter(
			$this->request->staleOf('bluesky', [self::URI . $this->run . '-7']),
			fn (array $pack): bool => str_starts_with($pack['uri'], self::URI . $this->run)
		));
		$this->assertSame([self::URI . $this->run . '-8'], array_column($stale, 'uri'));
		$this->assertSame('https://packs.example/users/curator', $stale[0]['curator']);

		$this->request->deleteByIds(array_column($stale, 'id'));
		$this->assertSame(['Kept', 'Other network'], array_column($this->mine(), 'name'));
	}

	private function cleanUp(): void {
		$this->request->deleteByIds(array_column(array_values(array_filter(
			[...$this->request->staleOf('bluesky', []), ...$this->request->staleOf('fediverse', [])],
			fn (array $pack): bool => str_starts_with($pack['uri'], self::URI . $this->run)
		)), 'id'));
	}
}
