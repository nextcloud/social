<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\WellKnown;

use OCA\Social\Controller\OAuthController;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\InstancesRequest;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Instance;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Tests\Federation\TBuildsServices;
use OCA\Social\WellKnown\WebfingerHandler;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\WellKnown\IRequestContext;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The discovery documents checked against their specifications rather than
 * against a fixed answer: WebFinger's JRD (RFC 7033 §4.4) and NodeInfo 2.0
 * and 2.1 (the schemas published at nodeinfo.diaspora.software), the latter
 * re-stated here as a small checker since the app takes no schema library.
 *
 * On an install in a subdirectory — `https://cloud.example/nextcloud`, which
 * #1808 once doubled into `/nextcloud/nextcloud` — every link stays under
 * the one prefix.
 */
class DiscoverySchemaTest extends TestCase {
	use TBuildsServices;

	private const CLOUD = 'https://cloud.example/nextcloud/index.php';
	private const SOCIAL = 'https://cloud.example/nextcloud/index.php/apps/social/';
	private const ALICE = self::SOCIAL . '@alice';

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function config(): ConfigService {
		$config = $this->createStub(ConfigService::class);
		$config->method('getCloudUrl')->willReturnCallback(
			static fn (bool $noPhp = false): string => $noPhp ? 'https://cloud.example/nextcloud' : self::CLOUD
		);
		$config->method('getCloudHost')->willReturn('cloud.example');
		$config->method('getSocialUrl')->willReturn(self::SOCIAL);
		$config->method('getSocialAddress')->willReturn('cloud.example');
		$config->method('getAppValue')->willReturn('');

		return $config;
	}

	private function webfinger(string $resource): array {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturn($resource);
		\OC::$server->register(IRequest::class, $request);
		$context = $this->createStub(IRequestContext::class);
		$context->method('getHttpRequest')->willReturn($request);

		$alice = new Person();
		$alice->setId(self::ALICE);
		$alice->setPreferredUsername('alice');
		$alice->setLocal(true);
		$cacheActors = $this->createStub(CacheActorService::class);
		$cacheActors->method('getFromLocalAccount')->willReturn($alice);
		$instanceActor = $this->createStub(InstanceActorService::class);
		$instanceActor->method('getId')->willReturn(self::SOCIAL . 'actor');

		$handler = new WebfingerHandler(
			$this->createStub(CacheActorsRequest::class), $cacheActors,
			$this->createStub(\OCA\Social\Service\FediverseService::class), $this->config(), $instanceActor
		);
		$http = $handler->handle('webfinger', $context, null)?->toHttpResponse();
		$this->assertInstanceOf(JSONResponse::class, $http);

		return $http->getData();
	}

	/** RFC 7033 §4.4: what a JRD may hold, and the type of each member. */
	private function assertJrd(array $jrd): void {
		$this->assertSame([], array_diff(array_keys($jrd), ['subject', 'aliases', 'properties', 'links']), 'only JRD members');
		$this->assertIsString($jrd['subject']);
		$this->assertNotFalse(parse_url($jrd['subject'], PHP_URL_SCHEME), 'the subject is a URI');
		foreach ($jrd['aliases'] ?? [] as $alias) {
			$this->assertIsString($alias);
		}
		$this->assertIsList($jrd['links']);
		foreach ($jrd['links'] as $link) {
			$this->assertIsString($link['rel'] ?? null, 'every link has a rel');
			$this->assertSame([], array_diff(array_keys($link), ['rel', 'type', 'href', 'titles', 'properties', 'template']));
			foreach (['type', 'href', 'template'] as $member) {
				if (array_key_exists($member, $link)) {
					$this->assertIsString($link[$member]);
				}
			}
		}
	}

	public function testAWebFingerAnswerIsAJrdWhoseLinksStayUnderTheSubdirectory(): void {
		$jrd = $this->webfinger('acct:alice@cloud.example');

		$this->assertJrd($jrd);
		$this->assertSame('acct:alice@cloud.example', $jrd['subject']);
		$self = array_values(array_filter($jrd['links'], static fn (array $link): bool => $link['rel'] === 'self'));
		$this->assertSame([['rel' => 'self', 'type' => 'application/activity+json', 'href' => self::ALICE]], $self);

		$urls = array_merge($jrd['aliases'], array_filter(array_column($jrd['links'], 'href')), array_filter(array_column($jrd['links'], 'template')));
		foreach ($urls as $url) {
			$this->assertStringStartsWith('https://cloud.example/nextcloud/', $url);
			$this->assertStringNotContainsString('/nextcloud/nextcloud', $url);
		}
	}

