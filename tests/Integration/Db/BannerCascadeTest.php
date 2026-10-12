<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\CacheDocumentService;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A profile banner goes with the account it is on, row and files, against
 * the real database and appdata.
 *
 * The banner is the one document a local account stores for itself outside a
 * post, and deleting an account removes the documents hanging off it; this
 * pins that the banner hangs off it too.
 */
class BannerCascadeTest extends TestCase {
	private IUserManager $userManager;
	private string $handle = '';

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = Server::get(IUserManager::class);
	}

	protected function tearDown(): void {
		if ($this->handle !== '') {
			$this->userManager->get($this->handle)?->delete();
			Server::get(ActorsRequest::class)->delete($this->handle);
		}
		parent::tearDown();
	}

	private function pngFile(): string {
		$path = tempnam(sys_get_temp_dir(), 'social-banner-itest');
		$image = imagecreatetruecolor(60, 20);
		imagefilledrectangle($image, 0, 0, 59, 19, imagecolorallocate($image, 30, 90, 200));
		imagepng($image, $path);

		return $path;
	}

	public function testDeletingAUserTakesTheirBannerRowAndItsFiles(): void {
		// a deleted handle stays reserved for the retention hour
		$this->handle = 'bannercascade' . bin2hex(random_bytes(3));
		$this->userManager->createUser($this->handle, bin2hex(random_bytes(16)) . 'Aa1!');
		Server::get(AccountService::class)->createActor($this->handle, $this->handle);
		$actorId = Server::get(ActorsRequest::class)->getFromUsername($this->handle)->getId();

		$tmp = $this->pngFile();
		try {
			$image = Server::get(BannerService::class)->setFromTempFile($this->handle, $tmp);
		} finally {
			@unlink($tmp);
		}

		$documents = Server::get(CacheDocumentsRequest::class);
		$files = Server::get(CacheDocumentService::class);
		$stored = $documents->getByUrl($image->getUrl());
		$this->assertSame($actorId, $stored->getParentId(), 'the banner hangs off its account');
		$this->assertNotNull($files->cachedFileSize($stored->getLocalCopy()), 'the banner is on disk');
		$this->assertNotNull($files->cachedFileSize($stored->getResizedCopy()), 'and so is its resized copy');

		$this->userManager->get($this->handle)->delete();

		$this->assertNull($files->cachedFileSize($stored->getLocalCopy()), 'the file went with the account');
		$this->assertNull($files->cachedFileSize($stored->getResizedCopy()), 'the resized copy too');
		$this->expectException(CacheDocumentDoesNotExistException::class);
		$documents->getByUrl($image->getUrl());
	}
}
