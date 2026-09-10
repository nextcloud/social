<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\MiscService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FediverseServiceTest extends TestCase {
	private ConfigService|MockObject $configService;
	private FediverseService $service;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->service = new FediverseService($this->configService, $this->createMock(MiscService::class));
	}

	private function withAccess(string $type, array $list, string $cloudHost = 'cloud.example.com'): void {
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key) => match ($key) {
				ConfigService::SOCIAL_ACCESS_TYPE => $type,
				ConfigService::SOCIAL_ACCESS_LIST => json_encode($list),
				default => '',
			});
		$this->configService->method('getCloudHost')->willReturn($cloudHost);
	}

	public function testEmptyOriginIsNeverAuthorized(): void {
		$this->withAccess('all_but', []);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->expectExceptionMessage('Empty Origin');
		$this->service->authorized('');
	}

	public function testBlacklistModeAuthorizesUnlistedAddresses(): void {
		$this->withAccess('all_but', ['spam.example']);

		$this->assertTrue($this->service->authorized('mastodon.example'));
	}

	public function testBlacklistModeBlocksListedAddresses(): void {
		$this->withAccess('all_but', ['spam.example']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->expectExceptionMessage('Unauthorized Fediverse');
		$this->service->authorized('spam.example');
	}

	public function testWhitelistModeAuthorizesListedAddresses(): void {
		$this->withAccess('none_but', ['friend.example']);

		$this->assertTrue($this->service->authorized('friend.example'));
	}

	public function testWhitelistModeAlwaysAuthorizesTheLocalHost(): void {
		$this->withAccess('none_but', []);

		$this->assertTrue($this->service->authorized('cloud.example.com'));
	}

	public function testWhitelistModeBlocksEverythingElse(): void {
		$this->withAccess('none_but', ['friend.example']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('mastodon.example');
	}

	public function testJailedThrowsForAnEmptyWhitelist(): void {
		$this->withAccess('none_but', []);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->expectExceptionMessage('Jailed Fediverse');
		$this->service->jailed();
	}

	public function testJailedIsQuietWithAPopulatedWhitelist(): void {
		$this->withAccess('none_but', ['friend.example']);

		$this->service->jailed();
		$this->addToAssertionCount(1);
	}

	public function testJailedIsQuietInBlacklistMode(): void {
		$this->withAccess('all_but', []);

		$this->service->jailed();
		$this->addToAssertionCount(1);
	}

	public function testGetAccessTypeReadsTheConfig(): void {
		$this->withAccess('none_but', []);

		$this->assertSame('none_but', $this->service->getAccessType());
	}

	public function testSetAccessTypePersistsAKnownType(): void {
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_TYPE, 'none_but');

		$this->service->setAccessType('none_but');
	}

	public function testSetAccessTypeRejectsUnknownTypes(): void {
		$this->configService->expects($this->never())->method('setAppValue');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('invalid type');
		$this->service->setAccessType('whitelist');
	}

	public function testIsLocalComparesAgainstTheCloudHost(): void {
		$this->withAccess('all_but', []);

		$this->assertTrue($this->service->isLocal('cloud.example.com'));
		$this->assertFalse($this->service->isLocal('mastodon.example'));
	}

	public function testListedAddressesAreDecodedFromConfig(): void {
		$this->withAccess('all_but', ['a.example', 'b.example']);

		$this->assertSame(['a.example', 'b.example'], $this->service->getListedAddresses());
		$this->assertTrue($this->service->isListed('b.example'));
		$this->assertFalse($this->service->isListed('c.example'));
	}

	public function testResetAddressesStoresAnEmptyList(): void {
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_LIST, '[]');

		$this->service->resetAddresses();
	}

	public function testAddAddressAppendsAndPersists(): void {
		$this->withAccess('all_but', ['a.example']);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_LIST, '["a.example","b.example"]');

		$this->service->addAddress('b.example');
	}

	public function testAddAddressIgnoresDuplicates(): void {
		$this->withAccess('all_but', ['a.example']);
		$this->configService->expects($this->never())->method('setAppValue');

		$this->service->addAddress('a.example');
	}

	public function testRemoveAddressPersistsTheRemainingList(): void {
		$this->withAccess('all_but', ['a.example', 'b.example']);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_LIST, '["a.example"]');

		$this->service->removeAddress('b.example');
	}

	public function testKnownAddressesIsEmpty(): void {
		$this->assertSame([], $this->service->getKnownAddresses());
	}

	public function testListedAddressesToleratesAnUnreadableStoredValue(): void {
		$this->configService->method('getAppValue')->willReturn('');

		$this->assertSame([], $this->service->getListedAddresses());
		$this->assertFalse($this->service->isListed('spam.example'));
	}

	public function testIsListedIgnoresHostnameCase(): void {
		$this->withAccess('all_but', ['spam.example']);

		$this->assertTrue($this->service->isListed('SPAM.Example'));
	}

	/**
	 * @dataProvider provideSubdomainsOfAListedDomain
	 */
	public function testAListedDomainCoversWhatIsUnderIt(string $address): void {
		// a suspension that only matched the exact string lasted as long as it
		// took to point another wildcard record at the same host
		$this->withAccess('all_but', ['evil.test']);

		$this->assertTrue($this->service->isListed($address), $address . ' should be covered');
	}

	public function provideSubdomainsOfAListedDomain(): iterable {
		yield 'the domain itself' => ['evil.test'];
		yield 'www' => ['www.evil.test'];
		yield 'a deeper label' => ['a.b.evil.test'];
		yield 'the absolute form' => ['evil.test.'];
		yield 'absolute subdomain' => ['www.evil.test.'];
		yield 'mixed case' => ['WWW.Evil.TEST'];
		yield 'padded' => [' www.evil.test '];
	}

	/**
	 * @dataProvider provideNamesThatMerelyLookSimilar
	 */
	public function testASuffixMatchStopsAtALabelBoundary(string $address): void {
		$this->withAccess('all_but', ['evil.test']);

		$this->assertFalse($this->service->isListed($address), $address . ' is a different name');
	}

	public function provideNamesThatMerelyLookSimilar(): iterable {
		yield 'longer label' => ['notevil.test'];
		yield 'different tld' => ['evil.testing'];
		yield 'the parent' => ['test'];
		yield 'unrelated' => ['good.example'];
		yield 'empty' => [''];
	}

	public function testBlacklistModeBlocksASubdomainOfABlockedDomain(): void {
		$this->withAccess('all_but', ['evil.test']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('a.evil.test');
	}

	public function testAWhitelistDoesNotWidenToSubdomains(): void {
		// the other direction: whoever runs the parent domain is not asked
		// before a subdomain appears, so an allow list stays exact
		$this->withAccess('none_but', ['friend.example']);

		$this->assertTrue($this->service->authorized('friend.example'));

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('impostor.friend.example');
	}

	public function testAddAddressTreatsAnAbsoluteNameAsTheSameEntry(): void {
		$this->withAccess('all_but', ['a.example']);
		$this->configService->expects($this->never())->method('setAppValue');

		$this->service->addAddress('A.Example.');
	}

	public function testAddAddressStillAddsASubdomainOfAListedDomain(): void {
		// covered by isListed(), but an admin may still want it written down
		$this->withAccess('all_but', ['a.example']);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(ConfigService::SOCIAL_ACCESS_LIST, '["a.example","sub.a.example"]');

		$this->service->addAddress('sub.a.example');
	}

	public function testBlacklistModeBlocksListedAddressesRegardlessOfCase(): void {
		$this->withAccess('all_but', ['spam.example']);

		$this->expectException(UnauthorizedFediverseException::class);
		$this->service->authorized('Spam.EXAMPLE');
	}

	public function testRemoveAddressKeepsTheListAList(): void {
		$this->withAccess('all_but', ['a.example', 'b.example', 'c.example']);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(
				ConfigService::SOCIAL_ACCESS_LIST,
				$this->callback(fn (string $json): bool => $json === json_encode(['a.example', 'c.example']))
			);

		$this->service->removeAddress('B.example');
	}
}
