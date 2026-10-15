<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\StatisticsController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\NetworkGrowthService;
use OCA\Social\Service\NetworkStatsService;
use OCA\Social\Service\StatisticsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `/api/v1/statistics` and its export: the reader's own numbers.
 *
 * Client-API routes asking a token for `read:statuses`, reached by the
 * Statistics page with its session and CSRF token. Whoever calls, the figures
 * are counted for the caller — there is no account to name.
 *
 * The file itself is built by `DataDownloadResponse`, which needs a class only
 * the server has; `Integration/Api/SignInTokenRoutesTest` downloads it.
 */
#[AllowMockObjectsWithoutExpectations]
class StatisticsControllerTest extends TestCase {
	use ClientApiCallers;

	private StatisticsService&MockObject $statistics;

	protected function setUp(): void {
		$this->setUpCallers();
		$this->statistics = $this->createMock(StatisticsService::class);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/** @return array<string, array{callable(StatisticsController): Response}> */
	public static function routes(): array {
		return [
			'the figures' => [static fn (StatisticsController $c): Response => $c->statistics(30)],
			'the file' => [static fn (StatisticsController $c): Response => $c->export(30)],
		];
	}

	#[DataProvider('routes')]
	public function testWithoutAUserNothingIsCounted(callable $call): void {
		$this->statistics->expects($this->never())->method($this->anything());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $call($this->controller())->getStatus());
	}

	/** Profile and follow scopes are not the posts the figures are counted from. */
	#[DataProvider('routes')]
	public function testATokenThatMayNotReadPostsIsRefused(callable $call): void {
		$this->asToken(['read:accounts', 'read:follows', 'write']);
		$this->statistics->expects($this->never())->method($this->anything());

		$this->assertSame(Http::STATUS_FORBIDDEN, $call($this->controller())->getStatus());
	}

	public function testATokenThatReadsPostsIsAnsweredForItsOwnAccount(): void {
		$this->asToken(['read:statuses'], 'bob');
		$this->statistics->expects($this->once())->method('cachedForAccount')
			->with($this->callback(static fn (Person $actor): bool => $actor->getUserId() === 'bob'), 30)
			->willReturn(['account' => ['acct' => 'bob']]);

		$response = $this->controller()->statistics(30);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('bob', $response->getData()['account']['acct']);
	}

	public function testTheStatisticsPageIsAnsweredThroughItsSession(): void {
		$this->asSession('alice');
		$this->statistics->expects($this->once())->method('cachedForAccount')
			->with($this->callback(static fn (Person $actor): bool => $actor->getUserId() === 'alice'), 30)
			->willReturn(['account' => ['acct' => 'alice']]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->statistics(30)->getStatus());
	}

	/** Where the followers and the answers are, and the networks the posts also went out on. */
	public function testTheFileCarriesTheServersAndNetworks(): void {
		$csv = (new \ReflectionMethod(StatisticsController::class, 'asCsv'))->invoke($this->controller(), [
			'account' => ['acct' => 'alice'],
			'engagement' => ['quotes' => 2],
			'audience' => ['instances' => [['host' => 'bsky.social', 'count' => 3]], 'starter_pack_joins' => 1],
			'engagement_sources' => ['servers' => [['host' => 'remote.example', 'count' => 4]]],
			'reach' => ['networks' => [['network' => 'Bluesky', 'posts' => 5]]],
		]);

		$this->assertStringContainsString("engagement,quotes,2\n", $csv);
		$this->assertStringContainsString('"followers by server",bsky.social,3' . "\n", $csv);
		$this->assertStringContainsString('audience,"starter pack joins",1' . "\n", $csv);
		$this->assertStringContainsString('"engagement by server",remote.example,4' . "\n", $csv);
		$this->assertStringContainsString('"posts also on",Bluesky,5' . "\n", $csv);
	}

	private function controller(): StatisticsController {
		return new StatisticsController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->statistics,
			$this->createStub(NetworkStatsService::class),
			$this->createStub(NetworkGrowthService::class),
		);
	}
}
