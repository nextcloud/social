<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\UserMigration;

use OCA\Social\Tools\ZipStreamWriter;
use OCP\Files\Folder;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\UserMigrationException;

/**
 * A zip file that goes out while it is written, for a download that
 * starts before the last file of it has been read: each file is copied
 * into the archive as it is handed over, with no temporary copy.
 *
 * Like `ZipExportDestination`, only what the archive's writers ask for is
 * implemented.
 */
class ZipStreamDestination implements IExportDestination {
	public function __construct(
		private ZipStreamWriter $zip,
	) {
	}

	#[\Override]
	public function addFileContents(string $path, string $content): void {
		$this->zip->addPieces($path, [$content], strlen($content));
	}

	#[\Override]
	public function addFileAsStream(string $path, $stream): void {
		$this->zip->addStream($path, $stream);
	}

	/**
	 * A file whose bytes are made as they are written, such as a
	 * repository's CAR, which is then never whole anywhere.
	 *
	 * @param iterable<string> $pieces
	 */
	public function addFileFromPieces(string $path, iterable $pieces): void {
		$this->zip->addPieces($path, $pieces);
	}

	#[\Override]
	public function copyFolder(Folder $folder, string $destinationPath, ?callable $nodeFilter = null): void {
		throw new UserMigrationException('this archive carries only the app\'s own data; there is no folder to copy');
	}

	#[\Override]
	public function setMigratorVersions(array $versions): void {
		// nothing: the archive this writes has no migrator's manifest
	}

	/** Writes the end of the archive; nothing can be added after. */
	#[\Override]
	public function close(): void {
		$this->zip->finish();
	}
}
