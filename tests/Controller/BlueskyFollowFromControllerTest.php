<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Atproto\Move\FollowFrom;
use OCA\Social\Controller\BlueskyFollowFromController;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ImportJob;
use OCA\Social\Service\ImportQueueService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyFollowFromControllerTest extends TestCase {
	private FollowFrom&MockObject $followFrom;
	private ImportQueueService&MockObject $imports;

	protected function setUp(): void {
		$this->followFrom = $this->createMock(FollowFrom::class);
		$this->imports = $this->createMock(ImportQueueService::class);
	}

	private function controller(?string $userId = 'bob'): BlueskyFollowFromController {
		return new BlueskyFollowFromController($this->createStub(IRequest::class), $userId, $this->followFrom, $this->imports, new NullLogger());
	}

	public function testTheAccountIsFoundNowAndItsFollowsQueued(): void {
		$source = ['did' => 'did:plc:alice', 'pds' => 'https://pds.example.com', 'handle' => 'alice.bsky.social'];
		$this->followFrom->expects($this->once())->method('resolve')->with('alice.bsky.social')->willReturn($source);
		$job = (new ImportJob())->setId(9)->setKind(ImportJob::KIND_BLUESKY_FOLLOWS);
		$this->imports->expects($this->once())->method('queue')->with('bob', ImportJob::KIND_BLUESKY_FOLLOWS, null, $source)->willReturn($job);

		$response = $this->controller()->followFrom('alice.bsky.social');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame($job, $response->getData()['import']);
	}

	public function testWhatCannotBeFoundOrIsRunningIsCoded(): void {
		$this->followFrom->method('resolve')->willThrowException(new MoveErrorException('No Bluesky account goes by x', 'handle-not-found'));
		$this->assertSame('handle-not-found', $this->controller()->followFrom('x')->getData()['code']);

		$this->imports->method('hasActive')->willReturn(true);
		$response = $this->controller()->followFrom('x');
		$this->assertSame([Http::STATUS_UNPROCESSABLE_ENTITY, 'import-in-progress'], [$response->getStatus(), $response->getData()['code']]);
	}

	/** What is not one of the app's own errors is answered with a fixed sentence: its message is the server's. */
	public function testAnUnexpectedErrorSaysNothingOfTheServer(): void {
		$this->followFrom->method('resolve')->willReturn(['did' => 'did:plc:alice', 'pds' => 'https://pds.example.com', 'handle' => 'alice.bsky.social']);
		$this->imports->method('queue')->willThrowException(new \RuntimeException('SQLSTATE[HY000]: disk full at /var/lib/mysql'));

		$response = $this->controller()->followFrom('alice.bsky.social');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Something went wrong on this server', 'code' => 'server-error'], $response->getData());
	}

	public function testItIsRateLimitedPerUser(): void {
		$this->assertCount(1, (new \ReflectionMethod(BlueskyFollowFromController::class, 'followFrom'))->getAttributes(UserRateLimit::class));
	}
}
