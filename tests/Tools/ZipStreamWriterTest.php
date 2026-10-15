<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools;

use DateTimeImmutable;
use Generator;
use LogicException;
use OCA\Social\Tools\ZipStreamWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * A zip written front to back to a sink: what it writes opens with
 * PHP's zip reader and passes `unzip -t`, whether a file's size was
 * known before (classic sizes) or not (ZIP64 sizes), and whether the
 * archive needs ZIP64 end records or not.
 */
class ZipStreamWriterTest extends TestCase {
	private string $path = '';
	/** @var resource|null */
	private $file = null;

	protected function setUp(): void {
		$this->path = (string)tempnam(sys_get_temp_dir(), 'zip-stream-test');
		$this->file = fopen($this->path, 'wb');
	}

	protected function tearDown(): void {
		if (is_resource($this->file)) {
			fclose($this->file);
		}
		if (is_file($this->path)) {
			unlink($this->path);
		}
	}

	private function writer(): ZipStreamWriter {
		$file = $this->file;

		return new ZipStreamWriter(static function (string $bytes) use ($file): void {
			fwrite($file, $bytes);
		}, new DateTimeImmutable('2026-10-15 13:45:58'));
	}

	/**
	 * @param bool $strict whether libzip checks every local header against the central directory: it reads
	 *                     the data descriptors of an archive with ZIP64 end records as eight-byte ones, which
	 *                     they are only for a file of 4 GB or more
	 */
	private function open(bool $strict = true): ZipArchive {
		fclose($this->file);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($this->path, ZipArchive::RDONLY | ($strict ? ZipArchive::CHECKCONS : 0)));

		return $zip;
	}

	private function assertUnzipTestsIt(): void {
		if (!is_executable('/usr/bin/unzip')) {
			return;
		}
		exec('/usr/bin/unzip -tqq ' . escapeshellarg($this->path) . ' 2>&1', $out, $status);
		$this->assertSame(0, $status, implode("\n", $out));
	}

	/** Where there is macOS's `ditto`, which reads a zip front to back as Archive Utility does. */
	private function assertDittoExtractsIt(): void {
		if (!is_executable('/usr/bin/ditto')) {
			return;
		}
		$dir = $this->path . '.d';
		exec('/usr/bin/ditto -x -k ' . escapeshellarg($this->path) . ' ' . escapeshellarg($dir) . ' 2>&1', $out, $status);
		exec('rm -rf ' . escapeshellarg($dir));
		$this->assertSame(0, $status, implode("\n", $out));
	}

	public function testEveryKindOfFileReadsBackAsItWasWritten(): void {
		$big = random_bytes(3 * 1024 * 1024 + 17);
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $big);
		rewind($stream);
		$zip = $this->writer();

		$zip->addPieces('empty.txt', [], 0);
		$zip->addPieces('known.txt', ['hello ', 'world'], 11);
		$zip->addStream('blobs/big', $stream);
		$zip->addPieces('unknown/repo.car', (static function (): Generator {
			yield 'CAR ';
			yield 'pieces';
		})());
		$zip->addPieces('ünïcødé.json', ['{}'], 2);
		$zip->finish();
		fclose($stream);

		$archive = $this->open();
		$this->assertSame(5, $archive->numFiles);
		$this->assertSame('', $archive->getFromName('empty.txt'));
		$this->assertSame('hello world', $archive->getFromName('known.txt'));
		$this->assertSame($big, $archive->getFromName('blobs/big'));
		$this->assertSame('CAR pieces', $archive->getFromName('unknown/repo.car'));
		$this->assertSame('{}', $archive->getFromName('ünïcødé.json'));
		$stat = $archive->statName('blobs/big');
		$this->assertSame([strlen($big), ZipArchive::CM_STORE, (int)hexdec(hash('crc32b', $big))], [$stat['size'], $stat['comp_method'], $stat['crc']]);
		// libzip reads the time in the system's zone, which shifts whole hours only
		$this->assertSame('45:58', gmdate('i:s', $stat['mtime']));
		$archive->close();
		$this->assertUnzipTestsIt();
		$this->assertDittoExtractsIt();
	}

	/** A file said to be 4 GB or more gets ZIP64 sizes, in its header and its data descriptor. */
	public function testAFileOf4GbOrMoreGetsZip64Sizes(): void {
		$zip = $this->writer();
		$zip->addPieces('small', ['small'], 5);
		$zip->addPieces('large', ['large'], 0x100000000);
		$zip->finish();

		$archive = $this->open();
		$this->assertSame(['small', 'large'], [$archive->getFromName('small'), $archive->getFromName('large')]);
		$archive->close();
		$this->assertUnzipTestsIt();
		$this->assertStringContainsString(pack('vvPP', 0x0001, 16, 0, 0), (string)file_get_contents($this->path));
	}

	/** More files than the classic end record can count: the ZIP64 end records say how many. */
	public function testMoreThan65535FilesGetZip64EndRecords(): void {
		$zip = $this->writer();
		for ($i = 0; $i < 65536; $i++) {
			$zip->addPieces('f' . $i, [(string)$i], strlen((string)$i));
		}
		$zip->finish();

		$archive = $this->open(false);
		$this->assertSame(65536, $archive->numFiles);
		$this->assertSame('65535', $archive->getFromName('f65535'));
		$archive->close();
		$this->assertUnzipTestsIt();
	}

	/** Bytes go out while a file is still being read, not when the archive is finished. */
	public function testTheBytesGoOutWhileTheFileIsRead(): void {
		$sent = 0;
		$zip = new ZipStreamWriter(static function (string $bytes) use (&$sent): void {
			$sent += strlen($bytes);
		}, new DateTimeImmutable('2026-10-15'));
		$seen = [];
		$zip->addPieces('video', (static function () use (&$sent, &$seen): Generator {
			for ($i = 0; $i < 4; $i++) {
				$seen[] = $sent;
				yield str_repeat('v', 100 * 1024);
			}
		})());

		$this->assertSame(0, $seen[0]);
		$this->assertGreaterThan(100 * 1024, $seen[2], 'the first pieces were sent before the third was read');
	}

	public function testNothingIsAddedAfterTheEnd(): void {
		$zip = $this->writer();
		$zip->finish();

		$this->expectException(LogicException::class);
		$zip->addPieces('late', ['x'], 1);
	}

	public function testAnEmptyArchiveIsAValidOne(): void {
		$this->writer()->finish();

		$this->assertSame(0, $this->open()->numFiles);
	}
}
