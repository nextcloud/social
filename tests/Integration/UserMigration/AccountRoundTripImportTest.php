<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\UserMigration;

/**
 * The import half of `AccountRoundTripTest`, on a second server.
 *
 * It reads the bundle the export half wrote (`SOCIAL_ROUNDTRIP_BUNDLE` names
 * its directory): caches the accounts and posts the archive names, as this
 * server would have fetched them, imports the archive into `dora` and holds
 * every view against the exporting server's. Without a bundle there is
 * nothing to import, and the test is skipped — which it is in an ordinary
 * run of the suite, where the export half imports on its own server instead.
 *
 * The second server has to answer at an address of its own: an account of
 * the first one would otherwise look local here.
 */
class AccountRoundTripImportTest extends RoundTripTestCase {
	public const ENV_BUNDLE = 'SOCIAL_ROUNDTRIP_BUNDLE';

	public function testTheExportedAccountComesBackEntityByEntity(): void {
		$bundle = (string)getenv(self::ENV_BUNDLE);
		if ($bundle === '') {
			$this->markTestSkipped(self::ENV_BUNDLE . ' names no bundle: AccountRoundTripTest imports on its own server instead');
		}
		$this->assertFileExists($bundle . '/' . self::BUNDLE_VIEWS);

		$manifest = json_decode((string)file_get_contents($bundle . '/' . self::BUNDLE_VIEWS), true, 512, JSON_THROW_ON_ERROR);
		$this->assertNotSame(
			$manifest['socialUrl'], $this->socialUrl(),
			'this server answers at the address of the server that exported: give it a social_url of its own'
		);

		$this->importAndCompare($bundle);
	}
}
