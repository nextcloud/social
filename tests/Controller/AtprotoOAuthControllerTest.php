<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Controller\AtprotoOAuthController;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The HTTP side of OAuth for Bluesky apps that is this app's own: the
 * pushed authorization request answers 201 with a fresh DPoP nonce, an
 * OAuth error as RFC 6749 shapes it with its own headers kept and never
 * cached, an internal failure without its details; every answer carries
 * the CORS headers a browser app needs, and with Bluesky off there is
 * nothing to sign in to.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoOAuthControllerTest extends TestCase {
	private bool $enabled = true;
	/** @var array|\Throwable what the authorization server makes of the request */
	private mixed $outcome = ['request_uri' => 'urn:ietf:params:oauth:request_uri:req-1', 'expires_in' => 300];
	/** @var list<array{0: array, 1: string}> */
	private array $pushed = [];

	protected function setUp(): void {
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function controller(): AtprotoOAuthController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['client_id' => 'https://app.example.com/client.json']);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'DPoP' ? 'the.proof.jws' : '');
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$server = $this->createMock(AuthorizationServer::class);
		$server->method('par')->willReturnCallback(function (array $params, string $proof): array {
			$this->pushed[] = [$params, $proof];
			if ($this->outcome instanceof \Throwable) {
				throw $this->outcome;
			}

			return $this->outcome;
		});
		$server->method('resourceMetadata')->willReturn(['resource' => 'https://social.example.com', 'authorization_servers' => ['https://social.example.com']]);
		$nonce = $this->createMock(DpopNonce::class);
		$nonce->method('current')->willReturn('fresh-nonce');

		return new AtprotoOAuthController($request, $config, $server, $nonce, new NullLogger());
	}

	public function testAPushedRequestIsAcceptedWithAFreshNonce(): void {
		$response = $this->controller()->par();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('urn:ietf:params:oauth:request_uri:req-1', $response->getData()['request_uri']);
		$this->assertSame('fresh-nonce', $response->getHeaders()['DPoP-Nonce']);
		$this->assertSame([[['client_id' => 'https://app.example.com/client.json'], 'the.proof.jws']], $this->pushed, 'the proof from the DPoP header');
		$this->assertCors($response->getHeaders());
	}

	public function testAnOAuthErrorKeepsItsOwnNonceAndIsNotCached(): void {
		$this->outcome = new OAuthException('use_dpop_nonce', 'Use the DPoP nonce this server provides', 400, ['DPoP-Nonce' => 'the-nonce-to-use']);
		$response = $this->controller()->par();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'use_dpop_nonce', 'error_description' => 'Use the DPoP nonce this server provides'], $response->getData());
		$this->assertSame('the-nonce-to-use', $response->getHeaders()['DPoP-Nonce']);
		$this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
	}

	public function testAnInternalFailureSaysNothingOfItself(): void {
		$this->outcome = new RuntimeException('SQLSTATE[HY000]: the database at 10.0.0.5 is down');
		$response = $this->controller()->par();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'server_error', 'error_description' => 'Internal server error'], $response->getData());
	}

	public function testWithBlueskyOffThereIsNothingToSignInTo(): void {
		$this->enabled = false;
		$controller = $this->controller();

		$this->assertSame(404, $controller->par()->getStatus());
		$this->assertSame(404, $controller->protectedResource()->getStatus());
		$this->assertSame([], $this->pushed);
	}

	public function testTheResourceMetadataAndThePreflightsCarryCors(): void {
		$controller = $this->controller();
		$metadata = $controller->protectedResource();

		$this->assertSame(['https://social.example.com'], $metadata->getData()['authorization_servers']);
		$this->assertCors($metadata->getHeaders());
		foreach ([$controller->parPreflight(), $controller->tokenPreflight(), $controller->revokePreflight()] as $preflight) {
			$this->assertSame(204, $preflight->getStatus());
			$this->assertCors($preflight->getHeaders());
		}
	}

	private function assertCors(array $headers): void {
		$this->assertSame('*', $headers['Access-Control-Allow-Origin']);
		$this->assertStringContainsString('DPoP', $headers['Access-Control-Allow-Headers']);
		$this->assertStringContainsString('DPoP-Nonce', $headers['Access-Control-Expose-Headers']);
	}
}
