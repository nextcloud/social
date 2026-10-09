<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\VideoLadderService;
use OCA\Social\Service\VideoThumbnailService;
use OCA\Social\Service\VideoTranscodeService;
use OCP\IBinaryFinder;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every ffmpeg and ffprobe this app starts is told to read local files only.
 *
 * A video somebody uploaded is a container, and a container can name other
 * inputs: an HLS playlist or a concat list pointing at `http://`, a device,
 * another file on the server. ffmpeg follows them unless
 * `-protocol_whitelist file` says not to, which makes an upload a way to
 * have the server fetch an internal URL or read a file into a video
 * somebody else can download. The binaries are replaced here by a script
 * that records its arguments, and every call is held to the option, ahead
 * of the input it governs.
 */
class FfmpegProtocolWhitelistTest extends TestCase {
	private string $log = '';
	private string $binary = '';
	private string $video = '';
	/** @var string[] */
	private array $tempFiles = [];
	/** @var string[] */
	private array $tempFolders = [];

	protected function setUp(): void {
		if (!function_exists('proc_open') || !is_executable('/bin/sh')) {
			$this->markTestSkipped('no shell to stand in for ffmpeg');
		}

		$this->log = $this->tempFile();
		$this->video = $this->tempFile();
		file_put_contents($this->video, 'not really a video');

		$this->binary = $this->tempFile();
		file_put_contents($this->binary, "#!/bin/sh\nfor arg in \"\$@\"; do printf '%s\\n' \"\$arg\" >> '" . $this->log . "'; done\necho '--end--' >> '" . $this->log . "'\necho 720\nexit 0\n");
		chmod($this->binary, 0o755);
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}
		foreach ($this->tempFolders as $folder) {
			foreach (glob($folder . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($folder);
		}
	}

	public function testThePosterAndItsDurationProbeReadLocalFilesOnly(): void {
		$service = new VideoThumbnailService($this->binaries(), $this->temp(), new NullLogger());

		$service->poster($this->video);
		$service->duration($this->video);

		$this->assertEveryCallReadsLocalFilesOnly(2);
	}

	public function testTheTranscodeAndItsCodecProbeReadLocalFilesOnly(): void {
		$service = new VideoTranscodeService($this->binaries(), $this->temp(), $this->config(), new NullLogger());

		$service->videoCodec($this->video);
		$service->convert($this->video);

		$this->assertEveryCallReadsLocalFilesOnly(2);
	}

	public function testTheLadderAndItsHeightProbeReadLocalFilesOnly(): void {
		$service = new VideoLadderService($this->binaries(), $this->temp(), $this->config(), new NullLogger());

		$service->heightOf($this->video);
		$service->encode($this->video, 360);

		$this->assertEveryCallReadsLocalFilesOnly(2);
	}

	private function assertEveryCallReadsLocalFilesOnly(int $atLeast): void {
		$calls = array_values(array_filter(array_map(
			static fn (string $call): array => array_values(array_filter(explode("\n", $call), static fn (string $arg): bool => $arg !== '')),
			explode("--end--\n", (string)file_get_contents($this->log))
		)));

		$this->assertGreaterThanOrEqual($atLeast, count($calls), 'the binaries were run');
		foreach ($calls as $argv) {
			$option = array_search('-protocol_whitelist', $argv, true);
			$this->assertNotFalse($option, 'no -protocol_whitelist in: ' . implode(' ', $argv));
			$this->assertSame('file', $argv[$option + 1] ?? null, implode(' ', $argv));

			$input = array_search('-i', $argv, true);
			$input = ($input === false) ? array_search($this->video, $argv, true) : $input;
			$this->assertNotFalse($input, 'no input in: ' . implode(' ', $argv));
			$this->assertLessThan($input, $option, 'the whitelist comes after the input it should govern: ' . implode(' ', $argv));
		}
	}

	private function binaries(): IBinaryFinder {
		$finder = $this->createStub(IBinaryFinder::class);
		$finder->method('findBinaryPath')->willReturn($this->binary);

		return $finder;
	}

	private function temp(): ITempManager {
		$temp = $this->createStub(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn (string $suffix = ''): string => $this->tempFile($suffix));
		$temp->method('getTemporaryFolder')->willReturnCallback(function (): string {
			$folder = sys_get_temp_dir() . '/social-ffmpeg-' . bin2hex(random_bytes(4));
			mkdir($folder);
			$this->tempFolders[] = $folder;

			return $folder;
		});

		return $temp;
	}

	private function config(): ConfigService {
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValue')->willReturn('');
		$config->method('getAppValueInt')->willReturn(0);

		return $config;
	}

	private function tempFile(string $suffix = ''): string {
		$path = tempnam(sys_get_temp_dir(), 'social-ffmpeg-') . $suffix;
		$this->tempFiles[] = $path;

		return $path;
	}
}
