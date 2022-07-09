<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Tests\Entitiy;

use OCA\Social\Entity\Account;
use OCA\Social\Serializer\AccountSerializer;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use Test\TestCase;

class AccountSerializerTest extends TestCase {
	public function testJsonLd(): void {
		$localDomain = "helloworld.social";
		$request = $this->createMock(IRequest::class);
		$request->expects($this->once())
			->method('getServerHost')
			->willReturn($localDomain);

		$alice = $this->createMock(IUser::class);
		$alice->expects($this->atLeastOnce())
			->method('getDisplayName')
			->willReturn('Alice Alice');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->once())
			->method('get')
			->with('alice_id')
			->willReturn($alice);

		$account = Account::newLocal();
		$account->setUserName('alice');
		$account->setUserId('alice_id');

		$accountSerializer = new AccountSerializer($request, $userManager);
		$jsonLd = $accountSerializer->toJsonLd($account);
		$this->assertSame($jsonLd['id'], 'https://' . $localDomain . '/alice');
		$this->assertSame($jsonLd['name'], 'Alice Alice');
	}
}
