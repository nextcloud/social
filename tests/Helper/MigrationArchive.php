<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use LogicException;
use OCP\Files\Folder;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\UserMigrationException;

/**
 * An export archive held in memory: what a migrator wrote, and what it can read
 * back. Both halves of the framework contract in one class, so a test can hand
 * an export straight to an import the way an account transfer does.
 *
 * `copyFolder()`/`copyToFolder()` throw: this app puts nothing from the user's
 * files in the archive, and a migrator that started to would fail here rather
 * than pass unnoticed.
 */
class MigrationArchive implements IExportDestination, IImportSource {
	/** @var array<string, string> path => content */
	private array $files = [];

	/** @var array<string, int> */
	private array $versions = [];

	public function __construct(
		private string $originalUid = 'alice',
	) {
	}

	/** @return array<string, string> */
	public function files(): array {
		return $this->files;
	}

	/** @return string[] the paths in the archive, sorted */
	public function paths(): array {
		$paths = array_keys($this->files);
		sort($paths);

		return $paths;
	}

	public function contents(string $path): string {
		return $this->files[$path] ?? '';
	}

	/** Drops a file, to build the partial archive a real one so often is. */
	public function remove(string $path): void {
		unset($this->files[$path]);
	}

	public function put(string $path, string $content): void {
		$this->files[$path] = $content;
	}

	public function setVersion(string $migrator, int $version): void {
		$this->versions[$migrator] = $version;
	}

	#[\Override]
	public function addFileContents(string $path, string $content): void {
		$this->files[$path] = $content;
	}

	/**
	 * @param resource $stream
	 */
	#[\Override]
	public function addFileAsStream(string $path, $stream): void {
		$this->files[$path] = (string)stream_get_contents($stream);
	}

	#[\Override]
	public function copyFolder(Folder $folder, string $destinationPath, ?callable $nodeFilter = null): void {
		throw new LogicException('the Social migrator copies no folder');
	}

	#[\Override]
	public function setMigratorVersions(array $versions): void {
		$this->versions = $versions;
	}

	#[\Override]
	public function close(): void {
	}

	#[\Override]
	public function getFileContents(string $path): string {
		if (!isset($this->files[$path])) {
			throw new UserMigrationException('no such file in the archive: ' . $path);
		}

		return $this->files[$path];
	}

	/**
	 * @return resource
	 */
	#[\Override]
	public function getFileAsStream(string $path) {
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, $this->getFileContents($path));
		rewind($stream);

		return $stream;
	}

	#[\Override]
	public function getFolderListing(string $path): array {
		$path = rtrim($path, '/') . '/';
		$listing = [];
		foreach (array_keys($this->files) as $file) {
			if (str_starts_with($file, $path)) {
				$listing[] = substr($file, strlen($path));
			}
		}

		return $listing;
	}

	#[\Override]
	public function pathExists(string $path): bool {
		return isset($this->files[$path]) || $this->getFolderListing($path) !== [];
	}

	#[\Override]
	public function copyToFolder(Folder $destination, string $sourcePath): void {
		throw new LogicException('the Social migrator copies nothing into the user files');
	}

	#[\Override]
	public function getMigratorVersions(): array {
		return $this->versions;
	}

	#[\Override]
	public function getMigratorVersion(string $migrator): ?int {
		return $this->versions[$migrator] ?? null;
	}

	#[\Override]
	public function getOriginalUid(): string {
		return $this->originalUid;
	}
}
