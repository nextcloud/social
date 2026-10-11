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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * No file ffmpeg writes keeps the container metadata of the one it read.
 *
 * A phone writes where a video was recorded, and on what, into the
 * container, and ffmpeg copies a container's metadata into its output unless
 * told not to. Every ffmpeg that writes a file is held to
 * `-map_metadata -1 -map_chapters -1`, after the input so that it applies to
 * the output; the remux an upload is stored through copies the streams
 * rather than re-encoding them. The binary is replaced by a script that
 * records its arguments, and, where a real ffmpeg is installed, a generated
 * MP4 carrying a location is rewritten and probed.
 */
class FfmpegWithoutMetadataTest extends TestCase {
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
		// writes something to the last argument, the output, so a call that
		// checks its result sees one
		file_put_contents($this->binary, "#!/bin/sh\nfor arg in \"\$@\"; do printf '%s\\n' \"\$arg\" >> '" . $this->log . "'; last=\"\$arg\"; done\necho '--end--' >> '" . $this->log . "'\ncase \"\$last\" in /*) echo out > \"\$last\";; esac\necho 720\nexit 0\n");
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

	public function testTheTranscodeKeepsNoMetadata(): void {
		$this->transcoder($this->binaries())->convert($this->video);

		$this->assertTheWriterKeepsNoMetadata();
	}

	public function testTheLadderKeepsNoMetadata(): void {
		(new VideoLadderService($this->binaries(), $this->temp(), $this->config(), new NullLogger()))->encode($this->video, 360);

		$this->assertTheWriterKeepsNoMetadata();
	}

	public function testThePosterKeepsNoMetadata(): void {
		(new VideoThumbnailService($this->binaries(), $this->temp(), new NullLogger()))->poster($this->video);

		$this->assertTheWriterKeepsNoMetadata();
	}

	/** @return array<string, array{string, string, bool}> */
	public static function remuxedTypes(): array {
		return [
			'an MP4' => ['video/mp4', 'mp4', true],
			'a QuickTime movie' => ['video/quicktime', 'mov', true],
			'a WebM' => ['video/webm', 'webm', false],
			'an MP3' => ['audio/mpeg', 'mp3', false],
			'an M4A' => ['audio/mp4', 'mp4', true],
			'an Ogg' => ['audio/ogg', 'ogg', false],
			'a FLAC' => ['audio/flac', 'flac', false],
			'a WAV' => ['audio/x-wav', 'wav', false],
		];
	}

	/**
	 * An upload's metadata comes off by copying its streams into a fresh
	 * container of the same kind: nothing is re-encoded.
	 */
	#[DataProvider('remuxedTypes')]
	public function testAnUploadIsRemuxedWithoutReEncoding(string $mime, string $format, bool $faststart): void {
		$rewritten = $this->transcoder($this->binaries())->withoutMetadata($this->video, $mime);

		$this->assertNotNull($rewritten);
		$this->tempFiles[] = $rewritten;
		$argv = $this->calls()[0];
		$this->assertTheWriterKeepsNoMetadata();
		$this->assertSame('copy', $argv[array_search('-c', $argv, true) + 1]);
		$this->assertNotContains('libx264', $argv);
		$this->assertSame($format, $argv[array_search('-f', $argv, true) + 1]);
		$this->assertSame($faststart, in_array('+faststart', $argv, true));
		$this->assertSame($rewritten, end($argv));
	}

	/** A file that is not a video or a sound file is not handed to ffmpeg at all. */
	#[DataProvider('notRemuxed')]
	public function testOtherFilesAreNotRewritten(string $mime): void {
		$this->assertNull($this->transcoder($this->binaries())->withoutMetadata($this->video, $mime));
		$this->assertSame([], $this->calls());
	}

	/** @return array<string, array{string}> */
	public static function notRemuxed(): array {
		return ['a PDF' => ['application/pdf'], 'a picture' => ['image/png'], 'an unknown video type' => ['video/x-flv']];
	}

