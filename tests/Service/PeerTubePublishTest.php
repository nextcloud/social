<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\PeerTubeService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The other direction: the `Video` this instance publishes.
 *
 * PeerTube ingests `Video` objects and nothing else, so an instance publishing
 * `Note`s had, from its side, no videos at all. What is asserted hardest here
 * is the **round trip** -- everything published goes back through the reader in
 * the same file, because two halves of one wire format in one class is only
 * worth anything if they are held to each other.
 */
class PeerTubePublishTest extends TestCase {
	private const WATCH = 'https://cloud.example.org/apps/social/@alice/0123';

	private PeerTubeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new PeerTubeService(
			$this->createMock(DocumentInterface::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function attachment(
		string $type = 'video',
		string $preview = 'https://cloud.example.org/media/poster.jpeg',
		float $duration = 113,
	): MediaAttachment {
		$meta = new AttachmentMeta();
		$meta->setDuration($duration);
		$original = new AttachmentMetaDim([1920, 1080]);
		$original->setDuration($duration);
		$meta->setOriginal($original);
		$meta->setSmall(new AttachmentMetaDim([1280, 720]));

		$media = new MediaAttachment();
		$media->setId('7')
			->setType($type)
			->setMediaType('video/mp4')
			->setUrl('https://cloud.example.org/media/movie.mp4')
			->setPreviewUrl($preview)
			->setDescription('A cat knocking a glass off a table');

		return $media->setMeta($meta);
	}

	/** A note as `exportAsActivityPub()` would have produced it. */
	private function note(string $content = '<p>The cat and the glass</p><p>Again.</p>'): array {
		return [
			'id' => self::WATCH,
			'type' => 'Note',
			'content' => $content,
			'attributedTo' => 'https://cloud.example.org/apps/social/@alice',
			'published' => '2026-09-13T10:00:00Z',
			'sensitive' => false,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'attachment' => [['type' => 'Document', 'mediaType' => 'video/mp4']],
		];
	}

	// which posts are videos at all

	public function testOneVideoAttachmentMakesItAVideo(): void {
		$this->assertNotNull(PeerTubeService::soleVideo([$this->attachment()]));
	}

	/**
	 * A `Video` object *is* the video -- one title, one duration, one file --
	 * so a post carrying a video and something else is a post.
	 */
	public function testAVideoAlongsideAnythingElseIsNotAVideo(): void {
		$this->assertNull(PeerTubeService::soleVideo([$this->attachment(), $this->attachment('image')]));
	}

	public function testAPostWithNoVideoIsNotAVideo(): void {
		$this->assertNull(PeerTubeService::soleVideo([$this->attachment('image')]));
		$this->assertNull(PeerTubeService::soleVideo([]));
	}

	// the shape

	public function testTheObjectBecomesAVideoCarryingItsRunningTime(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH);

		$this->assertSame('Video', $video['type']);
		$this->assertSame('PT113S', $video['duration']);
		$this->assertTrue($video['commentsEnabled']);
		// what the content is, said out loud: this app's posts are html, and a
		// peer that assumed markdown would show somebody their own tags
		$this->assertSame('text/html', $video['mediaType']);
	}

	/**
	 * `url` as a list is the one thing PeerTube actually looks at, and a
	 * `Video` without it is a video nothing can play.
	 */
	public function testTheFileAndTheWatchPageBothTravelInUrl(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH);

		$this->assertSame([
			['type' => 'Link', 'mediaType' => 'text/html', 'href' => self::WATCH],
			[
				'type' => 'Link',
				'mediaType' => 'video/mp4',
				'href' => 'https://cloud.example.org/media/movie.mp4',
				'width' => 1920,
				'height' => 1080,
			],
		], $video['url']);
	}

	public function testThePosterTravelsAsTheIcon(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH);

