<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyStatistics;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class BlueskyStatisticsTest extends TestCase {
	private const DID = 'did:plc:alice';
	private const AAA = 'https://bsky.app/profile/did:plc:aaa';
	private const BBB = 'https://bsky.app/profile/did:plc:bbb';
	private const CCC = 'https://bsky.app/profile/did:plc:ccc';

	public static function pdsHosts(): array {
		return [
			'one of Bluesky\'s own hosts' => ['https://morel.us-east.host.bsky.network', 'bsky.social'],
			'Bluesky\'s entryway' => ['https://bsky.social', 'bsky.social'],
			'a PDS of its own' => ['https://pds.example.org', 'pds.example.org'],
			'upper case' => ['https://PDS.Example.org/', 'pds.example.org'],
			'not known' => ['', 'bsky.social'],
		];
	}

	#[DataProvider('pdsHosts')]
	public function testAnAccountLivesOnItsPdsWithBlueskysOwnHostsAsOne(string $pds, string $host): void {
		$this->assertSame($host, BlueskyStatistics::hostOf($pds));
	}

	public function testOnlyBlueskyAccountsAreAnsweredForAndFromTheirCachedPds(): void {
		$aaa = new Person();
		$aaa->setId(self::AAA);
		$aaa->setDetailArray(ActorMapper::DETAIL, ['pds' => 'https://pds.example.org']);
		$cache = $this->createMock(CacheActorsRequest::class);
		$cache->expects($this->once())->method('getFromIds')->with([self::AAA, self::BBB])
			->willReturn([self::AAA => $aaa]);

		$homes = $this->statistics(cache: $cache)->homeServers([self::AAA, 'https://remote.example/users/bob', self::BBB]);

		$this->assertSame([self::AAA => 'pds.example.org', self::BBB => 'bsky.social'], $homes);
		$this->assertSame([], $this->statistics()->homeServers(['https://remote.example/users/bob']));
	}

	public function testThePostsThatWentOutAreCountedInTheAccountsRepository(): void {
		$repositories = $this->createMock(AtprotoRepoRequest::class);
		$repositories->expects($this->once())->method('countRecordsOf')
			->with(self::DID, RecordMapper::POST, ['https://social.test/@alice/1', 'https://social.test/@alice/2'])
			->willReturn(1);

		$reached = $this->statistics(repositories: $repositories)
			->reached($this->alice(), ['https://social.test/@alice/1', 'https://social.test/@alice/2']);

		$this->assertSame(['network' => 'Bluesky', 'posts' => 1], $reached);
	}

	public function testNothingIsReachedWithBlueskyOffOrNoIdentity(): void {
		$this->assertNull($this->statistics(enabled: false)->reached($this->alice(), ['x']));
		$this->assertNull($this->statistics(identity: false)->reached($this->alice(), ['x']));
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId('https://social.test/@alice');
		$alice->setLocal(true);

		return $alice;
	}

	private function statistics(
		bool $enabled = true,
		bool $identity = true,
		?CacheActorsRequest $cache = null,
		?AtprotoRepoRequest $repositories = null,
	): BlueskyStatistics {
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn($enabled);
		$identities = $this->createStub(IdentityService::class);
		$identities->method('forActor')->willReturn($identity
			? new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0)
			: null);

		return new BlueskyStatistics(
			$config,
			$cache ?? $this->createStub(CacheActorsRequest::class),
			$identities,
			$repositories ?? $this->createStub(AtprotoRepoRequest::class),
		);
	}
}
