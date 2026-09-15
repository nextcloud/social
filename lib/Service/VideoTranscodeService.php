<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCP\IBinaryFinder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turning a video into the one format the rest of the network will play.
 *
 * This app has never re-encoded anything, deliberately: a video is stored as
 * it was uploaded, which is the honest thing to do with somebody's file. The
 * cost of that is not theoretical. **Pixelfed's default `media_types` accepts
 * `video/mp4` and nothing else**, so every `video/quicktime` posted from here —
 * which is to say every video straight off an iPhone — is dropped by its
 * `verifyAttachments()` without a word to anybody. Safari will not play WebM,
 * and a 4K `.mov` goes out at whatever the camera wrote.
 *
 * So: H.264 in an MP4, AAC audio, bounded to a height an administrator sets,
 * with `+faststart` so the index is at the front and a browser can begin
 * playing before the file has arrived.
 *
 * Three things this is careful about.
 *
 * **It is off unless an administrator turns it on.** Re-encoding is lossy and
 * it is somebody's file; a server that silently replaced every upload with a
 * worse copy would be doing something nobody asked for.
 *
 * **It never runs during a request.** Transcoding a video is minutes, not the
 * seconds a poster frame takes, so the upload finishes as it always did and a
 * background job does the work afterwards. A video that has not been converted
 * yet is still there and still plays; it simply has not travelled well yet.
 *
 * **It refuses anything it cannot bound.** ffmpeg reads protocols and playlists
 * given the chance, so the whitelist is `file`, and a deadline kills a decoder
 * that a crafted file has made spin.
 */
class VideoTranscodeService {
	/** What everything on the network can play, and the only thing Pixelfed accepts. */
	public const TARGET_TYPE = 'video/mp4';

	/**
	 * What is worth converting.
	 *
	 * `video/mp4` is not among them: it is already the target, and a
	 * re-encode of it would be a second generation of loss for nothing. The
	 * rest are the formats this app accepts on upload.
	 */
	public const CONVERTIBLE = [
		'video/quicktime',
		'video/webm',
		'video/x-matroska',
		'video/ogg',
		'video/x-msvideo',
		'video/mpeg',
	];

	/** The tallest a converted video is written, unless an administrator says otherwise. */
	public const DEFAULT_MAX_HEIGHT = 1080;

	/**
	 * How long ffmpeg gets, per video.
	 *
	 * Generous, because this is the whole point of the job running in the
	 * background rather than in a request — but finite, because a file that
	 * makes a decoder spin must not hold a cron worker for ever.
	 */
	private const TIMEOUT_SECONDS = 900;

	/**
	 * How fast to encode. `veryfast` is the knee of the curve for this kind of
	 * material: several times quicker than `medium` for a file a few per cent
	 * larger, which on a server converting other people's holiday videos is
	 * the right side of the trade.
	 */
	private const PRESET = 'veryfast';

	/** Constant Rate Factor. 23 is ffmpeg's default and visually transparent enough here. */
	private const CRF = '23';

	public function __construct(
		private IBinaryFinder $binaryFinder,
		private ITempManager $tempManager,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/** Whether this server could convert anything at all. */
	public function isAvailable(): bool {
		return $this->ffmpeg() !== null;
	}

	/** Whether an administrator has asked for it, and it is possible. */
	public function isEnabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_VIDEO_TRANSCODE)
			&& $this->isAvailable();
	}

	/** Whether a file of this type is worth converting. */
	public function shouldConvert(string $mimeType): bool {
		return in_array(strtolower($mimeType), self::CONVERTIBLE, true);
	}

	/** The tallest a converted video is written. */
	public function maxHeight(): int {
		$height = $this->configService->getAppValueInt(ConfigService::SOCIAL_VIDEO_MAX_HEIGHT);

		return ($height > 0) ? $height : self::DEFAULT_MAX_HEIGHT;
	}

	/**
	 * Converts one video, and says where the result is.
	 *
	 * The source is left alone: the caller decides whether to keep it, and
	 * doing that here would mean this could destroy somebody's file on a path
	 * that had not finished checking the result.
	 *
	 * @param string $path a readable path to the video
	 *
	 * @return string|null the path of the converted file, or null when there
	 *                     is no ffmpeg, the source could not be read, or the
	 *                     conversion produced nothing
	 */
	public function convert(string $path): ?string {
		$ffmpeg = $this->ffmpeg();
		if ($ffmpeg === null || !is_readable($path)) {
			return null;
		}

		$target = $this->tempManager->getTemporaryFile('.mp4');
		if ($target === false) {
			return null;
		}

		$height = $this->maxHeight();
		$ran = $this->run([
			$ffmpeg,
			'-nostdin',
			// a file somebody uploaded is not a playlist, a device or a URL,
			// and ffmpeg will read all three if a container asks it to
			'-protocol_whitelist', 'file',
			'-i', $path,
			// only ever smaller: a 480p video scaled up to 1080 is a bigger
			// file of the same picture
			'-vf', 'scale=-2:\'min(' . $height . ',ih)\'',
			'-c:v', 'libx264',
			// what a phone, a browser and Pixelfed all decode. `high` and
			// yuv420p together are the combination that plays everywhere,
			// including on hardware decoders that refuse 4:2:2
			'-profile:v', 'high',
			'-pix_fmt', 'yuv420p',
			'-preset', self::PRESET,
			'-crf', self::CRF,
			'-c:a', 'aac',
			'-b:a', '128k',
			// a video with no audio track at all is not an error
			'-af', 'anull',
			// the index at the front, so a browser can start playing before
			// the whole file has arrived
			'-movflags', '+faststart',
			'-y', $target,
		]);

		if (!$ran || !is_file($target) || filesize($target) < 1) {
			@unlink($target);

			return null;
		}

		return $target;
	}

	private function ffmpeg(): ?string {
		$path = $this->binaryFinder->findBinaryPath('ffmpeg');

		return ($path === false) ? null : $path;
	}

	/**
	 * Runs ffmpeg with a deadline.
	 *
	 * @param string[] $command
	 *
	 * @return bool whether it ran to completion rather than being killed
	 */
	private function run(array $command): bool {
		try {
			$process = @proc_open(
				array_map('strval', $command),
				[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
				$pipes
			);
		} catch (Throwable $e) {
			$this->logger->debug('could not run ffmpeg', ['exception' => $e]);

			return false;
		}

		if (!is_resource($process)) {
			return false;
		}

		$deadline = time() + self::TIMEOUT_SECONDS;
		foreach ($pipes as $pipe) {
			stream_set_blocking($pipe, false);
		}

		$status = ['running' => true, 'exitcode' => -1];
		do {
			$status = proc_get_status($process);
			if (!$status['running']) {
				break;
			}

			// the pipes are drained as it goes: ffmpeg writes progress to
			// stderr, and a full pipe is a process that stops rather than one
			// that finishes
			foreach ($pipes as $pipe) {
				@stream_get_contents($pipe);
			}

			usleep(200000);
		} while (time() < $deadline);

		$killed = $status['running'];
		if ($killed) {
			proc_terminate($process, 9);
			$this->logger->warning('a transcode timed out and was killed', [
				'seconds' => self::TIMEOUT_SECONDS,
			]);
		}

		foreach ($pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		proc_close($process);

		return !$killed && $status['exitcode'] === 0;
	}
}
