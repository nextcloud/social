<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\UserMigration;

use OCP\Files\Folder;
use OCP\ITempManager;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\UserMigrationException;
use ZipArchive;

/**
 * A zip file that `SocialMigrator` can export into.
 *
 * The migrator was written for Nextcloud's whole-account export, which is an
 * app the admin has to install and a job the user cannot start for themselves.
 * The same code writes this archive: one migrator, one set of file names, so
 * an archive from the Migration page and one from `occ user:export` hold the
 * Social data in exactly the same shape and either can be read back here.
 *
 * Only what the migrator actually asks for is implemented. `copyFolder()`
 * belongs to migrators that carry files out of the user's storage; this app
 * has none, and a silent no-op there would produce an archive that says it
 * holds something it does not.
 */
class ZipExportDestination implements IExportDestination {
	/** @var array<string, int> */
	private array $versions = [];

	public function __construct(
		private ZipArchive $zip,
		private ITempManager $tempManager,
	) {
	}

	#[\Override]
	public function addFileContents(string $path, string $content): void {
		if ($this->zip->addFromString($path, $content) === false) {
			throw new UserMigrationException('could not write ' . $path . ' to the archive');
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Through a temporary file rather than through a string. `ZipArchive` will
	 * take a stream, but only one that stays open until `close()`, and the one
	 * this is handed is the caller's and is closed as soon as this returns — so
	 * the bytes are copied, a chunk at a time, to a file the zip reads at
	 * `close()` instead. What arrives here is an attachment as often as it is an
	 * outbox, and an attachment is a video: `stream_get_contents()` of one is
	 * two gigabytes of memory, which is what the streaming half of this
	 * interface exists to avoid.
	 *
	 * The temporary file has to outlive this call, so it is left to
	 * `ITempManager` to clean up at the end of the request, which is after the
	 * archive has been closed and handed over.
	 */
	#[\Override]
	public function addFileAsStream(string $path, $stream): void {
		$tmpPath = $this->tempManager->getTemporaryFile();
		if ($tmpPath === false) {
			throw new UserMigrationException('could not make a temporary file for ' . $path);
		}

		$target = fopen($tmpPath, 'w');
		if (!is_resource($target)) {
			throw new UserMigrationException('could not open a temporary file for ' . $path);
		}

		try {
			if (stream_copy_to_stream($stream, $target) === false) {
				throw new UserMigrationException('could not read the contents for ' . $path);
			}
		} finally {
			fclose($target);
		}

		if ($this->zip->addFile($tmpPath, $path) === false) {
			throw new UserMigrationException('could not write ' . $path . ' to the archive');
		}
	}

	#[\Override]
	public function copyFolder(Folder $folder, string $destinationPath, ?callable $nodeFilter = null): void {
		throw new UserMigrationException(
			'this archive carries only the app\'s own data; there is no folder to copy'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Kept until the archive is closed, and written then: the versions are the
	 * last thing the migrator reports, and a reader has to find them whether
	 * or not it knows this app.
	 */
	#[\Override]
	public function setMigratorVersions(array $versions): void {
		$this->versions = $versions;
	}

	/** @return array<string, int> what the migrators reported */
	public function getMigratorVersions(): array {
		return $this->versions;
	}

	#[\Override]
	public function close(): void {
		// nothing: the service that opened the zip closes it, because it is
		// the one that has to hand the finished file to the browser
	}
}
