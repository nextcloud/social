<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OC\Files\SetupManager;
use OCA\Social\Controller\MediaApiController;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Server;

/**
 * `POST /api/v1/media/from-file` attaches one of the user's own files, so it
 * takes the Nextcloud session, never a Social token (docs/API.md, "Which
 * credentials a route takes"): the web app's, with its CSRF token, or a
 * client's Nextcloud app password over Basic with `OCS-APIRequest: true`.
 * A bearer token is refused with a 403 whatever it was granted — the test
 * token holds every scope there is.
 */
class MediaFromFileSessionTest extends ApiTestCase {
	private const FILE = 'apictr-from-file.png';

	protected function tearDown(): void {
		foreach ($this->accountNames() as $name) {
			try {
				Server::get(IRootFolder::class)->getUserFolder(self::PREFIX . $name)->get(self::FILE)->delete();
			} catch (\Throwable) {
			}
		}
		parent::tearDown();
	}

	/**
	 * A real picture in the account's own files.
	 *
	 * The account may have been deleted and made again since this process
	 * first set its files up, which leaves the old home mounted: its files
	 * are set up afresh before the picture is written.
	 */
	private function pictureIn(string $name): void {
		$this->account($name);
		$user = Server::get(IUserManager::class)->get(self::PREFIX . $name);
		$this->assertNotNull($user);
		$setup = Server::get(SetupManager::class);
		$setup->tearDown();
		$setup->setupForUser($user);

		$image = imagecreatetruecolor(40, 20);
		ob_start();
		imagepng($image);
		$png = (string)ob_get_clean();

		Server::get(IRootFolder::class)->getUserFolder(self::PREFIX . $name)->newFile(self::FILE, $png);
	}

	/** @param array<string, string> $headers */
	private function attach(string $as, bool $webSession, bool $appPassword = false, array $headers = []): ApiResponse {
		return $this->call(
			MediaApiController::class, 'mediaFromFile', [], ['path' => '/' . self::FILE], $as, 'POST',
			webSession: $webSession, appPassword: $appPassword, headers: $headers
		);
	}

	public function testTheWebAppAttachesAFileThroughItsSession(): void {
		$this->pictureIn('alice');

		$response = $this->attach('alice', true);

		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertSame('image', $response->body->type);
	}

	public function testAClientAttachesAFileWithANextcloudAppPassword(): void {
		$this->pictureIn('alice');

		$response = $this->attach('alice', false, true);

		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertSame('image', $response->body->type);
	}

	/**
	 * Without `OCS-APIRequest: true` Nextcloud holds the request to the CSRF
	 * check, which a client cannot pass, and it is answered as nobody's.
	 */
	public function testAnAppPasswordWithoutTheOcsHeaderIsNotSignedIn(): void {
		$this->pictureIn('alice');

		$response = $this->attach('alice', false, true, ['OCS-APIRequest' => '']);

		$this->assertSame(401, $response->status, json_encode($response->body) ?: '');
	}

	/** Refused before any file is looked at: the path need not name one. */
	public function testABearerTokenIsRefusedWhateverItsScopes(): void {
		$response = $this->attach('alice', false);

		$this->assertSame(403, $response->status, json_encode($response->body) ?: '');
		$this->assertSame('this route is only for the Aloha Social web app, signed in to Nextcloud', $response->body->error);
	}
}
