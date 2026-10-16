<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Service;

use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamState;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\AtprotoFiguresService;
use OCA\Social\Controller\AtprotoAdminController;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Service\DurableCache;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The Bluesky figures of the admin page. */
#[AllowMockObjectsWithoutExpectations]
class AtprotoFiguresServiceTest extends TestCase {
	private const NOW = 1760000000;

	private AtprotoConfig&MockObject $config;
	private JetstreamState&MockObject $jetstream;
	private IdentityService&MockObject $identities;
	private AtprotoRepoRequest&MockObject $repos;
	private AtprotoWatchRequest&MockObject $watches;
	private EventService&MockObject $events;
	private FirehoseDaemon&MockObject $daemon;
	private DurableCache&MockObject $cache;

	protected function setUp(): void {
		$this->config = $this->createMock(AtprotoConfig::class);
		$this->config->method('isEnabled')->willReturn(true);
		$this->config->method('jetstream')->willReturn('');
		$this->jetstream = $this->createMock(JetstreamState::class);
		$this->identities = $this->createMock(IdentityService::class);
		$this->repos = $this->createMock(AtprotoRepoRequest::class);
		$this->repos->method('sumHeads')->willReturn(['records' => 0, 'blob_bytes' => 0]);
		$this->watches = $this->createMock(AtprotoWatchRequest::class);
		$this->events = $this->createMock(EventService::class);
		$this->daemon = $this->createMock(FirehoseDaemon::class);
		$this->cache = $this->createMock(DurableCache::class);
	}

	private function service(): AtprotoFiguresService {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new AtprotoFiguresService(
			$this->config, $this->jetstream, $this->identities, $this->repos, $this->watches,
			$this->events, $this->daemon, $this->cache, $time,
		);
	}

	private function disabledConfig(): void {
		$this->config = $this->createMock(AtprotoConfig::class);
		$this->config->method('isEnabled')->willReturn(false);
	}

	private function jetstreamAt(string $endpoint): void {
		$enabled = $this->createMock(AtprotoConfig::class);
		$enabled->method('isEnabled')->willReturn(true);
		$enabled->method('jetstream')->willReturn($endpoint);
		$this->config = $enabled;
	}

	public function testTheAccountsWithAnIdentityAndTheSwitchedOffAreCounted(): void {
		$this->identities->method('count')->willReturn(12);
		$this->identities->method('countDeactivated')->willReturn(2);

		$this->assertSame(['identities' => 12, 'off' => 2], $this->service()->figures()['accounts']);
	}

	/** A day and a week back from now, in the post collection. */
	public function testThePostsOfTheLastDayAndWeekAreCounted(): void {
		$this->repos->method('countRecordsWrittenSince')->willReturnMap([
			[RecordMapper::POST, self::NOW - 86400, 7],
			[RecordMapper::POST, self::NOW - 7 * 86400, 40],
		]);

		$this->assertSame(['day' => 7, 'week' => 40], $this->service()->figures()['posts']);
	}

	public function testTheRepositoriesAreSummedFromTheHeads(): void {
		$repos = $this->createMock(AtprotoRepoRequest::class);
		$repos->method('countHeads')->willReturn(10);
		$repos->method('sumHeads')->willReturn(['records' => 5000, 'blob_bytes' => 123456789]);
		$this->repos = $repos;

		$this->assertSame(
			['count' => 10, 'records' => 5000, 'blob_bytes' => 123456789],
			$this->service()->figures()['repositories'],
		);
	}

	public function testTheWatchedAccountsAreCounted(): void {
		$this->watches->method('count')->willReturn(31);

		$reader = $this->service()->figures()['reader'];

		$this->assertSame(31, $reader['watched']);
		$this->assertFalse($reader['jetstream']);
		$this->assertNull($reader['jetstream_lag'], 'no Jetstream endpoint, no lag');
	}

	/** The cursor is the last event's time in microseconds. */
	public function testTheJetstreamLagIsHowFarBehindTheStoredCursorIs(): void {
		$this->jetstreamAt('wss://jetstream.example');
		$this->jetstream->method('cursor')->willReturn((self::NOW - 90) * 1000000 + 123456);

		$reader = $this->service()->figures()['reader'];

		$this->assertTrue($reader['jetstream']);
		$this->assertSame(90, $reader['jetstream_lag']);
	}

