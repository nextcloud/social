<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckServiceTest extends TestCase {
	private IUserManager|MockObject $userManager;
	private ICache|MockObject $cache;
	private IAppConfig|MockObject $appConfig;
	private IConfig|MockObject $config;
	private IClient|MockObject $client;
	private IRequest|MockObject $request;
	private IURLGenerator|MockObject $urlGenerator;
	private FollowsRequest|MockObject $followRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private StreamRequest|MockObject $streamRequest;
	private AccountService|MockObject $accountService;
	private MiscService|MockObject $miscService;
	private ConfigService|MockObject $configService;
	private CheckService $service;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->cache = $this->createMock(ICache::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createMock(IConfig::class);
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$this->request = $this->createMock(IRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->followRequest = $this->createMock(FollowsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->miscService = $this->createMock(MiscService::class);
		$this->configService = $this->createMock(ConfigService::class);

		$this->service = new CheckService(
			$this->userManager,
			'alice',
			$this->cache,
			$this->appConfig,
			$this->config,
			$clientService,
			$this->request,
			$this->urlGenerator,
			$this->followRequest,
			$this->cacheActorsRequest,
			$this->createMock(StreamDestRequest::class),
			$this->streamRequest,
			$this->accountService,
			$this->configService,
			$this->miscService,
		);
	}

	private function response(int $status): IResponse|MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);

		return $response;
	}

	public function testCheckWellKnownTrustsTheCache(): void {
		$this->cache->method('get')->with(CheckService::CACHE_PREFIX . 'wellknown')->willReturn('true');
		$this->client->expects($this->never())->method('get');

		$this->assertTrue($this->service->checkWellKnown());
	}

	public function testCheckWellKnownProbesTheConfiguredAddressFirstAndCachesSuccess(): void {
		$this->cache->method('get')->willReturn(null);
		$this->appConfig->method('getValueString')->with('social', 'address', '')->willReturn('https://social.example.com');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'social.checkssl' ? false : $default
		);
		$this->client->expects($this->once())
			->method('get')
			->with(
				'https://social.example.com/.well-known/webfinger?resource=acct:alice@social.example.com',
				['nextcloud' => ['allow_local_address' => false], 'verify' => false],
			)
			->willReturn($this->response(200));
		$this->cache->expects($this->once())->method('set')->with(CheckService::CACHE_PREFIX . 'wellknown', 'true', 3600);

		$this->assertTrue($this->service->checkWellKnown());
	}

	public function testOnlyTheAdminsOwnAddressMayResolveLocally(): void {
		// one of the candidates is built from the Host header, so reaching a
		// local address must be limited to the URL the admin configured
		$this->cache->method('get')->willReturn(null);
		$this->appConfig->method('getValueString')->with('social', 'address', '')->willReturn('http://localhost');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => match ($key) {
				'overwrite.cli.url' => 'http://localhost',
				'social.checkssl' => false,
				default => $default,
			}
		);
		$this->client->expects($this->once())
			->method('get')
			->with(
				'http://localhost/.well-known/webfinger?resource=acct:alice@localhost',
				['nextcloud' => ['allow_local_address' => true], 'verify' => false],
			)
			->willReturn($this->response(200));

		$this->assertTrue($this->service->checkWellKnown());
	}

	public function testTheHostHeaderCandidateNeverReachesALocalAddress(): void {
		$this->cache->method('get')->willReturn(null);
		$this->appConfig->method('getValueString')->with('social', 'address', '')->willReturn('');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => match ($key) {
				'overwrite.cli.url' => 'https://cloud.example.com',
				'social.checkssl' => false,
				default => $default,
			}
		);
		$this->request->method('getServerProtocol')->willReturn('http');
		$this->request->method('getServerHost')->willReturn('127.0.0.1');
		$this->urlGenerator->method('getBaseUrl')->willReturn('http://127.0.0.1');
		$this->client->method('get')->willReturnCallback(
			function (string $url, array $options): IResponse {
				$this->assertFalse($options['nextcloud']['allow_local_address']);

				return $this->response(404);
			}
		);

		$this->assertFalse($this->service->checkWellKnown());
	}

	public function testANonWebAddressIsNotProbedAtAll(): void {
		$this->cache->method('get')->willReturn(null);
		$this->appConfig->method('getValueString')->with('social', 'address', '')->willReturn('file:///etc');
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $default
		);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('cloud.example.com');
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.com');
		$this->client->expects($this->exactly(2))->method('get')->willReturn($this->response(404));

		$this->assertFalse($this->service->checkWellKnown());
	}

	public function testCheckWellKnownFallsBackToTheRequestHostThenTheBaseUrl(): void {
		$this->cache->method('get')->willReturn(null);
		$this->appConfig->method('getValueString')->willReturn('');
		$this->config->method('getSystemValue')->willReturn(true);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('cloud.example.com');
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.com/nextcloud');
		$probed = [];
		$this->client->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function (string $url) use (&$probed) {
				$probed[] = $url;

				return $this->response(count($probed) === 1 ? 404 : 200);
			});

		$this->assertTrue($this->service->checkWellKnown());
		$this->assertSame([
			'https://cloud.example.com/.well-known/webfinger?resource=acct:alice@cloud.example.com',
			'https://cloud.example.com/nextcloud/.well-known/webfinger?resource=acct:alice@cloud.example.com',
		], $probed);
	}

	public function testCheckWellKnownFailsWhenEveryProbeFails(): void {
		$this->cache->method('get')->willReturn(null);
		$this->appConfig->method('getValueString')->willReturn('');
		$this->request->method('getServerProtocol')->willReturn('http');
		$this->request->method('getServerHost')->willReturn('localhost');
		$this->urlGenerator->method('getBaseUrl')->willReturn('http://localhost');
		$this->client->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function (string $url) {
				if (str_contains($url, '?resource=acct:alice@localhost') && $url === 'http://localhost/.well-known/webfinger?resource=acct:alice@localhost') {
					throw new Exception('connection refused');
				}

				return $this->response(500);
			});
		$this->cache->expects($this->never())->method('set');

		$this->assertFalse($this->service->checkWellKnown());
	}

	/** The server declares a URL and the app agrees with it. */
	private function addressesAgree(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'overwrite.cli.url' ? 'https://cloud.example' : $default
		);
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example/index.php');
	}

	public function testCheckDefaultReportsTheWellKnownCheck(): void {
		$this->cache->method('get')->willReturn('true');
		$this->addressesAgree();

		$this->assertSame(
			[
				'success' => true,
				'checks' => ['wellknown' => true, 'cloudAddress' => true],
				'addresses' => [
					'configured' => 'https://cloud.example/index.php',
					'expected' => 'https://cloud.example/index.php',
				],
			],
			$this->service->checkDefault()
		);
	}

	public function testCheckDefaultFailsWhenACheckFails(): void {
		$this->cache->method('get')->willReturn(null);
		$this->appConfig->method('getValueString')->willReturn('');
		$this->addressesAgree();
		$this->client->method('get')->willReturn($this->response(404));

		$result = $this->service->checkDefault();

		$this->assertFalse($result['success']);
		$this->assertFalse($result['checks']['wellknown']);
	}

	// the address the app builds ids from vs. the one the server says it has

	public function testTheAddressCheckPassesWhenTheServerAndTheAppAgree(): void {
		$this->addressesAgree();

		$this->assertTrue($this->service->checkCloudAddress());
	}

	public function testTheAddressCheckFailsOnceTheServersUrlHasMovedOn(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'overwrite.cli.url' ? 'https://new.example' : $default
		);
		// what the app was set up with, and still builds every id from
		$this->configService->method('getCloudUrl')->willReturn('https://old.example/index.php');

		$this->assertFalse($this->service->checkCloudAddress());
	}

	public function testATrailingSlashIsNotADisagreement(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'overwrite.cli.url' ? 'https://cloud.example/' : $default
		);
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example/index.php/');

		$this->assertTrue($this->service->checkCloudAddress());
	}

	public function testAServerThatDeclaresNoUrlIsNotADisagreement(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $default
		);
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example/index.php');

		// nothing to compare against; this is not the app's problem to report
		$this->assertTrue($this->service->checkCloudAddress());
	}

	public function testAnAppThatWasNeverSetUpIsNotADisagreement(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => $key === 'overwrite.cli.url' ? 'https://cloud.example' : $default
		);
		$this->configService->method('getCloudUrl')
			->willThrowException(new SocialAppConfigException());

		// the setup screen handles this one; it is not a mismatch
		$this->assertTrue($this->service->checkCloudAddress());
	}

	public function testTheIndexPhpSuffixFollowsTheFrontController(): void {
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => match ($key) {
				'overwrite.cli.url' => 'https://cloud.example',
				'htaccess.IgnoreFrontController' => true,
				default => $default,
			}
		);

		$this->assertSame('https://cloud.example', $this->service->derivedCloudAddress());
	}

	private function follow(string $id, string $actorId, string $objectId): Follow {
		$follow = new Follow();
		$follow->setId($id);
		$follow->setActorId($actorId);
		$follow->setObjectId($objectId);

		return $follow;
	}

	public function testRemoveInvalidFollowsDropsFollowsWithAnUnknownActorOrObject(): void {
		$known = 'https://cloud.example.com/apps/social/@alice';
		$this->followRequest->method('getAll')->willReturn([
			$this->follow('f1', $known, 'https://remote.example/users/bob'),
			$this->follow('f2', $known, 'https://gone.example/users/x'),
			$this->follow('f3', 'https://gone.example/users/y', $known),
		]);
		$this->cacheActorsRequest->method('getFromId')->willReturnCallback(function (string $id) {
			if (str_starts_with($id, 'https://gone.example/')) {
				throw new CacheActorDoesNotExistException();
			}

			return new Person();
		});
		$deleted = [];
		$this->followRequest->expects($this->exactly(2))
			->method('deleteById')
			->willReturnCallback(function (string $id) use (&$deleted): void {
				$deleted[] = $id;
			});
		$this->miscService->expects($this->once())->method('log')->with('removeInvalidFollows removed 2 entries', 1);

		$this->assertSame(2, $this->service->removeInvalidFollows());
		$this->assertSame(['f2', 'f3'], $deleted);
	}

	public function testRemoveInvalidNotesDropsNotesFromUnknownAuthors(): void {
		$valid = new Note();
		$valid->setId('https://remote.example/notes/1');
		$valid->setAttributedTo('https://remote.example/users/bob');
		$orphan = new Note();
		$orphan->setId('https://gone.example/notes/2');
		$orphan->setAttributedTo('https://gone.example/users/x');
		$this->streamRequest->method('getAll')->with(Note::TYPE)->willReturn([$valid, $orphan]);
		$this->cacheActorsRequest->method('getFromId')->willReturnCallback(function (string $id) {
			if ($id === 'https://gone.example/users/x') {
				throw new CacheActorDoesNotExistException();
			}

			return new Person();
		});
		$this->streamRequest->expects($this->once())->method('deleteById')->with('https://gone.example/notes/2', Note::TYPE);
		$this->miscService->expects($this->once())->method('log')->with('removeInvalidNotes removed 1 entries', 1);

		$this->assertSame(1, $this->service->removeInvalidNotes());
	}

	public function testCheckInstallationStatusRunsRepairsAndLoopbackFollows(): void {
		$this->followRequest->method('getAll')->willReturn([]);
		$this->streamRequest->method('getAll')->willReturn([]);
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$this->userManager->method('search')->with('')->willReturn([$alice, $bob]);
		$actor = new Person();
		$this->accountService->method('getActorFromUserId')->willReturnCallback(function (string $uid) use ($actor) {
			if ($uid === 'bob') {
				throw new ActorDoesNotExistException();
			}

			return $actor;
		});
		$this->followRequest->expects($this->once())->method('generateLoopbackAccount')->with($this->identicalTo($actor));

		$result = $this->service->checkInstallationStatus();

		$this->assertSame(['invalidFollows' => 0, 'invalidNotes' => 0], $result);
	}

	public function testLightInstallationStatusSkipsTheRepairs(): void {
		$this->followRequest->expects($this->never())->method('getAll');
		$this->streamRequest->expects($this->never())->method('getAll');
		$this->userManager->method('search')->willReturn([]);

		$this->assertSame([], $this->service->checkInstallationStatus(true));
	}

	public function testCheckInstallationStatusSurvivesLoopbackFailures(): void {
		$this->userManager->method('search')->willThrowException(new Exception('ldap down'));

		$this->assertSame([], $this->service->checkInstallationStatus(true));
	}

	public function testCheckStatusTableFollowsSeedsAPlaceholderWhenEmpty(): void {
		$this->followRequest->method('countFollows')->willReturn(0);
		$this->followRequest->expects($this->once())
			->method('save')
			->with($this->callback(function (Follow $follow) {
				$this->assertSame('Unknown', $follow->getType());
				$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $follow->getId());
				$this->assertNotSame($follow->getActorId(), $follow->getObjectId());

				return true;
			}));

		$this->service->checkStatusTableFollows();
	}

	public function testCheckStatusTableFollowsLeavesAPopulatedTableAlone(): void {
		$this->followRequest->method('countFollows')->willReturn(5);
		$this->followRequest->expects($this->never())->method('save');

		$this->service->checkStatusTableFollows();
	}
}
