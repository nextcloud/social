<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Service;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\KeySealService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\AtprotoStatusService;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AtprotoStatusServiceTest extends TestCase {
	/** @var array<string, string> what each relay answers about this server */
	private array $relayAnswers = [];
	/** how many keys do not open with the instance secret */
	private int $sealedKeys = 0;

	private function service(array $relays, bool $firehoseUpgraded = false): AtprotoStatusService {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('pdsEndpoint')->willReturn('https://social.example');
		$config->method('handleHost')->willReturn('social.example');
		$config->method('relays')->willReturn($relays);
		$curl = $this->createMock(CurlService::class);
		$curl->method('doWebSocketHandshake')->willReturnCallback(static function (string $url, &$status) use ($firehoseUpgraded): bool {
			$status = $firehoseUpgraded ? 101 : 426;

			return $firehoseUpgraded;
		});
		$curl->method('doRequest')->willReturnCallback(function (string $verb, string $url, array $options, &$contentType, &$status): string {
			if (str_ends_with($url, '/xrpc/com.atproto.sync.subscribeRepos')) {
				$status = 426;

				return '';
			}
			foreach ($this->relayAnswers as $relay => $answer) {
				if (str_starts_with($url, $relay . '/xrpc/com.atproto.sync.getHostStatus?hostname=social.example')) {
					$status = str_contains($answer, 'HostNotFound') ? 400 : 200;

					return $answer;
				}
			}
			$status = 404;

			return '';
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$daemon = $this->createMock(FirehoseDaemon::class);
		$daemon->method('status')->willReturn(['running' => true]);

		$keySeals = $this->createMock(KeySealService::class);
		$keySeals->method('countSealed')->willReturnCallback(fn (): int => $this->sealedKeys);

		return new AtprotoStatusService($config, $this->createMock(ConfigService::class), $this->createMock(IdentityService::class), $this->createMock(InstanceKeyService::class), $this->createMock(EventService::class), $daemon, $this->createMock(AtprotoRepoRequest::class), $curl, $this->createMock(IConfig::class), $cacheFactory, $this->createMock(ITimeFactory::class), null, null, null, $keySeals);
	}

	private function keysCheck(): array {
		foreach ($this->service([])->checks(true) as $check) {
			if ($check['id'] === 'keys') {
				return $check;
			}
		}
		$this->fail('no keys check');
	}

	public function testKeysThatNoLongerOpenAreAnErrorThatNamesTheCommand(): void {
		$this->assertSame(['id' => 'keys', 'state' => 'ok', 'detail' => ''], $this->keysCheck());

		$this->sealedKeys = 3;
		$check = $this->keysCheck();
		$this->assertSame('error', $check['state']);
		$this->assertSame('3 keys cannot be opened with the current secret: run occ social:atproto:keys:reseal with the previous secret in OC_OLD_SECRET', $check['detail']);
	}

	private function relayCheck(array $relays): array {
		foreach ($this->service($relays)->checks(true) as $check) {
			if ($check['id'] === 'relay_host') {
				return $check;
			}
		}
		$this->fail('no relay check');
	}

	public function testARelayThatCarriesThisServerSaysHowMany(): void {
		$this->relayAnswers['https://bsky.network'] = '{"hostname":"social.example","accountCount":42,"seq":7,"status":"active"}';

		$this->assertSame(['id' => 'relay_host', 'state' => 'ok', 'detail' => 'bsky.network: active, 42 accounts'], $this->relayCheck(['https://bsky.network']));
	}

	public function testAThrottlingOrUnawareRelayIsAWarningWithWhatToDo(): void {
		$this->relayAnswers['https://bsky.network'] = '{"hostname":"social.example","accountCount":100,"status":"throttled"}';
		$this->relayAnswers['https://relay.example'] = '{"error":"HostNotFound","message":"host not found"}';

		$check = $this->relayCheck(['https://bsky.network', 'https://relay.example']);

		$this->assertSame('warning', $check['state'], 'never an error: the switch does not wait for a relay');
		$this->assertSame('bsky.network throttles this server at 100 accounts: ask its operator to raise the limit for this host; relay.example has not crawled this server yet: run occ social:atproto:crawl', $check['detail']);
	}

	public function testNoRelayIsAWarning(): void {
		$this->assertSame('warning', $this->relayCheck([])['state']);
	}

	public function testHttp426WarnsThatTheFirehoseWebsocketIsNotProxied(): void {
		$firehose = array_values(array_filter($this->service([])->checks(true), static fn (array $check): bool => $check['id'] === 'firehose'))[0];

		$this->assertSame('warning', $firehose['state']);
		$this->assertStringContainsString('did not accept a WebSocket upgrade', $firehose['detail']);
	}

	public function testAValidFirehoseWebsocketUpgradeIsHealthy(): void {
		$firehose = array_values(array_filter($this->service([], true)->checks(true), static fn (array $check): bool => $check['id'] === 'firehose'))[0];

		$this->assertSame(['id' => 'firehose', 'state' => 'ok', 'detail' => ''], $firehose);
	}

	public function testPublicDidProfileAndPostVisibilityAreChecked(): void {
		$identity = new Identity(1, 'https://social.example/users/alice', 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'alice.social.example', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('firstActive')->willReturn($identity);
		$record = new StoredRecord($identity->did, 'app.bsky.feed.post', '3kabc', Cid::parse('bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm'), '', '1', 1);
		$records = $this->createMock(AtprotoRepoRequest::class);
		$records->method('latestActivePost')->willReturn($record);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('pdsEndpoint')->willReturn('https://social.example');
		$config->method('handleHost')->willReturn('social.example');
		$config->method('plcDirectory')->willReturn('https://plc.directory');
		$config->method('relays')->willReturn([]);
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(static function (string $verb, string $url, array $options, &$contentType, &$status): string {
			$status = 200;
			if ($url === 'https://plc.directory/' . rawurlencode('did:plc:ewvi7nxzyoun6zhxrhs64oiz')) {
				return json_encode(['id' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz', 'service' => [['id' => '#atproto_pds', 'serviceEndpoint' => 'https://social.example']]]);
			}
			$status = 404;

			return '';
		});
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(static function (string $method, array $params): array {
			if ($method === 'app.bsky.actor.getProfile') {
				return ['did' => $params['actor']];
			}
			return ['thread' => ['post' => ['uri' => $params['uri']]]];
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$daemon = $this->createMock(FirehoseDaemon::class);
		$daemon->method('status')->willReturn(['running' => true]);
		$status = new AtprotoStatusService($config, $this->createMock(ConfigService::class), $identities, $this->createMock(InstanceKeyService::class), $this->createMock(EventService::class), $daemon, $records, $curl, $this->createMock(IConfig::class), $cacheFactory, $this->createMock(ITimeFactory::class), null, null, null, null, $appView);
		$checks = array_column($status->checks(true), null, 'id');

		$this->assertSame('ok', $checks['did_resolution']['state']);
		$this->assertSame('ok', $checks['appview_profile']['state']);
		$this->assertSame('ok', $checks['appview_post']['state']);
	}
}
