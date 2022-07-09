<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Tests\Service\ActivityPub;

use OCA\Social\Service\ActivityPub\TagManager;
use OCP\IRequest;
use Test\TestCase;

class TagManagerTest extends TestCase {

	public function localUriProvider(): array {
		return [
			[null, 'helloworld.com', false],
			['https://helloworld.com', 'helloworld.com', true],
			['https://helloworld.com/rehie', 'helloworld.com', true],
			['https://helloworld.com:3000/rehie', 'helloworld.com', false],
			['https://helloworld1.com', 'helloworld.com', false],
			['https://floss.social/@carlschwan', 'helloworld.com', false],
		];
	}

	/**
	 * @dataProvider localUriProvider
	 */
	public function testIsLocalUri(?string $url, string $localDomain, bool $result): void {
		$request = $this->createMock(IRequest::class);
		$request->expects($this->any())
			->method('getServerHost')
			->willReturn($localDomain);
		$tagManager = new TagManager($request);
		$this->assertSame($tagManager->isLocalUri($url), $result);
	}
}
