<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\RecommendationsController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\RecommendationCandidateService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Authentication and ownership boundaries for the additive v4 API. */
#[AllowMockObjectsWithoutExpectations]
class RecommendationsControllerTest extends TestCase {
	private RecommendationPreferenceService $service;
	private RecommendationCandidateService $candidateService;
	private bool $enabled = true;

	private function controller(): RecommendationsController {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('passesCSRFCheck')->willReturn(true);
		\OC::$server->register(IRequest::class, $request);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setId('https://cloud.example/users/alice')->setUserId('alice'));

		return new RecommendationsController(
			$request,
			$session,
			new NullLogger(),
			$accounts,
			$this->createStub(ClientService::class),
			$this->service,
			$this->candidateService,
		);
	}

	protected function setUp(): void {
		$this->service = $this->createMock(RecommendationPreferenceService::class);
		$this->service->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$this->candidateService = $this->createMock(RecommendationCandidateService::class);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
		parent::tearDown();
	}

	public function testPreferencesAlwaysUseTheAuthenticatedOwner(): void {
		$state = ['revision' => 2, 'generation' => 1, 'topics' => []];
		$this->service->expects($this->once())->method('get')->with('alice')->willReturn($state);

		$response = $this->controller()->preferences();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($state, $response->getData());
	}

	public function testPreferenceWriteCannotChooseAnotherOwner(): void {
		$this->service->expects($this->once())->method('replace')->with(
			'alice', 3, [['topic_id' => 'tech.apple', 'mode' => 'INTERESTED']]
		)->willReturn(['revision' => 4, 'generation' => 4, 'topics' => []]);

		$response = $this->controller()->savePreferences(3, [['topic_id' => 'tech.apple', 'mode' => 'INTERESTED']]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testStaleWriteReturnsConflictWithTheCurrentOwnerState(): void {
		$current = ['revision' => 5, 'generation' => 5, 'topics' => []];
		$this->service->expects($this->once())->method('replace')->with('alice', 2, [])->willReturn(null);
		$this->service->expects($this->once())->method('get')->with('alice')->willReturn($current);

		$response = $this->controller()->savePreferences(2, []);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'revision-conflict', 'current' => $current], $response->getData());
	}

	public function testTheInstanceKillSwitchDisablesV4BeforePreferenceAccess(): void {
		$this->enabled = false;
		$this->service->expects($this->never())->method('get');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->preferences()->getStatus());
	}

	public function testCandidateDiscoveryUsesTheAuthenticatedReaderAndRequestedTopic(): void {
		$result = ['topic_id' => 'tech.apple', 'count' => 0, 'requested' => 10, 'underfilled' => true,
			'remote_refresh_queued' => false, 'sources' => ['activitypub' => 0, 'atproto' => 0], 'candidates' => []];
		$this->candidateService->expects($this->once())->method('discover')->with(
			$this->callback(static fn (Person $viewer): bool => $viewer->getUserId() === 'alice'), 'tech.apple', 10
		)->willReturn($result);

		$response = $this->controller()->candidates('tech.apple', 10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}
}
