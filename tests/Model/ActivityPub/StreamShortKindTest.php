<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\MediaAttachment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What goes into `social_stream.short_kind`: the one rule for what a short
 * video is, which `only_short` filters on and `/api/v2/config` states.
 *
 * A short is a post whose only attachment is a video of at most
 * `SHORT_MAX_SECONDS`, no wider than `SHORT_MAX_ASPECT`, both read from the
 * attachment's meta. The thresholds are inclusive, and a video whose meta does
 * not state one of the two is not a short.
 */
class StreamShortKindTest extends TestCase {
	/** What `Version1000Date20261012500100` declares. */
	private const COLUMN_WIDTH = 7;

	/**
	 * @param array<string, mixed>|null $meta
	 * @return array<string, mixed>
	 */
	private static function video(?array $meta, string $type = 'video'): array {
		return ['id' => '1', 'type' => $type, 'url' => 'https://cloud.example.org/media/1', 'meta' => $meta];
	}

	/** @return array<string, mixed> meta the way a probed upload states it */
	private static function probed(float|int $duration, int $width, int $height): array {
		return [
			'duration' => $duration,
			'original' => ['width' => $width, 'height' => $height, 'aspect' => $width / $height],
		];
	}

	/** @param list<array<string, mixed>> $attachments */
	private function kindOf(array $attachments): string {
		return Stream::shortKindOf((string)json_encode($attachments));
	}

	/** @return array<string, array{list<array<string, mixed>>}> */
	public static function shorts(): array {
		return [
			'an upright phone video' => [[self::video(self::probed(30, 1080, 1920))]],
			'exactly the longest a short may run' => [[self::video(self::probed(Stream::SHORT_MAX_SECONDS, 1080, 1920))]],
			'a square video, exactly the widest a short may be' => [[self::video(self::probed(15, 1080, 1080))]],
			'one second long' => [[self::video(self::probed(1, 720, 1280))]],
			'the size at the top level, as Mastodon states it' => [[self::video(['duration' => 12.5, 'width' => 720, 'height' => 1280])]],
			'the duration under original' => [[self::video(['original' => ['width' => 720, 'height' => 1280, 'duration' => 59.9]])]],
			'only an aspect, no size' => [[self::video(['duration' => 20, 'aspect' => 0.5625])]],
			'numbers sent as strings' => [[self::video(['duration' => '42', 'original' => ['width' => '1080', 'height' => '1920']])]],
		];
	}

	/** @param list<array<string, mixed>> $attachments */
	#[DataProvider('shorts')]
	public function testAShortIsAShort(array $attachments): void {
		$this->assertSame(Stream::SHORT_KIND_SHORT, $this->kindOf($attachments));
	}

	/** @return array<string, array{list<array<string, mixed>>}> */
	public static function notShorts(): array {
		return [
			'a second past the longest' => [[self::video(self::probed(Stream::SHORT_MAX_SECONDS + 1, 1080, 1920))]],
			'a fraction past the longest' => [[self::video(self::probed(Stream::SHORT_MAX_SECONDS + 0.01, 1080, 1920))]],
			'a pixel wider than square' => [[self::video(self::probed(30, 1081, 1080))]],
			'a landscape video' => [[self::video(self::probed(30, 1920, 1080))]],
			'a remote video with no meta' => [[self::video(null)]],
			'a remote video with a size but no duration' => [[self::video(['original' => ['width' => 720, 'height' => 1280]])]],
			'a video with a duration but no shape' => [[self::video(['duration' => 30])]],
			'a duration of zero' => [[self::video(self::probed(0, 1080, 1920))]],
			'a negative duration' => [[self::video(self::probed(-5, 1080, 1920))]],
			'a zero height and no aspect' => [[self::video(['duration' => 30, 'original' => ['width' => 1080, 'height' => 0]])]],
			'a duration that is not a number' => [[self::video(['duration' => '0:00:30', 'original' => ['width' => 1080, 'height' => 1920]])]],
			'two short videos' => [[self::video(self::probed(10, 1080, 1920)), self::video(self::probed(10, 1080, 1920))]],
			'a short video beside a photograph' => [[self::video(self::probed(10, 1080, 1920)), self::video(['original' => ['width' => 1080, 'height' => 1920]], 'image')]],
			'a short gifv' => [[self::video(self::probed(5, 480, 640), 'gifv')]],
			'an upright photograph' => [[self::video(['original' => ['width' => 1080, 'height' => 1920]], 'image')]],
			'nothing attached' => [[]],
		];
	}

	/** @param list<array<string, mixed>> $attachments */
	#[DataProvider('notShorts')]
	public function testAnythingElseIsNot(array $attachments): void {
		$this->assertSame(Stream::SHORT_KIND_NONE, $this->kindOf($attachments));
	}

	public function testAColumnThatIsNotAListIsNotAShort(): void {
		$this->assertSame(Stream::SHORT_KIND_NONE, Stream::shortKindOf(''));
		$this->assertSame(Stream::SHORT_KIND_NONE, Stream::shortKindOf('not json'));
		$this->assertSame(Stream::SHORT_KIND_NONE, Stream::shortKindOf('{"type":"video"}'));
	}

	public function testEveryAnswerFitsTheColumn(): void {
		foreach ([Stream::SHORT_KIND_SHORT, Stream::SHORT_KIND_NONE] as $kind) {
			$this->assertLessThanOrEqual(self::COLUMN_WIDTH, strlen($kind));
		}
	}

	/**
	 * The stored copy of an upload the server measured, built the way
	 * `CacheDocumentService` builds its meta and serialised the way
	 * `StreamRequest` stores it: the shape the rule reads in production.
	 */
	public function testAMeasuredUploadIsReadFromItsStoredCopy(): void {
		$meta = new AttachmentMeta();
		$meta->setDuration(27.0);
		$meta->setOriginal(new AttachmentMetaDim([1080, 1920]));
		$meta->setSmall(new AttachmentMetaDim([360, 640]));

		$media = new MediaAttachment();
		$media->setId('7');
		$media->setType('video');
		$media->setMediaType('video/mp4');
		$media->setUrl('https://cloud.example.org/media/7');
		$media->setMeta($meta);

		$this->assertSame(
			Stream::SHORT_KIND_SHORT,
			Stream::shortKindOf((string)json_encode([$media->asLocal()], JSON_UNESCAPED_SLASHES))
		);

		$meta->setOriginal(new AttachmentMetaDim([1920, 1080]));
		$this->assertSame(
			Stream::SHORT_KIND_NONE,
			Stream::shortKindOf((string)json_encode([$media->asLocal()], JSON_UNESCAPED_SLASHES)),
			'the same video turned on its side is not a short'
		);
	}
}