	public function testTheInstanceActorIsAJrdToo(): void {
		$jrd = $this->webfinger('acct:cloud.example@cloud.example');

		$this->assertJrd($jrd);
		$this->assertContains(self::SOCIAL . 'actor', array_column($jrd['links'], 'href'));
	}

	public function testHostMetaPointsAtTheSubdirectorysWebFinger(): void {
		$context = $this->createStub(IRequestContext::class);
		$handler = new WebfingerHandler(
			$this->createStub(CacheActorsRequest::class), $this->createStub(CacheActorService::class),
			$this->createStub(\OCA\Social\Service\FediverseService::class), $this->config(),
			$this->createStub(InstanceActorService::class)
		);

		$this->assertStringContainsString(
			'template="https://cloud.example/nextcloud/.well-known/webfinger?resource={uri}"',
			(string)$handler->handle('host-meta', $context, null)?->toHttpResponse()->render()
		);
	}

	private function nodeInfo(string $version): array {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'installed_version' => '0.26.180',
				'name' => 'Aloha Social',
				default => $default,
			}
		);
		$users = $this->createStub(IUserManager::class);
		$users->method('countUsers')->willReturn(['Database' => 3]);
		$stats = $this->createStub(InstanceStatsRequest::class);
		$stats->method('countLocalStatuses')->willReturn(42);
		$instances = $this->createStub(InstancesRequest::class);
		$instances->method('getLocal')->willReturn(new Instance());
		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn(self::SOCIAL);

		$instanceService = $this->realService(InstanceService::class, [
			InstancesRequest::class => $instances,
			ConfigService::class => $this->config(),
			IAppConfig::class => $appConfig,
			IURLGenerator::class => $urls,
			IUserManager::class => $users,
			InstanceStatsRequest::class => $stats,
		]);
		$controller = $this->realService(OAuthController::class, [
			InstanceService::class => $instanceService,
			ConfigService::class => $this->config(),
			IURLGenerator::class => $urls,
			'logger' => new NullLogger(),
		]);

		$response = ($version === '2.0') ? $controller->nodeinfo2() : $controller->nodeinfo21();

		return json_decode((string)json_encode($response->getData()), true);
	}

	/** @return iterable<string, array{string, list<string>}> */
	public static function nodeInfoVersions(): iterable {
		yield 'NodeInfo 2.0' => ['2.0', ['name', 'version']];
		yield 'NodeInfo 2.1' => ['2.1', ['name', 'version', 'repository', 'homepage']];
	}

	/**
	 * The schema's own rules: these top-level members and no others (an old
	 * `rootUrl` was one too many), a software name crawlers can group by,
	 * protocols and services from its enumerations, and integer usage.
	 */
	#[DataProvider('nodeInfoVersions')]
	public function testNodeInfoMatchesItsSchema(string $version, array $softwareKeys): void {
		$info = $this->nodeInfo($version);

		$this->assertSame(
			['version', 'software', 'protocols', 'services', 'usage', 'openRegistrations', 'metadata'],
			array_keys($info)
		);
		$this->assertSame($version, $info['version']);
		$this->assertSame($softwareKeys, array_keys($info['software']));
		$this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $info['software']['name']);
		$this->assertMatchesRegularExpression('/^\S+$/', $info['software']['version']);
		$this->assertNotSame([], $info['protocols']);
		$this->assertSame([], array_diff($info['protocols'], [
			'activitypub', 'buddycloud', 'dfrn', 'diaspora', 'libertree', 'ostatus', 'pumpio', 'tent', 'xmpp', 'zot',
		]));
		$this->assertIsList($info['services']['inbound']);
		$this->assertIsList($info['services']['outbound']);
		$this->assertIsBool($info['openRegistrations']);
		$this->assertIsArray($info['usage']['users']);
		foreach ($info['usage']['users'] as $count) {
			$this->assertIsInt($count);
			$this->assertGreaterThanOrEqual(0, $count);
		}
		$this->assertIsInt($info['usage']['localPosts'] ?? 0);
		$this->assertIsArray($info['metadata']);
	}
}