	public function testAJetstreamWithoutAStoredCursorHasNoLag(): void {
		$this->jetstreamAt('wss://jetstream.example');
		$this->jetstream->method('cursor')->willReturn(0);

		$this->assertNull($this->service()->figures()['reader']['jetstream_lag']);
	}

	/**
	 * The subscribers live in the daemon's process; the web reads the count
	 * it reports, and the database says the rest.
	 */
	public function testTheFirehoseIsTheDaemonsReportAndTheEventTable(): void {
		$this->daemon->method('status')->willReturn(['running' => true, 'pid' => 4, 'started' => 1, 'seen' => self::NOW, 'head' => 77, 'subscribers' => 3]);
		$this->events->method('latestSeq')->willReturn(77);
		$this->events->method('latestTime')->willReturn(self::NOW - 5);
		$this->events->method('countInWindow')->willReturn(1500);

		$this->assertSame(
			['running' => true, 'subscribers' => 3, 'head_seq' => 77, 'head_time' => self::NOW - 5, 'events_kept' => 1500],
			$this->service()->figures()['firehose'],
		);
	}

	public function testAStoppedDaemonHasNoSubscriberCount(): void {
		$this->daemon->method('status')->willReturn(['running' => false, 'pid' => 4, 'started' => 1, 'seen' => 1, 'head' => 77, 'subscribers' => 3]);

		$firehose = $this->service()->figures()['firehose'];

		$this->assertFalse($firehose['running']);
		$this->assertNull($firehose['subscribers']);
	}

	public function testANeverStartedDaemonHasNoSubscriberCount(): void {
		$this->daemon->method('status')->willReturn(null);

		$this->assertNull($this->service()->figures()['firehose']['subscribers']);
	}

	/** Switched off, the page says so instead of drawing zeros. */
	public function testNothingIsCountedWhileBlueskyIsOff(): void {
		$this->disabledConfig();
		$this->identities->expects($this->never())->method('count');
		$this->repos->expects($this->never())->method('countRecordsWrittenSince');
		$this->cache->expects($this->never())->method('get');

		$this->assertSame(['enabled' => false], $this->service()->figures());
	}

	public function testTheAnswerIsCachedForAMinute(): void {
		$this->identities->method('count')->willReturn(12);
		$this->cache->expects($this->once())->method('set')
			->with(AtprotoFiguresService::CACHE_NAMESPACE, 'figures', $this->callback(
				static fn (array $figures): bool => $figures['enabled'] === true && $figures['accounts']['identities'] === 12 && $figures['computed'] === self::NOW
			), 60);

		$this->service()->figures();
	}

	public function testACachedAnswerIsNotCountedAgain(): void {
		$cached = ['enabled' => true, 'computed' => self::NOW - 30, 'accounts' => ['identities' => 4, 'off' => 0]];
		$this->cache->method('get')->willReturn($cached);
		$this->identities->expects($this->never())->method('count');
		$this->repos->expects($this->never())->method('countRecordsWrittenSince');

		$this->assertSame($cached, $this->service()->figures());
	}

	/**
	 * Administrators only, by omission: the route and its controller carry
	 * none of the attributes that relax the default, so the server refuses
	 * everybody else before the method runs.
	 */
	public function testTheRouteIsForAdministratorsOnly(): void {
		$method = new \ReflectionMethod(AtprotoAdminController::class, 'figures');
		$class = new \ReflectionClass(AtprotoAdminController::class);

		foreach ([AuthorizedAdminSetting::class, NoAdminRequired::class, PublicPage::class, NoCSRFRequired::class] as $attribute) {
			$this->assertSame([], $method->getAttributes($attribute), $attribute . ' on the route');
			$this->assertSame([], $class->getAttributes($attribute), $attribute . ' on the controller');
		}
		$route = $method->getAttributes(FrontpageRoute::class)[0]->getArguments();
		$this->assertSame(['verb' => 'GET', 'url' => '/admin/bluesky/figures'], $route);
	}
}
