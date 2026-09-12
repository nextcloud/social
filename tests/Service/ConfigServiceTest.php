<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase {
	private IConfig|MockObject $config;
	private IURLGenerator|MockObject $urlGenerator;
	private ConfigService $service;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->service = new ConfigService(
			'alice',
			$this->config,
			$this->createMock(IRequest::class),
			$this->urlGenerator,
			$this->createMock(MiscService::class),
		);
	}

	/** Serve app values for the 'social' app from a map, falling back to the requested default. */
	private function withAppValues(array $values): void {
		$this->config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, $default) use ($values) {
				$this->assertSame('social', $app);

				return $values[$key] ?? $default;
			});
	}

	public function testGetAppValuePassesTheKnownDefault(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('social', ConfigService::SOCIAL_MAX_SIZE, 10)
			->willReturn('20');

		$this->assertSame('20', $this->service->getAppValue(ConfigService::SOCIAL_MAX_SIZE));
	}

	public function testGetAppValueHasNoDefaultForUnknownKey(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('social', 'installed_version', null)
			->willReturn('0.9.0');

		$this->assertSame('0.9.0', $this->service->getAppValue('installed_version'));
	}

	public function testGetAppValueIntCastsTheStoredString(): void {
		$this->withAppValues([ConfigService::SOCIAL_SERVICE => '3']);

		$this->assertSame(3, $this->service->getAppValueInt(ConfigService::SOCIAL_SERVICE));
		$this->assertSame(10, $this->service->getAppValueInt(ConfigService::SOCIAL_MAX_SIZE));
	}

	public function testGetConfigReturnsEveryKnownKeyWithItsDefault(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'https://cloud.example.com']);

		$config = $this->service->getConfig();

		$this->assertSame(array_keys($this->service->defaults), array_keys($config));
		$this->assertSame('https://cloud.example.com', $config[ConfigService::CLOUD_URL]);
		$this->assertSame('all_but', $config[ConfigService::SOCIAL_ACCESS_TYPE]);
		$this->assertSame('[]', $config[ConfigService::SOCIAL_ACCESS_LIST]);
		$this->assertSame('0', $config[ConfigService::SOCIAL_SELF_SIGNED]);
	}

	public function testSetAndDeleteAppValueTargetTheSocialApp(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with('social', ConfigService::SOCIAL_ADDRESS, 'social.example.com');
		$this->config->expects($this->once())
			->method('deleteAppValue')
			->with('social', ConfigService::SOCIAL_ADDRESS);
		$this->config->expects($this->once())
			->method('deleteAppValues')
			->with('social');

		$this->service->setAppValue(ConfigService::SOCIAL_ADDRESS, 'social.example.com');
		$this->service->deleteAppValue(ConfigService::SOCIAL_ADDRESS);
		$this->service->unsetAppConfig();
	}

	public function testGetUserValueFallsBackToSessionUserAndKnownDefault(): void {
		$this->config->expects($this->once())
			->method('getUserValue')
			->with('alice', 'social', ConfigService::SOCIAL_MAX_SIZE, 10)
			->willReturn('5');

		$this->assertSame('5', $this->service->getUserValue(ConfigService::SOCIAL_MAX_SIZE));
	}

	public function testGetUserValueForAnotherAppHasEmptyDefault(): void {
		$this->config->expects($this->once())
			->method('getUserValue')
			->with('bob', 'avatar', 'version', '')
			->willReturn('3');

		$this->assertSame('3', $this->service->getUserValue('version', 'bob', 'avatar'));
	}

	public function testUserValuesAreWrittenForTheRightUser(): void {
		$this->config->expects($this->exactly(2))
			->method('setUserValue')
			->withConsecutive(
				['alice', 'social', 'key', 'value'],
				['bob', 'social', 'key', 'other'],
			);
		$this->config->expects($this->once())
			->method('getUserValue')
			->with('bob', 'social', 'key')
			->willReturn('other');

		$this->service->setUserValue('key', 'value');
		$this->service->setValueForUser('bob', 'key', 'other');
		$this->assertSame('other', $this->service->getValueForUser('bob', 'key'));
	}

	public function testCoreValuesUseTheCoreAppNamespace(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with('core', 'public_webfinger', 'social/webfinger');
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('core', 'public_webfinger', '')
			->willReturn('social/webfinger');
		$this->config->expects($this->once())
			->method('deleteAppValue')
			->with('core', 'public_webfinger');

		$this->service->setCoreValue('public_webfinger', 'social/webfinger');
		$this->assertSame('social/webfinger', $this->service->getCoreValue('public_webfinger'));
		$this->service->unsetCoreValue('public_webfinger');
	}

	public function testGetSystemValueDefaultsToEmptyString(): void {
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('overwrite.cli.url', '')
			->willReturn('https://cloud.example.com');

		$this->assertSame('https://cloud.example.com', $this->service->getSystemValue('overwrite.cli.url'));
	}

	/** @return array<string, array{string, bool, string}> */
	public static function cloudUrlProvider(): array {
		return [
			'plain' => ['https://cloud.example.com', false, 'https://cloud.example.com'],
			'trailing slash removed' => ['https://cloud.example.com/', false, 'https://cloud.example.com'],
			'index.php kept by default' => ['https://cloud.example.com/index.php', false, 'https://cloud.example.com/index.php'],
			'index.php stripped' => ['https://cloud.example.com/index.php', true, 'https://cloud.example.com'],
			'subfolder install keeps folder' => ['https://cloud.example.com/nextcloud/index.php', true, 'https://cloud.example.com/nextcloud'],
			'subfolder with trailing slash' => ['https://cloud.example.com/nextcloud/index.php/', false, 'https://cloud.example.com/nextcloud/index.php'],
			'port survives' => ['http://localhost:8080/index.php', true, 'http://localhost:8080'],
		];
	}

	/** @dataProvider cloudUrlProvider */
	public function testGetCloudUrl(string $stored, bool $noPhp, string $expected): void {
		$this->withAppValues([ConfigService::CLOUD_URL => $stored]);

		$this->assertSame($expected, $this->service->getCloudUrl($noPhp));
	}

	public function testGetCloudUrlThrowsWhenUnconfigured(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->getCloudUrl();
	}

	public function testGetCloudHostIsTheHostOfTheCloudUrl(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'https://cloud.example.com/nextcloud/index.php']);

		$this->assertSame('cloud.example.com', $this->service->getCloudHost());
	}

	public function testGetCloudHostDropsThePort(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'http://localhost:8080']);

		$this->assertSame('localhost', $this->service->getCloudHost());
	}

	public function testGetCloudHostThrowsWhenUnconfigured(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->getCloudHost();
	}

	public function testSetCloudUrlAddsAMissingScheme(): void {
		$this->config->expects($this->exactly(2))
			->method('setAppValue')
			->withConsecutive(
				['social', ConfigService::CLOUD_URL, 'http://cloud.example.com'],
				['social', ConfigService::CLOUD_URL, 'https://cloud.example.com/nextcloud'],
			);

		$this->service->setCloudUrl('cloud.example.com');
		$this->service->setCloudUrl('https://cloud.example.com/nextcloud');
	}

	public function testGetSocialAddressPrefersTheConfiguredAddress(): void {
		$this->withAppValues([
			ConfigService::SOCIAL_ADDRESS => 'social.example.com',
			ConfigService::CLOUD_URL => 'https://cloud.example.com',
		]);

		$this->assertSame('social.example.com', $this->service->getSocialAddress());
	}

	public function testGetSocialAddressFallsBackToTheCloudHost(): void {
		$this->withAppValues([ConfigService::CLOUD_URL => 'https://cloud.example.com/index.php']);

		$this->assertSame('cloud.example.com', $this->service->getSocialAddress());
	}

	public function testGetSocialAddressThrowsWhenNothingIsConfigured(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->getSocialAddress();
	}

	public function testSetSocialAddress(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with('social', ConfigService::SOCIAL_ADDRESS, 'social.example.com');

		$this->service->setSocialAddress('social.example.com');
	}

	public function testGetSocialUrl(): void {
		$this->withAppValues([ConfigService::SOCIAL_URL => 'https://cloud.example.com/apps/social/']);

		$this->assertSame('https://cloud.example.com/apps/social/', $this->service->getSocialUrl());
	}

	public function testGetSocialUrlThrowsWhenUnconfigured(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->getSocialUrl();
	}

	public function testSetSocialUrlDerivesItFromTheNavigationRoute(): void {
		$this->urlGenerator->expects($this->once())
			->method('linkToRoute')
			->with('social.Navigation.navigate')
			->willReturn('/nextcloud/apps/social/');
		$this->urlGenerator->expects($this->once())
			->method('getAbsoluteURL')
			->with('/nextcloud/apps/social/')
			->willReturn('https://cloud.example.com/nextcloud/apps/social/');
		$this->config->expects($this->once())
			->method('setAppValue')
			->with('social', ConfigService::SOCIAL_URL, 'https://cloud.example.com/nextcloud/apps/social/');

		$this->service->setSocialUrl();
	}

	public function testSetSocialUrlAddsAMissingScheme(): void {
		$this->urlGenerator->expects($this->never())->method('linkToRoute');
		$this->config->expects($this->once())
			->method('setAppValue')
			->with('social', ConfigService::SOCIAL_URL, 'http://cloud.example.com/apps/social/');

		$this->service->setSocialUrl('cloud.example.com/apps/social/');
	}

	public function testGenerateIdWithoutRandomPartIsTheSocialUrlPlusPath(): void {
		$this->withAppValues([ConfigService::SOCIAL_URL => 'https://cloud.example.com/apps/social/']);

		$this->assertSame(
			'https://cloud.example.com/apps/social/users/alice/',
			$this->service->generateId('/users/alice', false),
		);
	}

	public function testGenerateIdAppendsTimestampAndChecksum(): void {
		$this->withAppValues([ConfigService::SOCIAL_URL => 'https://cloud.example.com/apps/social/']);

		$before = time();
		$id = $this->service->generateId('documents/avatar');

		$this->assertMatchesRegularExpression(
			'#^https://cloud\.example\.com/apps/social/documents/avatar/(\d{10})(\d+)$#',
			$id,
		);
		preg_match('#/avatar/(\d{10})#', $id, $m);
		$this->assertGreaterThanOrEqual($before, (int)$m[1]);
		$this->assertNotSame($id, $this->service->generateId('documents/avatar'));
	}

	public function testTheRandomHalfOfAnIdIsNotDerivedFromTheClock(): void {
		// crc32(uniqid()) left about a million candidates per second to
		// enumerate offline; the same seed must not reproduce the value
		$this->withAppValues([ConfigService::SOCIAL_URL => 'https://cloud.example.com/apps/social/']);

		mt_srand(7);
		$first = $this->service->generateId('documents/avatar');
		mt_srand(7);

		$this->assertNotSame($first, $this->service->generateId('documents/avatar'));
	}

	public function testGenerateIdRequiresTheSocialUrl(): void {
		$this->withAppValues([]);

		$this->expectException(SocialAppConfigException::class);
		$this->service->generateId('/users/alice');
	}

	public function testWithRequestTimeoutBoundsTheRequestsMadeInside(): void {
		$this->withAppValues([]);
		$request = new NCRequest('/users/bob', Request::TYPE_GET);

		$returned = $this->service->withRequestTimeout(3, function () use ($request) {
			$this->service->configureRequest($request);

			return 'done';
		});

		$this->assertSame('done', $returned);
		$this->assertSame(3, $request->getTimeout());
	}

	public function testReachingAPeerCanBeBudgetedApartFromReadingItsAnswer(): void {
		// one number for both meant DNS+TCP+TLS and the peer rendering its actor
		// document had to share a deadline a slow-but-honest instance could not meet
		$this->withAppValues([]);
		$request = new NCRequest('/users/bob', Request::TYPE_GET);

		$this->service->withRequestTimeout(10, function () use ($request) {
			$this->service->configureRequest($request);
		}, 5);

		$this->assertSame(10, $request->getTimeout());
		$this->assertSame(5, $request->getConnectTimeout());
	}

	public function testAConnectBudgetIsAlsoLiftedAfterTheCall(): void {
		$this->withAppValues([]);

		$this->service->withRequestTimeout(10, fn () => null, 5);

		$after = new NCRequest('/users/bob', Request::TYPE_GET);
		$this->service->configureRequest($after);

		$this->assertSame(0, $after->getConnectTimeout(), 'no separate budget was asked for');
	}

	public function testTheTimeoutOverrideLastsOnlyForThatCall(): void {
		$this->withAppValues([]);
		$default = (new NCRequest('/users/bob', Request::TYPE_GET))->getTimeout();

		$this->service->withRequestTimeout(3, fn () => null);

		$after = new NCRequest('/users/bob', Request::TYPE_GET);
		$this->service->configureRequest($after);

		$this->assertSame($default, $after->getTimeout());
	}

	public function testTheTimeoutOverrideIsLiftedEvenWhenTheCallThrows(): void {
		$this->withAppValues([]);
		$default = (new NCRequest('/users/bob', Request::TYPE_GET))->getTimeout();

		try {
			$this->service->withRequestTimeout(3, function (): void {
				throw new \RuntimeException('boom');
			});
			$this->fail('expected the exception to surface');
		} catch (\RuntimeException $e) {
		}

		$after = new NCRequest('/users/bob', Request::TYPE_GET);
		$this->service->configureRequest($after);

		$this->assertSame($default, $after->getTimeout());
	}

	public function testConfigureRequestAddsActivityPubAcceptHeaderOnGet(): void {
		$this->withAppValues([]);
		$request = new NCRequest('/users/bob', Request::TYPE_GET);

		$this->service->configureRequest($request);

		$headers = $request->getHeaders();
		$this->assertSame(
			'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
			$headers['Accept'],
		);
		$this->assertArrayNotHasKey('Content-Type', $headers);
		$this->assertTrue($request->isVerifyPeer());
		$this->assertTrue($request->isFollowLocation());
		// Local addresses are off unless the instance opts in — see the two tests below.
		$this->assertFalse($request->isLocalAddressAllowed());
	}

	public function testConfigureRequestKeepsLocalAddressesOffByDefault(): void {
		$this->config->method('getSystemValueBool')
			->with('allow_local_remote_servers', false)->willReturn(false);
		$request = new NCRequest('/users/bob', Request::TYPE_GET);

		$this->service->configureRequest($request);

		$this->assertFalse($request->isLocalAddressAllowed());
	}

	public function testConfigureRequestAllowsLocalAddressesWhenTheInstanceOptsIn(): void {
		$this->config->method('getSystemValueBool')
			->with('allow_local_remote_servers', false)->willReturn(true);
		$request = new NCRequest('/users/bob', Request::TYPE_GET);

		$this->service->configureRequest($request);

		$this->assertTrue($request->isLocalAddressAllowed());
	}

	public function testConfigureRequestAddsContentTypeOnPost(): void {
		$this->withAppValues([]);
		$request = new NCRequest('/inbox', Request::TYPE_POST);

		$this->service->configureRequest($request);

		$headers = $request->getHeaders();
		$this->assertSame('application/activity+json', $headers['Content-Type']);
		$this->assertArrayNotHasKey('Accept', $headers);
	}

	public function testConfigureRequestSkipsJsonHeadersWhenAsked(): void {
		$this->withAppValues([]);
		$request = new NCRequest('/media/1.png', Request::TYPE_GET);
		$request->setClientOptions(['ignoreJsonHeaders' => true]);

		$this->service->configureRequest($request);

		$this->assertArrayNotHasKey('Accept', $request->getHeaders());
	}

	public function testConfigureRequestDisablesPeerVerificationForSelfSigned(): void {
		$this->withAppValues([ConfigService::SOCIAL_SELF_SIGNED => '1']);
		$request = new NCRequest('/inbox', Request::TYPE_POST);

		$this->service->configureRequest($request);

		$this->assertFalse($request->isVerifyPeer());
	}
}
