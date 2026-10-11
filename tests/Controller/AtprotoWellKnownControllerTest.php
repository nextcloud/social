<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Controller\AtprotoWellKnownController;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `/.well-known/atproto-did` (§4.2): the https way a handle names its DID.
 * The handle is the host the request came to, port dropped and case
 * folded; only an active identity this app holds answers, as plain text a
 * verifier compares byte for byte, and everything else is a 404, so the
 * document never vouches for a handle that is not one of ours.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoWellKnownControllerTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private bool $enabled = true;
	private string $host = 'alice.social.example.com';
	private string $state = Identity::STATE_ACTIVE;

	protected function setUp(): void {
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new \DateTime('@1760000000'));
		\OC::$server->register(ITimeFactory::class, $clock);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function controller(): AtprotoWellKnownController {
		$request = $this->createMock(IRequest::class);
		$request->method('getInsecureServerHost')->willReturnCallback(fn (): string => $this->host);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByHandle')->willReturnCallback(fn (string $handle): Identity => $handle === 'alice.social.example.com'
			? new Identity(1, 'https://social.example.com/users/alice', self::DID, $handle, '', 'did:key:z', '', $this->state, '', 0)
			: throw new AtprotoIdentityNotFoundException());
		$identities->method('instanceDocument')->willReturn(['id' => 'did:web:social.example.com']);

		return new AtprotoWellKnownController($request, $config, $identities);
	}

	public static function hosts(): array {
		return [
			'the handle host' => ['alice.social.example.com'],
			'with a port' => ['alice.social.example.com:443'],
			'in capitals' => ['Alice.Social.Example.COM'],
		];
	}

	#[DataProvider('hosts')]
	public function testTheHandleHostAnswersItsDidAsPlainText(string $host): void {
		$this->host = $host;
		$response = $this->controller()->atprotoDid();

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::DID, $response->render(), 'nothing around it: a verifier compares it as it is');
		$this->assertStringStartsWith('text/plain', $response->getHeaders()['Content-Type']);
		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);
	}

	public function testAProxyThatLosesTheHostNamesTheHandle(): void {
		$this->host = 'social.example.com';

		$this->assertSame(self::DID, $this->controller()->atprotoDid('alice.social.example.com')->render());
	}

	public static function refused(): array {
		return [
			'the instance host itself' => ['social.example.com', Identity::STATE_ACTIVE, true],
			'a handle not held here' => ['mallory.social.example.com', Identity::STATE_ACTIVE, true],
			'not a handle' => ['127.0.0.1', Identity::STATE_ACTIVE, true],
			'a deactivated account' => ['alice.social.example.com', Identity::STATE_DEACTIVATED, true],
			'an account that moved away' => ['alice.social.example.com', Identity::STATE_MOVED_AWAY, true],
			'Bluesky off' => ['alice.social.example.com', Identity::STATE_ACTIVE, false],
		];
	}

	#[DataProvider('refused')]
	public function testAnythingElseIsNotFound(string $host, string $state, bool $enabled): void {
		$this->host = $host;
		$this->state = $state;
		$this->enabled = $enabled;
		$response = $this->controller()->atprotoDid();

		$this->assertSame(404, $response->getStatus());
		$this->assertStringNotContainsString('did:', (string)$response->render());
	}

	public function testTheInstanceDocumentIsServedOnlyWithBlueskyOn(): void {
		$this->assertSame(['id' => 'did:web:social.example.com'], $this->controller()->didJson()->getData());
		$this->enabled = false;
		$this->assertSame(404, $this->controller()->didJson()->getStatus());
	}
}