		$this->assertSame([[
			'type' => 'Image',
			'mediaType' => 'image/jpeg',
			'url' => 'https://cloud.example.org/media/poster.jpeg',
			'width' => 1280,
			'height' => 720,
		]], $video['icon']);
	}

	/**
	 * A server with no ffmpeg has no poster, and `preview_url` is then the
	 * video itself -- which is not a picture and must not be published as one.
	 */
	public function testAVideoWithNoPosterPublishesNoIcon(): void {
		$attachment = $this->attachment(preview: 'https://cloud.example.org/media/movie.mp4');

		$video = PeerTubeService::asVideo($this->note(), $attachment, self::WATCH);

		$this->assertArrayNotHasKey('icon', $video);
	}

	/**
	 * Every Mastodon-family server reads `attachment` and nothing else, and
	 * these posts rendered there with an inline player before any of this
	 * existed. Publishing both is the difference between gaining PeerTube and
	 * trading Mastodon for it.
	 */
	public function testTheAttachmentIsKeptForEverybodyElse(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH);

		$this->assertSame([['type' => 'Document', 'mediaType' => 'video/mp4']], $video['attachment']);
	}

	public function testEverythingTheNoteAlreadySaidSurvives(): void {
		$note = $this->note();

		$video = PeerTubeService::asVideo($note, $this->attachment(), self::WATCH);

		foreach (['id', 'attributedTo', 'published', 'sensitive', 'to', 'content'] as $key) {
			$this->assertSame($note[$key], $video[$key], $key . ' was lost');
		}
	}

	// the title, which a Note does not have

	public function testTheTitleIsTheFirstLineOfThePost(): void {
		$video = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH);

		$this->assertSame('The cat and the glass', $video['name']);
	}

	/** A post that is nothing but the video falls back to its alt text. */
	public function testAPostWithNoTextIsTitledByItsAltText(): void {
		$video = PeerTubeService::asVideo($this->note(''), $this->attachment(), self::WATCH);

		$this->assertSame('A cat knocking a glass off a table', $video['name']);
	}

	public function testATitleIsAlwaysPresent(): void {
		$attachment = $this->attachment();
		$attachment->setDescription('');

		$video = PeerTubeService::asVideo($this->note(''), $attachment, self::WATCH);

		$this->assertSame('Video', $video['name']);
	}

	public function testTheTitleIsPlainTextAndBounded(): void {
		$long = str_repeat('a very long title indeed ', 20);

		$video = PeerTubeService::asVideo(
			$this->note('<p><a href="https://x.example">' . $long . '</a></p>'),
			$this->attachment(),
			self::WATCH
		);

		$this->assertSame(120, mb_strlen($video['name']));
		$this->assertStringNotContainsString('<', $video['name']);
	}

	/** Entities in the html are text by the time they are a title. */
	public function testTheTitleIsDecoded(): void {
		$video = PeerTubeService::asVideo(
			$this->note('<p>Cats &amp; dogs</p>'),
			$this->attachment(),
			self::WATCH
		);

		$this->assertSame('Cats & dogs', $video['name']);
	}

	// the round trip

	/**
	 * What this app publishes, this app can read -- through the same methods
	 * that read framatube.org. Two halves of one wire format in one class are
	 * only worth anything if they are held to each other.
	 */
	public function testWhatIsPublishedIsReadBackIdentically(): void {
		$published = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH);

		$this->assertSame(self::WATCH, $this->service->watchUrl($published));
		$this->assertSame(113, $this->service->duration($published));
		$this->assertSame(
			['url' => 'https://cloud.example.org/media/poster.jpeg', 'mediaType' => 'image/jpeg'],
			$this->service->thumbnail($published)
		);
		$this->assertSame(
			'https://cloud.example.org/apps/social/@alice',
			$this->service->attributedTo($published)
		);

		$source = $this->service->source($published);
		$this->assertSame('https://cloud.example.org/media/movie.mp4', $source?->getUrl());
		$this->assertSame('video/mp4', $source?->getMediaType());
	}

	/**
	 * And the content survives the trip: the title is prepended by the reader,
	 * but the post that was written is still in there and is still html.
	 */
	public function testTheContentSurvivesTheRoundTrip(): void {
		$published = PeerTubeService::asVideo($this->note(), $this->attachment(), self::WATCH);

		$read = $this->service->content($published);

		$this->assertStringContainsString('<p>The cat and the glass</p>', $read);
		$this->assertStringContainsString('<p>Again.</p>', $read);
		// declared html, so it is passed through rather than escaped
		$this->assertStringNotContainsString('&lt;p&gt;', $read);
	}
}
