<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\HostMetaException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CurlServiceTest extends TestCase {
	private const BOB = 'https://mastodon.example/users/bob';

	private ConfigService|MockObject $configService;
	private FediverseService|MockObject $fediverseService;
	private CurlService|MockObject $service;
	/** @var NCRequest[] every request handed to the (mocked) transport */
	/** TEST-NET-3, so the classifier sees a public address without asking DNS */
	private const PUBLIC_IP = '203.0.113.10';

	private array $requests = [];
	private IClientService|MockObject $clientService;
	private IClient|MockObject $client;
	private HttpSignatureService|MockObject $httpSignatureService;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key) => match ($key) {
				ConfigService::SOCIAL_MAX_SIZE => '10',
				'installed_version' => '0.9.1',
				default => '',
			});
		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($this->client);
		// by default this instance has no key to sign a fetch with, so requests
		// go out exactly as they did before signed fetches existed
		$this->httpSignatureService = $this->createMock(HttpSignatureService::class);
		$this->httpSignatureService->method('signFetch')->willReturn(false);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	/** CurlService with the network layer replaced: doRequest is answered by $responder(NCRequest): string. */
	private function serviceAnsweringWith(callable $responder, string $mocked = 'doRequest'): CurlService {
		$this->service = $this->getMockBuilder(CurlService::class)
			->setConstructorArgs([
				$this->configService, $this->fediverseService, $this->clientService,
				$this->httpSignatureService, new NullLogger()
			])
			->onlyMethods([$mocked])
			->getMock();
		$this->service->method($mocked)->willReturnCallback(function (NCRequest $request) use ($responder) {
			$this->requests[] = $request;

			return $responder($request);
		});

		return $this->service;
	}

	private function hostMetaJson(string $template = 'https://mastodon.example/.well-known/webfinger?resource={uri}'): string {
		return json_encode(['Link' => ['@attributes' => ['rel' => 'lrdd', 'template' => $template]]]);
	}

	private function jrd(string $subject = 'acct:bob@mastodon.example', ?string $self = self::BOB): string {
		$links = [['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => 'https://mastodon.example/@bob']];
		if ($self !== null) {
			$links[] = ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $self];
		}

		return json_encode(['subject' => $subject, 'links' => $links]);
	}

	public function testConstructorConvertsTheMaxSizeToBytes(): void {
		$service = new CurlService($this->configService, $this->fediverseService, $this->clientService,
			$this->httpSignatureService, new NullLogger());
		$property = new \ReflectionProperty(CurlService::class, 'maxDownloadSize');

		$this->assertSame(10 * 1048576, $property->getValue($service));
	}

	public function testAssignUserAgentIncludesTheInstalledVersion(): void {
		$service = new CurlService($this->configService, $this->fediverseService, $this->clientService,
			$this->httpSignatureService, new NullLogger());
		$request = new NCRequest('/users/bob');

		$service->assignUserAgent($request);

		$this->assertSame('Nextcloud Social 0.9.1', $request->getUserAgent());
	}

	public function testDoRequestChecksAuthorizationConfiguresAndTagsTheRequestBeforeSending(): void {
		$service = $this->serviceAnsweringWith(fn () => 'body', 'doRequestOrig');
		$request = new NCRequest('/users/bob');
		$request->setHost('mastodon.example');
		$this->fediverseService->expects($this->once())->method('authorized')->with('mastodon.example')->willReturn(true);
		$this->configService->expects($this->once())->method('configureRequest')->with($this->identicalTo($request));

		$this->assertSame('body', $service->doRequest($request));
		$this->assertSame('Nextcloud Social 0.9.1', $request->getUserAgent());
		$this->assertSame([$request], $this->requests);
	}

	public function testDoRequestNeverSendsToABlockedInstance(): void {
		$service = $this->serviceAnsweringWith(fn () => 'body', 'doRequestOrig');
		$this->fediverseService->method('authorized')->willThrowException(new UnauthorizedFediverseException());
		$this->configService->expects($this->never())->method('configureRequest');
		$request = new NCRequest('/users/bob');
		$request->setHost('blocked.example');

		try {
			$service->doRequest($request);
			$this->fail('expected UnauthorizedFediverseException');
		} catch (UnauthorizedFediverseException $e) {
			$this->assertSame([], $this->requests);
		}
	}

	public function testHostMetaRedirectsToTheWebfingerTemplate(): void {
		$service = $this->serviceAnsweringWith(fn () => $this->hostMetaJson('http://api.mastodon.example/wf?resource={uri}'));
		$host = 'mastodon.example';
		$protocols = ['https', 'http'];

		$path = $service->hostMeta($host, $protocols);

		$this->assertSame('/wf', $path);
		$this->assertSame('api.mastodon.example', $host);
		$this->assertSame(['http'], $protocols);
		$this->assertSame('/.well-known/host-meta', $this->requests[0]->getPath());
		$this->assertSame('mastodon.example', $this->requests[0]->getHost());
		$this->assertSame(['ignoreJsonHeaders' => true], $this->requests[0]->getClientOptions());
	}

	public function testHostMetaParsesTheXrdDocument(): void {
		$service = $this->serviceAnsweringWith(function (NCRequest $request) {
			$request->setContentType('application/xrd+xml; charset=utf-8');

			return '<?xml version="1.0" encoding="UTF-8"?><XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0">'
				. '<Link rel="lrdd" template="https://mastodon.example/.well-known/webfinger?resource={uri}"/></XRD>';
		});
		$host = 'mastodon.example';
		$protocols = ['https'];

		$this->assertSame('/.well-known/webfinger', $service->hostMeta($host, $protocols));
		$this->assertSame(['https'], $protocols);
	}

	public function testHostMetaFailsWhenTheDocumentHasNoTemplate(): void {
		$service = $this->serviceAnsweringWith(fn () => '{"Link":{}}');
		$host = 'mastodon.example';
		$protocols = ['https'];

		$this->expectException(HostMetaException::class);
		$this->expectExceptionMessage('Failed to get URL');
		$service->hostMeta($host, $protocols);
	}

	public function testHostMetaWrapsTransportErrors(): void {
		$service = $this->serviceAnsweringWith(function () {
			throw new RequestNetworkException('timeout');
		});
		$host = 'mastodon.example';
		$protocols = ['https'];

		$this->expectException(HostMetaException::class);
		$this->expectExceptionMessage('RequestNetworkException - timeout');
		$service->hostMeta($host, $protocols);
	}

	public function testWebfingerAccountFollowsHostMetaAndCanonicalisesTheAccount(): void {
		$service = $this->serviceAnsweringWith(fn (NCRequest $request) => $request->getPath() === '/.well-known/host-meta'
			? $this->hostMetaJson('https://wf.mastodon.example/.well-known/webfinger?resource={uri}')
			: $this->jrd('acct:Bob@mastodon.example'));
		$account = '@bob@mastodon.example';

		$result = $service->webfingerAccount($account);

		$this->assertSame('Bob@mastodon.example', $account, 'the subject of the JRD wins');
		$this->assertSame('acct:Bob@mastodon.example', $result['subject']);
		$this->assertCount(2, $this->requests);
		$webfinger = $this->requests[1];
		$this->assertSame('/.well-known/webfinger', $webfinger->getPath());
		$this->assertSame('wf.mastodon.example', $webfinger->getHost());
		$this->assertSame(['https'], $webfinger->getProtocols());
		$this->assertSame(['resource' => 'acct:bob@mastodon.example'], $webfinger->getParams());
		$this->assertSame(['ignoreJsonHeaders' => true], $webfinger->getClientOptions());
	}

	public function testWebfingerAccountFallsBackToTheDefaultPathWithoutHostMeta(): void {
		$service = $this->serviceAnsweringWith(function (NCRequest $request) {
			if ($request->getPath() === '/.well-known/host-meta') {
				throw new RequestNetworkException('404');
			}

			return $this->jrd();
		});
		$account = 'bob@mastodon.example';

		$service->webfingerAccount($account);

		$webfinger = $this->requests[1];
		$this->assertSame('/.well-known/webfinger', $webfinger->getPath());
		$this->assertSame('mastodon.example', $webfinger->getHost());
		$this->assertSame(['https', 'http'], $webfinger->getProtocols());
	}

	public function testWebfingerAccountRequiresAHost(): void {
		$service = $this->serviceAnsweringWith(fn () => '{}');
		$account = 'bob';

		$this->expectException(InvalidResourceException::class);
		$service->webfingerAccount($account);
		$this->assertSame([], $this->requests);
	}

	public function testWebfingerAccountRejectsNonJsonAnswers(): void {
		$service = $this->serviceAnsweringWith(fn (NCRequest $request) => $request->getPath() === '/.well-known/host-meta' ? $this->hostMetaJson() : '<html>');
		$account = 'bob@mastodon.example';

		$this->expectException(RequestResultNotJsonException::class);
		$service->webfingerAccount($account);
	}

	public function testRetrieveObjectFetchesActivityJsonAndAnnotatesTheResult(): void {
		$service = $this->serviceAnsweringWith(function (NCRequest $request) {
			$request->setResultCode(200);

			return json_encode(['id' => self::BOB, 'type' => 'Person']);
		});

		$result = $service->retrieveObject(self::BOB . '?page=2&min_id=7');

		$this->assertSame(['id' => self::BOB, 'type' => 'Person', '_host' => 'mastodon.example', '_resultCode' => 200], $result);
		$request = $this->requests[0];
		$this->assertSame('/users/bob', $request->getPath());
		$this->assertSame('mastodon.example', $request->getHost());
		$this->assertSame(['https'], $request->getProtocols());
		$this->assertSame(Request::TYPE_GET, $request->getType());
		$this->assertSame(['page' => '2', 'min_id' => '7'], $request->getParams());
		$this->assertSame('application/activity+json', $request->getHeaders()['Accept']);
	}

	/**
	 * An ActivityPub GET is signed: unsigned, a peer running Mastodon's
	 * AUTHORIZED_FETCH or GoToSocial's secure mode answers 401 to every actor,
	 * object and collection fetch.
	 */
	public function testAnActivityPubFetchIsSigned(): void {
		$this->httpSignatureService = $this->createMock(HttpSignatureService::class);
		$this->httpSignatureService->expects($this->once())
			->method('signFetch')
			->willReturnCallback(function (NCRequest $request): bool {
				$request->addHeader('Signature', 'keyId="k"');

				return true;
			});
		$service = $this->serviceAnsweringWith(fn () => '{"id":"x"}');

		$service->retrieveObject(self::BOB);

		$this->assertSame('keyId="k"', $this->requests[0]->getHeaders()['Signature']);
	}

	/** A request that is not asking for ActivityPub is nobody's business to sign. */
	public function testANonActivityPubFetchIsNotSigned(): void {
		$this->httpSignatureService = $this->createMock(HttpSignatureService::class);
		$this->httpSignatureService->expects($this->never())->method('signFetch');
		$service = $this->serviceAnsweringWith(fn () => '{}');

		$service->retrieveObject(self::BOB, false);
	}

	/**
	 * A signature is an addition to a request that used to go out without one,
	 * and a peer is entitled not to expect it. One unsigned retry keeps those
	 * peers reachable.
	 */
	public function testASignedFetchRefusedWithA401IsRetriedUnsigned(): void {
		$this->httpSignatureService = $this->createMock(HttpSignatureService::class);
		$this->httpSignatureService->method('signFetch')
			->willReturnCallback(function (NCRequest $request): bool {
				$request->addHeader('Signature', 'keyId="k"');

				return true;
			});

		$service = $this->serviceAnsweringWith(function (NCRequest $request) {
			if (array_key_exists('Signature', $request->getHeaders())) {
				throw new RequestContentException('nope', 401);
			}
			$request->setResultCode(200);

			return json_encode(['id' => self::BOB, 'type' => 'Person']);
		});

		$result = $service->retrieveObject(self::BOB);

		$this->assertSame(self::BOB, $result['id']);
		$this->assertCount(2, $this->requests);
		$this->assertArrayNotHasKey('Signature', $this->requests[1]->getHeaders());
	}

	public function testASignedFetchThatIsAnswered404IsNotRetried(): void {
		$this->httpSignatureService = $this->createMock(HttpSignatureService::class);
		$this->httpSignatureService->method('signFetch')->willReturn(true);
		$service = $this->serviceAnsweringWith(function () {
			throw new RequestContentException('gone', 404);
		});

		try {
			$service->retrieveObject(self::BOB);
			$this->fail('expected a RequestContentException');
		} catch (RequestContentException $e) {
			$this->assertSame(404, $e->getCode());
		}

		$this->assertCount(1, $this->requests);
	}

	public function testAnUnsignedFetchRefusedWithA401IsNotRetried(): void {
		$service = $this->serviceAnsweringWith(function () {
			throw new RequestContentException('nope', 401);
		});

		$this->expectException(RequestContentException::class);
		try {
			$service->retrieveObject(self::BOB);
		} finally {
			$this->assertCount(1, $this->requests);
		}
	}

	public function testRetrieveObjectCanSkipTheActivityJsonAcceptHeader(): void {
		$service = $this->serviceAnsweringWith(fn () => '{}');

		$service->retrieveObject(self::BOB, false);

		$this->assertArrayNotHasKey('Accept', $this->requests[0]->getHeaders());
	}

	public function testRetrieveObjectRejectsRelativeIds(): void {
		$service = $this->serviceAnsweringWith(fn () => '{}');

		$this->expectException(MalformedArrayException::class);
		$service->retrieveObject('/users/bob');
	}

	private function useActivityPubReturning(?Person $actor): void {
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromData')->willReturn($actor ?? new Note());
		$ap->method('isActor')->willReturnCallback(fn ($item) => $item instanceof Person);
		AP::set($ap);
	}

	private function webfingerThenActor(array $actorData): CurlService {
		return $this->serviceAnsweringWith(fn (NCRequest $request) => match ($request->getPath()) {
			'/.well-known/host-meta' => $this->hostMetaJson(),
			'/.well-known/webfinger' => $this->jrd(),
			default => json_encode($actorData),
		});
	}

	public function testRetrieveAccountResolvesTheSelfLinkToAnActor(): void {
		$service = $this->webfingerThenActor(['id' => self::BOB, 'type' => 'Person']);
		$bob = new Person();
		$bob->setId(self::BOB);
		$this->useActivityPubReturning($bob);
		$account = 'bob@mastodon.example';

		$this->assertSame($bob, $service->retrieveAccount($account));
		$this->assertSame('/users/bob', end($this->requests)->getPath());
	}

	public function testRetrieveAccountAcceptsCaseDifferencesInTheActorId(): void {
		$service = $this->webfingerThenActor(['id' => strtoupper(self::BOB)]);
		$bob = new Person();
		$bob->setId(strtoupper(self::BOB));
		$this->useActivityPubReturning($bob);
		$account = 'bob@mastodon.example';

		$this->assertSame($bob, $service->retrieveAccount($account));
	}

	public function testRetrieveAccountRejectsAnActorClaimingAnotherId(): void {
		$service = $this->webfingerThenActor(['id' => 'https://evil.example/users/bob']);
		$impostor = new Person();
		$impostor->setId('https://evil.example/users/bob');
		$this->useActivityPubReturning($impostor);
		$account = 'bob@mastodon.example';

		$this->expectException(InvalidOriginException::class);
		$service->retrieveAccount($account);
	}

	public function testRetrieveAccountRejectsANonActorDocument(): void {
		$service = $this->webfingerThenActor(['type' => 'Note']);
		$this->useActivityPubReturning(null);
		$account = 'bob@mastodon.example';

		$this->expectException(ItemUnknownException::class);
		$service->retrieveAccount($account);
	}

	public function testRetrieveAccountRequiresASelfLink(): void {
		$service = $this->serviceAnsweringWith(fn (NCRequest $request) => $request->getPath() === '/.well-known/host-meta'
			? $this->hostMetaJson()
			: $this->jrd('acct:bob@mastodon.example', null));
		$account = 'bob@mastodon.example';

		$this->expectException(RetrieveAccountFormatException::class);
		$service->retrieveAccount($account);
	}

	public function testRetrieveJsonRejectsEmptyBodies(): void {
		$service = $this->serviceAnsweringWith(fn () => '');

		$this->expectException(RequestResultNotJsonException::class);
		$service->retrieveJson(new NCRequest('/x'));
	}

	public function testAsyncWithTokenPostsToTheLocalAsyncEndpoint(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://cloud.example.com/nextcloud/apps/social/');
		$this->configService->method('getCloudHost')->willReturn('cloud.example.com');
		$service = $this->serviceAnsweringWith(fn () => 'not json');

		$service->asyncWithToken('abc-123');

		$request = $this->requests[0];
		$this->assertSame('/nextcloud/apps/social/async/request/abc-123', $request->getPath());
		$this->assertSame('cloud.example.com', $request->getHost());
		$this->assertSame(['https'], $request->getProtocols());
		$this->assertSame(Request::TYPE_POST, $request->getType());
	}

	public function testAsyncWithTokenSwallowsTransportErrors(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://cloud.example.com/apps/social/');
		$service = $this->serviceAnsweringWith(function () {
			throw new RequestNetworkException('refused');
		});

		$service->asyncWithToken('abc-123');
		$this->assertCount(1, $this->requests);
	}

	// --- the transport itself (the OCP http client)

	/** An IResponse double; the body is streamed the way the real client does. */
	private function answer(string $body, int $code = 200, string $contentType = 'application/json'): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($code);
		$response->method('getHeader')->willReturnCallback(
			static fn (string $key): string => (strtolower($key) === 'content-type') ? $contentType : ''
		);
		$response->method('getBody')->willReturnCallback(static function () use ($body) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, $body);
			rewind($stream);

			return $stream;
		});

		return $response;
	}

	/** Captures what the client was asked to send. */
	private function captureRequest(IResponse $response): callable {
		$captured = null;
		$this->client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) use ($response, &$captured): IResponse {
				$captured = ['method' => $method, 'url' => $url, 'options' => $options];

				return $response;
			}
		);

		return static function () use (&$captured): ?array {
			return $captured;
		};
	}

	private function service(): CurlService {
		return new CurlService(
			$this->configService, $this->fediverseService, $this->clientService,
			$this->httpSignatureService, new NullLogger()
		);
	}

	public function testTheRequestIsSentThroughTheServersHttpClient(): void {
		$sent = $this->captureRequest($this->answer('{"ok":true}'));
		$request = new NCRequest('/users/bob', Request::TYPE_GET);
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocol('https');
		$request->addParam('page', '2');
		$request->addHeader('Accept', 'application/activity+json');
		$request->setTimeout(7);

		$this->assertSame('{"ok":true}', $this->service()->doRequestOrig($request));

		$this->assertSame('get', $sent()['method']);
		$this->assertSame('https://' . self::PUBLIC_IP . '/users/bob?page=2', $sent()['url']);
		$this->assertSame('application/activity+json', $sent()['options']['headers']['Accept']);
		$this->assertSame(7, $sent()['options']['timeout']);
		$this->assertSame(200, $request->getResultCode());
		$this->assertSame('application/json', $request->getContentType());
	}

	public function testTheClientIsToldNotToReachLocalAddresses(): void {
		$sent = $this->captureRequest($this->answer('{}'));
		$request = new NCRequest('/users/bob');
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocol('https');

		$this->service()->doRequestOrig($request);

		$this->assertFalse($sent()['options']['nextcloud']['allow_local_address']);
		// left to the server, which re-checks every redirect it follows
		$this->assertArrayNotHasKey('allow_redirects', $sent()['options']);
		$this->assertTrue($sent()['options']['stream'], 'an endless body must not fill memory');
		$this->assertFalse($sent()['options']['http_errors'], 'the status code belongs to the caller');
	}

	public function testARequestThatMayReachLocalAddressesSaysSo(): void {
		$sent = $this->captureRequest($this->answer('{}'));
		$request = new NCRequest('/inbox');
		$request->setHost('localhost');
		$request->setProtocol('http');
		$request->setLocalAddressAllowed(true);

		$this->service()->doRequestOrig($request);

		$this->assertTrue($sent()['options']['nextcloud']['allow_local_address']);
	}

	#[DataProvider('refusedHostProvider')]
	public function testALocalHostIsRefusedBeforeAnythingIsSent(string $host): void {
		$this->client->expects($this->never())->method('request');
		$request = new NCRequest('/users/bob');
		$request->setHost($host);
		$request->setProtocol('http');

		$this->expectException(RequestServerException::class);
		$this->service()->doRequestOrig($request);
	}

	/** @return array<string, array{string}> */
	public static function refusedHostProvider(): array {
		return [
			'loopback' => ['127.0.0.1'],
			'private range' => ['10.0.0.5'],
			'cloud metadata' => ['169.254.169.254'],
			// a name that resolves to nothing is no legitimate peer either
			'unresolvable' => ['mastodon.invalid'],
		];
	}

	public function testRedirectsAreRefusedWhenTheRequestDoesNotWantThem(): void {
		$sent = $this->captureRequest($this->answer('{}'));
		$request = new NCRequest('/users/bob');
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocol('https');
		$request->setFollowLocation(false);

		$this->service()->doRequestOrig($request);

		$this->assertFalse($sent()['options']['allow_redirects']);
	}

	public function testABodyIsSentWithAWritingRequest(): void {
		$sent = $this->captureRequest($this->answer('{}'));
		$request = new NCRequest('/inbox', Request::TYPE_POST);
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocol('https');
		$request->setDataJson('{"type":"Create"}');

		$this->service()->doRequestOrig($request);

		$this->assertSame('post', $sent()['method']);
		$this->assertSame('{"type":"Create"}', $sent()['options']['body']);
		$this->assertSame('https://' . self::PUBLIC_IP . '/inbox', $sent()['url'], 'no query string on a post');
	}

	public function testAnErrorStatusBecomesAContentException(): void {
		$this->client->method('request')->willReturn($this->answer('gone', 410, 'text/plain'));
		$request = new NCRequest('/users/bob');
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocol('https');

		try {
			$this->service()->doRequestOrig($request);
			$this->fail('an error status has to be raised');
		} catch (RequestContentException $e) {
			$this->assertSame(410, $e->getCode());
			$this->assertSame(410, $request->getResultCode());
		}
	}

	public function testATransportFailureBecomesANetworkException(): void {
		$this->client->method('request')->willThrowException(new Exception('connection refused'));
		$request = new NCRequest('/users/bob');
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocol('https');

		$this->expectException(RequestNetworkException::class);
		$this->service()->doRequestOrig($request);
	}

	public function testTheNextProtocolIsTriedWhenOneCannotConnect(): void {
		$attempts = [];
		$this->client->method('request')->willReturnCallback(
			function (string $method, string $url) use (&$attempts): IResponse {
				$attempts[] = $url;
				if (str_starts_with($url, 'https://')) {
					throw new Exception('TLS handshake failed');
				}

				return $this->answer('{"ok":true}');
			}
		);
		$request = new NCRequest('/.well-known/host-meta');
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocols(['https', 'http']);

		$this->assertSame('{"ok":true}', $this->service()->doRequestOrig($request));
		$this->assertSame(
			[
				'https://' . self::PUBLIC_IP . '/.well-known/host-meta',
				'http://' . self::PUBLIC_IP . '/.well-known/host-meta',
			],
			$attempts
		);
	}

	public function testAnErrorStatusEndsTheAttemptInsteadOfTryingTheNextProtocol(): void {
		$attempts = 0;
		$this->client->method('request')->willReturnCallback(
			function () use (&$attempts): IResponse {
				$attempts++;

				return $this->answer('nope', 404, 'text/plain');
			}
		);
		$request = new NCRequest('/users/bob');
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocols(['https', 'http']);

		$this->expectException(RequestContentException::class);
		try {
			$this->service()->doRequestOrig($request);
		} finally {
			$this->assertSame(1, $attempts, 'an answer is an answer, whatever its status');
		}
	}

	public function testABodyOverTheSizeLimitIsRefused(): void {
		// the limit comes from the max_size app setting (10 MB here)
		$this->client->method('request')->willReturn($this->answer(str_repeat('a', 10 * 1048576 + 1)));
		$request = new NCRequest('/users/bob');
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocol('https');

		$this->expectException(RequestResultSizeException::class);
		$this->service()->doRequestOrig($request);
	}

	public function testABodyAtTheSizeLimitIsKept(): void {
		$this->client->method('request')->willReturn($this->answer(str_repeat('a', 1024)));
		$request = new NCRequest('/users/bob');
		$request->setHost(self::PUBLIC_IP);
		$request->setProtocol('https');

		$this->assertSame(1024, strlen($this->service()->doRequestOrig($request)));
	}
}
