<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

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
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CurlServiceTest extends TestCase {
	private const BOB = 'https://mastodon.example/users/bob';

	private ConfigService|MockObject $configService;
	private FediverseService|MockObject $fediverseService;
	private CurlService|MockObject $service;
	/** @var NCRequest[] every request handed to the (mocked) transport */
	private array $requests = [];

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key) => match ($key) {
				ConfigService::SOCIAL_MAX_SIZE => '10',
				'installed_version' => '0.9.1',
				default => '',
			});
		$this->fediverseService = $this->createMock(FediverseService::class);
	}

	protected function tearDown(): void {
		AP::$activityPub = null;
	}

	/** CurlService with the network layer replaced: doRequest is answered by $responder(NCRequest): string. */
	private function serviceAnsweringWith(callable $responder, string $mocked = 'doRequest'): CurlService {
		$this->service = $this->getMockBuilder(CurlService::class)
			->setConstructorArgs([$this->configService, $this->fediverseService, new NullLogger()])
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
		$service = new CurlService($this->configService, $this->fediverseService, new NullLogger());
		$property = new \ReflectionProperty(CurlService::class, 'maxDownloadSize');

		$this->assertSame(10 * 1048576, $property->getValue($service));
	}

	public function testAssignUserAgentIncludesTheInstalledVersion(): void {
		$service = new CurlService($this->configService, $this->fediverseService, new NullLogger());
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
		AP::$activityPub = $ap;
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
}
