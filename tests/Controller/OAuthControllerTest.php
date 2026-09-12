<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\OAuthController;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Instance;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class OAuthControllerTest extends TestCase {
	private const OOB = 'urn:ietf:wg:oauth:2.0:oob';

	/** @var IUserSession&MockObject */
	private $userSession;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var InstanceService&MockObject */
	private $instanceService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var ClientService&MockObject */
	private $clientService;
	/** @var ConfigService&MockObject */
	private $configService;
	/** @var IInitialState&MockObject */
	private $initialState;
	private OAuthController $controller;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->instanceService = $this->createMock(InstanceService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->clientService = $this->createMock(ClientService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->initialState = $this->createMock(IInitialState::class);

		$this->controller = new OAuthController(
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->urlGenerator,
			$this->instanceService,
			$this->accountService,
			$this->clientService,
			$this->configService,
			new NullLogger(),
			$this->initialState
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function loggedIn(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$actor = $this->createMock(Person::class);
		$actor->method('getPreferredUsername')->willReturn($uid);
		$this->accountService->method('getActorFromUserId')->with($uid)->willReturn($actor);
	}

	private function knownClient(string $clientId = 'client-1', string $appName = 'Tusky'): SocialClient {
		$client = new SocialClient();
		$client->setAppClientId($clientId)->setAppName($appName);
		$this->clientService->method('getFromClientId')->with($clientId)->willReturn($client);

		return $client;
	}

	// nodeinfo2()

	public function testNodeinfo2DescribesTheLocalInstance(): void {
		$instance = new Instance();
		$instance->setTitle('My Social')->setVersion('0.10.1')->setUsage(['users' => ['total' => 3]])->setRegistrations(true);
		$this->instanceService->method('getLocal')->willReturn($instance);
		$response = $this->controller->nodeinfo2();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'version' => '2.0',
			// the schema wants ^[a-z0-9-]+$ here, and crawlers group instances
			// by it; the human title goes to metadata.nodeName
			'software' => ['name' => 'nextcloud-social', 'version' => '0.10.1'],
			'protocols' => ['activitypub'],
			'services' => ['inbound' => [], 'outbound' => []],
			// the schema names every top-level key and allows no other, so the
			// old `rootUrl` is gone
			'usage' => ['users' => ['total' => 3]],
			'openRegistrations' => true,
			'metadata' => ['nodeName' => 'My Social', 'nodeDescription' => ''],
		], $response->getData());
	}

	public function testNodeinfo21AddsTheRepositoryAndHomepage(): void {
		$instance = new Instance();
		$instance->setTitle('My Social')->setShortDescription('A cosy corner')->setVersion('0.10.1');
		$this->instanceService->method('getLocal')->willReturn($instance);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/social/');

		$data = $this->controller->nodeinfo21()->getData();

		$this->assertSame('2.1', $data['version']);
		$this->assertSame([
			'name' => 'nextcloud-social',
			'version' => '0.10.1',
			'repository' => 'https://github.com/nextcloud/social',
			'homepage' => 'https://github.com/nextcloud/social',
		], $data['software']);
		$this->assertSame(['nodeName' => 'My Social', 'nodeDescription' => 'A cosy corner'], $data['metadata']);
		$this->assertSame(['inbound' => [], 'outbound' => []], $data['services']);
	}

	public function testNodeinfo2FallsBackToAppDefaultsWithoutAnInstance(): void {
		$this->instanceService->method('getLocal')->willThrowException(new InstanceDoesNotExistException());
		$this->configService->method('getAppValue')->with('installed_version')->willReturn('0.10.1');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/social/');

		$data = $this->controller->nodeinfo2()->getData();

		$this->assertSame(['name' => 'nextcloud-social', 'version' => '0.10.1'], $data['software']);
		$this->assertSame('Nextcloud Social', $data['metadata']['nodeName']);
		$this->assertSame([], $data['usage']);
		$this->assertFalse($data['openRegistrations']);
	}

	// apps()

	public function testAppsRegistersAClientAndReturnsItsCredentials(): void {
		$this->clientService->expects($this->once())->method('createApp')
			->willReturnCallback(function (SocialClient $client): void {
				$this->assertSame('Tusky', $client->getAppName());
				$this->assertSame('https://tusky.app', $client->getAppWebsite());
				$this->assertSame(['https://tusky.app/callback'], $client->getAppRedirectUris());
				$this->assertSame(['read', 'write'], $client->getAppScopes());
				$client->setId(7)->setAppClientId('cid')->setAppClientSecret('csecret');
			});

		$response = $this->controller->apps('Tusky', 'https://tusky.app/callback', 'https://tusky.app', 'read write');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'id' => 7,
			'name' => 'Tusky',
			'website' => 'https://tusky.app',
			'scopes' => 'read write',
			'client_id' => 'cid',
			'client_secret' => 'csecret',
			// Mastodon's Application entity always carries both, and a client
			// that decodes this into a typed struct fails without them
			'redirect_uri' => 'https://tusky.app/callback',
			'vapid_key' => '',
		], $response->getData());
	}

	/**
	 * Mastodon's API takes several redirect URIs newline-separated in one
	 * field. They used to be wrapped into a single entry, which
	 * `ClientService::confirmData()` then compared a lone URI against — so a
	 * client registered with more than one could never authorize at all.
	 */
	public function testAppsSplitsNewlineSeparatedRedirectUris(): void {
		$this->clientService->expects($this->once())->method('createApp')
			->with($this->callback(
				fn (SocialClient $c): bool
					=> $c->getAppRedirectUris() === ['https://a/cb', 'https://b/cb']
			));

		$this->controller->apps('App', "https://a/cb\nhttps://b/cb\n");
	}

	public function testAppsIgnoresBlankAndDuplicateRedirectUris(): void {
		$this->clientService->expects($this->once())->method('createApp')
			->with($this->callback(
				fn (SocialClient $c): bool => $c->getAppRedirectUris() === ['https://a/cb']
			));

		$this->controller->apps('App', "https://a/cb\n\n  https://a/cb  \n");
	}

	public function testAppsAcceptsAListOfRedirectUris(): void {
		$this->clientService->expects($this->once())->method('createApp')
			->with($this->callback(fn (SocialClient $c): bool => $c->getAppRedirectUris() === ['https://a/cb', 'https://b/cb']));

		$this->controller->apps('App', ['https://a/cb', 'https://b/cb']);
	}

	public function testAppsDefaultsToReadScope(): void {
		$this->clientService->method('createApp');

		$this->assertSame('read', $this->controller->apps('App', 'https://a/cb')->getData()['scopes']);
	}

	// authorize()

	public function testAuthorizeRendersTheConsentPageForAKnownClient(): void {
		$this->loggedIn();
		$this->knownClient();
		$this->initialState->expects($this->once())->method('provideInitialState')->with('appName', 'Tusky');

		$response = $this->controller->authorize('client-1', self::OOB, 'code', 'read write');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('oauth2', $response->getTemplateName());
		$this->assertSame([
			'request' => [
				'clientId' => 'client-1',
				'redirectUri' => self::OOB,
				'responseType' => 'code',
				'scope' => 'read write',
				'state' => '',
			],
		], $response->getParams());
	}

	public function testAuthorizeCarriesTheClientsStateToTheConsentPage(): void {
		$this->loggedIn();
		$this->knownClient();

		$response = $this->controller->authorize('client-1', self::OOB, 'code', 'read', 'xyz789');

		$this->assertSame('xyz789', $response->getParams()['request']['state']);
	}

	/**
	 * A refused consent request is answered, not thrown: an exception out of
	 * this route reached the browser as a Nextcloud HTML error page — with a
	 * stack trace where debug is on — instead of an error the client can read.
	 */
	public function testAuthorizeRejectsNonCodeResponseTypes(): void {
		$this->loggedIn();
		$this->clientService->expects($this->never())->method('getFromClientId');

		$response = $this->controller->authorize('client-1', self::OOB, 'token');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid response type'], $response->getData());
	}

	public function testAuthorizeRejectsUnknownClients(): void {
		$this->loggedIn();
		$this->clientService->method('getFromClientId')->willThrowException(new ClientNotFoundException('unknown'));

		$response = $this->controller->authorize('nope', self::OOB, 'code');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unknown'], $response->getData());
	}

	public function testAuthorizeRejectsARedirectUriTheClientDidNotRegister(): void {
		// The consent GET now confirms the redirect_uri against the client's registered
		// URIs before rendering, so a code can never be steered to a forged link. A
		// rejected redirect_uri is refused before the consent page is prepared.
		$this->loggedIn();
		$client = $this->knownClient();
		$this->clientService->expects($this->once())->method('confirmData')
			->with($client, $this->callback(fn (array $data): bool => $data['redirect_uri'] === 'https://evil.example/steal'))
			->willThrowException(new ClientException('unknown redirect_uri'));
		$this->initialState->expects($this->never())->method('provideInitialState');

		$response = $this->controller->authorize('client-1', 'https://evil.example/steal', 'code', 'read');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unknown redirect_uri'], $response->getData());
	}

	// authorizing()

	public function testAuthorizingIssuesACodeBoundToTheUser(): void {
		$this->loggedIn('alice');
		$client = $this->knownClient();
		$this->clientService->expects($this->once())->method('confirmData')
			->with($client, $this->callback(fn (array $data): bool => $data['app_scopes'] === 'read write'));
		$this->clientService->expects($this->once())->method('authClient')
			->willReturnCallback(function (SocialClient $c): void {
				$this->assertSame(['read', 'write'], $c->getAuthScopes());
				$this->assertSame('alice', $c->getAuthAccount());
				$this->assertSame('alice', $c->getAuthUserId());
				$c->setAuthCode('auth-code-1');
			});

		$response = $this->controller->authorizing('client-1', self::OOB, 'code', 'read write');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['code' => 'auth-code-1'], $response->getData());
	}

	public function testAuthorizingEchoesTheStateOnTheOutOfBandResponse(): void {
		$this->loggedIn('alice');
		$this->knownClient();
		$this->clientService->method('authClient')
			->willReturnCallback(static fn (SocialClient $c) => $c->setAuthCode('auth-code-1'));

		$response = $this->controller->authorizing('client-1', self::OOB, 'code', 'read', 'xyz789');

		$this->assertSame(['code' => 'auth-code-1', 'state' => 'xyz789'], $response->getData());
	}

	/**
	 * `state` is what a web client compares against what it stored, to know the
	 * redirect answers its own request. It used to be dropped, so a client
	 * following the spec rejected the redirect and one that did not was open to
	 * having somebody else's code injected.
	 */
	public function testAuthorizingRedirectsWithTheCodeAndTheState(): void {
		$this->loggedIn('alice');
		$this->knownClient();
		$this->clientService->method('authClient')
			->willReturnCallback(static fn (SocialClient $c) => $c->setAuthCode('auth-code-1'));

		$response = $this->controller->authorizing(
			'client-1', 'https://elk.example/oauth/callback', 'code', 'read', 'xyz789'
		);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(
			'https://elk.example/oauth/callback?code=auth-code-1&state=xyz789',
			$response->getRedirectURL()
		);
	}

	public function testARedirectUriThatAlreadyHasAQueryStringStaysValid(): void {
		$this->loggedIn('alice');
		$this->knownClient();
		$this->clientService->method('authClient')
			->willReturnCallback(static fn (SocialClient $c) => $c->setAuthCode('c1'));

		$response = $this->controller->authorizing(
			'client-1', 'https://elk.example/cb?instance=cloud.example', 'code', 'read', 's1'
		);

		// concatenating '?code=' produced a URL with two question marks in it,
		// and the client could not read the code out of it
		$this->assertSame(
			'https://elk.example/cb?instance=cloud.example&code=c1&state=s1',
			$response->getRedirectURL()
		);
	}

	public function testAFragmentOnTheRedirectUriStaysAtTheEnd(): void {
		$this->loggedIn('alice');
		$this->knownClient();
		$this->clientService->method('authClient')
			->willReturnCallback(static fn (SocialClient $c) => $c->setAuthCode('c1'));

		$response = $this->controller->authorizing(
			'client-1', 'https://elk.example/cb#/done', 'code', 'read'
		);

		$this->assertSame('https://elk.example/cb?code=c1#/done', $response->getRedirectURL());
	}

	public function testAuthorizingRejectsNonCodeResponseTypes(): void {
		$this->loggedIn();
		$this->clientService->expects($this->never())->method('authClient');

		$response = $this->controller->authorizing('client-1', self::OOB, 'token');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid response type'], $response->getData());
	}

	public function testAuthorizingRejectsUnknownClients(): void {
		$this->loggedIn();
		$this->clientService->method('getFromClientId')->willThrowException(new ClientNotFoundException('unknown client'));

		$response = $this->controller->authorizing('nope', self::OOB, 'code');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unknown client'], $response->getData());
	}

	public function testAuthorizingRejectsMismatchingClientData(): void {
		$this->loggedIn();
		$this->knownClient();
		$this->clientService->method('confirmData')->willThrowException(new ClientException('wrong scopes'));
		$this->clientService->expects($this->never())->method('authClient');

		$response = $this->controller->authorizing('client-1', self::OOB, 'code', 'admin');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'wrong scopes'], $response->getData());
	}

	// token()

	public function testTokenExchangesAnAuthorizationCodeForABearerToken(): void {
		$client = $this->knownClient();
		$client->setAuthScopes(['read']);
		$client->setCreation(1700000000);
		$confirmations = [];
		$this->clientService->method('confirmData')->willReturnCallback(function (SocialClient $c, array $data) use (&$confirmations): void {
			$confirmations[] = $data;
		});
		$this->clientService->expects($this->once())->method('generateToken')
			->willReturnCallback(fn (SocialClient $c) => $c->setToken('bearer-token'));

		$response = $this->controller->token('client-1', 'secret', self::OOB, 'authorization_code', 'read', 'auth-code-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'access_token' => 'bearer-token',
			'token_type' => 'Bearer',
			'scope' => 'read',
			'created_at' => 1700000000,
		], $response->getData());
		$this->assertSame([
			['client_secret' => 'secret', 'redirect_uri' => self::OOB, 'auth_scopes' => 'read'],
			['code' => 'auth-code-1'],
		], $confirmations);
	}

	/**
	 * The scope the token really carries, not the one the token call asked
	 * for. Tusky omits `scope` on the token call, which defaults to `read`
	 * here — so a client that had been granted write was told it only had
	 * read, and hid its compose button while writes in fact worked.
	 */
	public function testTokenAnswersWithTheGrantedScopesNotTheRequestedOnes(): void {
		$client = $this->knownClient();
		$client->setAuthScopes(['read', 'write', 'follow']);
		$client->setCreation(1700000000);
		$this->clientService->method('confirmData');
		$this->clientService->method('generateToken')
			->willReturnCallback(fn (SocialClient $c) => $c->setToken('bearer-token'));

		$response = $this->controller->token('client-1', 'secret', self::OOB, 'authorization_code', 'read', 'auth-code-1');

		$this->assertSame('read write follow', $response->getData()['scope']);
	}

	public function testTokenThrottlesAWrongClientSecret(): void {
		$this->knownClient();
		$this->clientService->method('confirmData')
			->willThrowException(new ClientException('wrong client_secret'));

		$response = $this->controller->token('client-1', 'guess', self::OOB, 'authorization_code', 'read', 'c');

		// A credential guess is throttled so /oauth/token cannot be brute-forced.
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testTokenThrottlesAnUnknownClient(): void {
		$this->clientService->method('getFromClientId')
			->willThrowException(new ClientNotFoundException('unknown'));

		$response = $this->controller->token('nope', 'secret', self::OOB, 'authorization_code', 'read', 'c');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public function testTokenRequiresACodeForTheAuthorizationCodeGrant(): void {
		$this->knownClient();
		$this->clientService->expects($this->never())->method('generateToken');

		$response = $this->controller->token('client-1', 'secret', self::OOB, 'authorization_code');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'missing code'], $response->getData());
	}

	public function testTokenRejectsUnknownGrantTypes(): void {
		$this->knownClient();

		$response = $this->controller->token('client-1', 'secret', self::OOB, 'password');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid value for grant_type'], $response->getData());
	}

	public function testTokenClientCredentialsGrantIsRefused(): void {
		$this->knownClient();
		$this->clientService->expects($this->never())->method('generateToken');

		$response = $this->controller->token('client-1', 'secret', self::OOB, 'client_credentials');

		// Falling through would have returned whatever token the client row held from
		// some user's authorization-code grant.
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unsupported_grant_type'], $response->getData());
	}

	public function testTokenRejectsUnknownClientIds(): void {
		$this->clientService->method('getFromClientId')->willThrowException(new ClientNotFoundException());

		$response = $this->controller->token('nope', 'secret', self::OOB, 'authorization_code', 'read', 'c');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'unknown client_id'], $response->getData());
	}

	public function testTokenRejectsAWrongClientSecret(): void {
		$this->knownClient();
		$this->clientService->method('confirmData')->willThrowException(new ClientException('wrong client_secret'));
		$this->clientService->expects($this->never())->method('generateToken');

		$response = $this->controller->token('client-1', 'wrong', self::OOB, 'authorization_code', 'read', 'c');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'wrong client_secret'], $response->getData());
	}

	// revoke()

	public function testRevokeClearsTheToken(): void {
		$client = $this->knownClient();
		$this->clientService->expects($this->once())
			->method('revokeToken')
			->with($this->identicalTo($client), 'tok');

		$response = $this->controller->revoke('client-1', 's3cret', 'tok');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testRevokeOfAnUnknownTokenIsStillASuccess(): void {
		$this->knownClient();
		$this->clientService->method('revokeToken')->willThrowException(new ClientNotFoundException());

		$this->assertSame(Http::STATUS_OK, $this->controller->revoke('client-1', 's3cret', 'gone')->getStatus());
	}

	public function testRevokeThrottlesWrongClientCredentials(): void {
		$this->knownClient();
		$this->clientService->method('confirmData')->willThrowException(new ClientException('wrong client_secret'));
		$this->clientService->expects($this->never())->method('revokeToken');

		$response = $this->controller->revoke('client-1', 'wrong', 'tok');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}
}