	public function testWithoutFfmpegNothingIsRewritten(): void {
		$finder = $this->createStub(IBinaryFinder::class);
		$finder->method('findBinaryPath')->willReturn(false);

		$this->assertNull($this->transcoder($finder)->withoutMetadata($this->video, 'video/mp4'));
	}

	/**
	 * The real thing: a short H.264 MP4 stating where it was recorded comes
	 * back with its video untouched and no location, title or creation time.
	 */
	public function testARealMp4LosesItsLocationAndKeepsItsVideo(): void {
		$ffmpeg = $this->installed('ffmpeg');
		$ffprobe = $this->installed('ffprobe');
		$source = $this->tempFile('.mp4');
		exec(escapeshellarg($ffmpeg) . ' -v error -nostdin -f lavfi -i testsrc=duration=1:size=64x64:rate=5'
			. ' -c:v libx264 -pix_fmt yuv420p'
			. ' -metadata location=+52.5200+013.4050/ -metadata title=Holiday -metadata creation_time=2026-05-01T10:00:00Z'
			. ' -y ' . escapeshellarg($source) . ' 2>&1', $out, $status);
		if ($status !== 0) {
			$this->markTestSkipped('this ffmpeg cannot write an H.264 MP4: ' . implode(' ', $out));
		}
		$this->assertStringContainsString('52.5200', $this->formatTags($ffprobe, $source), 'the fixture states a location');

		$finder = $this->createStub(IBinaryFinder::class);
		$finder->method('findBinaryPath')->willReturnCallback(fn (string $name) => ($name === 'ffmpeg') ? $ffmpeg : $ffprobe);
		$transcoder = $this->transcoder($finder);
		$rewritten = $transcoder->withoutMetadata($source, 'video/mp4');

		$this->assertNotNull($rewritten);
		$this->tempFiles[] = $rewritten;
		$tags = $this->formatTags($ffprobe, $rewritten);
		foreach (['52.5200', 'location', 'Holiday', '2026-05-01'] as $needle) {
			$this->assertStringNotContainsStringIgnoringCase($needle, $tags);
		}
		$this->assertSame('h264', $transcoder->videoCodec($rewritten));
	}

	private function assertTheWriterKeepsNoMetadata(): void {
		$calls = array_values(array_filter($this->calls(), fn (array $argv): bool => in_array('-i', $argv, true)));
		$this->assertNotEmpty($calls, 'ffmpeg was run');

		foreach ($calls as $argv) {
			$line = implode(' ', $argv);
			$input = array_search('-i', $argv, true);
			foreach (['-map_metadata', '-map_chapters'] as $option) {
				$at = array_search($option, $argv, true);
				$this->assertNotFalse($at, 'no ' . $option . ' in: ' . $line);
				$this->assertSame('-1', $argv[$at + 1] ?? null, $line);
				$this->assertGreaterThan($input, $at, $option . ' has to follow the input to apply to the output: ' . $line);
			}
		}
	}

	/** @return list<list<string>> the argv of every run of the stand-in binary */
	private function calls(): array {
		return array_values(array_filter(array_map(
			static fn (string $call): array => array_values(array_filter(explode("\n", $call), static fn (string $arg): bool => $arg !== '')),
			explode("--end--\n", (string)@file_get_contents($this->log))
		)));
	}

	private function formatTags(string $ffprobe, string $path): string {
		exec(escapeshellarg($ffprobe) . ' -v error -show_entries format_tags:stream_tags -of json ' . escapeshellarg($path), $out);

		return implode("\n", $out);
	}

	private function installed(string $name): string {
		$path = trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
		if ($path === '' || !is_executable($path)) {
			$this->markTestSkipped($name . ' is not installed');
		}

		return $path;
	}

	private function transcoder(IBinaryFinder $finder): VideoTranscodeService {
		return new VideoTranscodeService($finder, $this->temp(), $this->config(), new NullLogger());
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
		$base = (string)tempnam(sys_get_temp_dir(), 'social-ffmpeg-');
		$path = $base . $suffix;
		array_push($this->tempFiles, $base, $path);

		return $path;
	}
}
