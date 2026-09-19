<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\NetworkStatsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The one set of figures on the statistics page this server did not count.
 *
 * What is asserted here is what happens when somebody else's survey is the
 * source: that it is quoted with its date, that an instance which will not
 * make the request says so by having no section at all rather than zeros, and
 * that a survey which is down is not asked again for every reader.
 */
class NetworkStatsServiceTest extends TestCase {
	private CurlService|MockObject $curlService;
	private ConfigService|MockObject $configService;
	private InstanceStatsRequest|MockObject $instanceStatsRequest;
	private NetworkStatsService $service;

	/** what the survey answers with, or a Throwable it raises */
	private mixed $answer = null;
	/** the `network_stats` app value */
	private string $configured = '';
	/** how many requests were made */
	private int $asked = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->curlService = $this->createMock(CurlService::class);
		$this->curlService->method('retrieveJson')
			->willReturnCallback(function (): array {
				$this->asked++;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return is_array($this->answer) ? $this->answer : [];
			});

		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string
				=> ($key === NetworkStatsService::CONFIG_KEY) ? $this->configured : '');

		$this->instanceStatsRequest = $this->createMock(InstanceStatsRequest::class);
		$this->instanceStatsRequest->method('countRemoteDomains')->willReturn(2841);

		$this->service = $this->build($this->createMock(ICache::class));
	}

	private function build(ICache|MockObject $cache): NetworkStatsService {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new NetworkStatsService(
			$this->curlService,
			$this->configService,
			$this->instanceStatsRequest,
			new NullLogger(),
			$factory
		);
	}

	/** The survey's answer, in the shape fedidb actually sends. */
	private function survey(array $extra = []): array {
		return array_merge([
			'total_users' => 14375788,
			'total_statuses' => 1652970897,
			'total_instances' => 42890,
			'monthly_active_users' => 1306773,
			'last_updated_at' => '2026-09-19T22:15:03+00:00',
		], $extra);
	}

	public function testTheNetworkIsReportedWithThisInstancesOwnReachBesideIt(): void {
		$this->answer = $this->survey();

		$network = $this->service->network();

		$this->assertSame(42890, $network['servers']);
		$this->assertSame(14375788, $network['accounts']);
		$this->assertSame(1306773, $network['active']);
		$this->assertSame(1652970897, $network['posts']);
		// counted here, not quoted: it is the only number in the section that
		// is about this server
		$this->assertSame(2841, $network['peers']);
	}

	public function testTheSurveyIsNamedAndDated(): void {
		$this->answer = $this->survey();

		$network = $this->service->network();

		// a figure about the whole fediverse is somebody's survey, and a page
		// that does not say whose is claiming it
		$this->assertSame('FediDB', $network['source']);
		$this->assertSame('https://fedidb.org', $network['source_url']);
		$this->assertSame('2026-09-19T22:15:03+00:00', $network['measured']);
	}

	public function testAnAdministratorCanRefuseTheOutboundRequest(): void {
		$this->configured = '0';
		$this->answer = $this->survey();

		$this->assertNull($this->service->network());
		$this->assertSame(0, $this->asked, 'the request was made anyway');
	}

	public function testASurveyThatDoesNotAnswerLeavesTheSectionOut(): void {
		$this->answer = new RuntimeException('down');

		$this->assertNull($this->service->network());
	}

	/**
	 * An answer with no count of servers is not an answer: that figure is the
	 * one the section exists to put beside "the servers this one talks to".
	 */
	public function testAnAnswerMissingTheOneFigureThatMattersIsRefused(): void {
		$this->answer = ['total_users' => 100];

		$this->assertNull($this->service->network());
	}

	public function testTheAnswerIsKeptRatherThanAskedForEveryReader(): void {
		$this->answer = $this->survey();
		$kept = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$kept): bool {
			$kept[$key] = $value;

			return true;
		});
		$cache->method('get')->willReturnCallback(function (string $key) use (&$kept) {
			return is_string($kept[$key] ?? null) ? $kept[$key] : null;
		});
		$this->service = $this->build($cache);

		$first = $this->service->network();
		$second = $this->service->network();

		$this->assertSame($first, $second);
		$this->assertSame(1, $this->asked);
	}

	public function testASurveyThatIsDownIsNotAskedAgainForTheNextReader(): void {
		$this->answer = new RuntimeException('down');
		$kept = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$kept): bool {
			$kept[$key] = $value;

			return true;
		});
		$cache->method('get')->willReturnCallback(function (string $key) use (&$kept) {
			return is_string($kept[$key] ?? null) ? $kept[$key] : null;
		});
		$this->service = $this->build($cache);

		$this->service->network();
		$this->service->network();

		// a statistics page that cannot reach the survey must not spend four
		// seconds finding that out again
		$this->assertSame(1, $this->asked);
	}
}
