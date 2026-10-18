<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use PHPUnit\Framework\TestCase;

/**
 * `video_card`: the same seven facts about every video post, whether it
 * arrived as a PeerTube `Video`, was uploaded here, or came from somewhere
 * that only sent a file and some words.
 */
class StreamVideoCardTest extends TestCase {
	private const PUBLISHED = 1714564800;

	private string $timezone;

	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
	}

	/**
	 * @param array<int, array<string, mixed>> $attachments
	 */
	private function post(string $content, array $attachments = [], array $videoMeta = []): Note {
		$note = new Note();
		$note->setId('https://cloud.example/apps/social/@alice/1');
		$note->setNid(7);
		$note->setPublishedTime(self::PUBLISHED);
		$note->setContent($content);
		$note->setAttachments(array_map(
			static fn (array $data): MediaAttachment => (new MediaAttachment())->import($data),
			$attachments
		));
		if ($videoMeta !== []) {
			$note->setVideoMeta($videoMeta);
		}

		return $note;
	}

	/** @return array<string, mixed> */
	private function video(string $url, string $preview, array $meta = []): array {
		return [
			'id' => '1',
			'type' => 'video',
			'media_type' => 'video/mp4',
			'url' => $url,
			'preview_url' => $preview,
			'meta' => $meta,
		];
	}

	public function testAPeerTubeVideoSaysEverythingItself(): void {
		$card = $this->post(
			'<p>A long description</p>',
			[$this->video('https://tube.example/static/web-videos/1.mp4', 'https://tube.example/lazy-static/thumbnails/1.jpg', ['duration' => 30])],
			[
				'title' => 'Building a boat',
				'duration' => 754,
				'views' => 1234,
				'category' => 'Science & Technology',
				'originally_published_at' => '2019-03-01T10:00:00.000Z',
			]
		)->exportAsLocal()['video_card'];

		$this->assertSame([
			'title' => 'Building a boat',
			'duration' => 754,
			'thumbnail' => 'https://tube.example/lazy-static/thumbnails/1.jpg',
			'views' => 1234,
			'live' => false,
			'category' => 'Science & Technology',
			'published' => '2019-03-01T10:00:00.000Z',
		], $card);
	}

	public function testAnUploadHereIsTitledByItsFirstLine(): void {
		$card = $this->post(
			'<p>Sunset at the harbour &amp; the ferry<br>shot on my phone</p><p>#sunset</p>',
			[$this->video('https://cloud.example/apps/social/media/one.mp4', 'https://cloud.example/apps/social/media/one-poster.jpeg', ['duration' => 42])]
		)->exportAsLocal()['video_card'];

		$this->assertSame('Sunset at the harbour & the ferry', $card['title']);
		$this->assertSame(42, $card['duration']);
		$this->assertSame('https://cloud.example/apps/social/media/one-poster.jpeg', $card['thumbnail']);
		$this->assertNull($card['views'], 'nobody counted, so it is not a number');
		$this->assertFalse($card['live']);
		$this->assertNull($card['category']);
		$this->assertSame('2024-05-01T12:00:00.000Z', $card['published']);
	}

	/** A Mastodon or Bluesky video: a file, no poster, no running time. */
	public function testAPlainRemoteVideoHasNullsWhereNothingWasSaid(): void {
		$card = $this->post(
			'<p></p><p>  look at this  </p>',
			[$this->video('https://files.example/a.mp4', 'https://files.example/a.mp4')]
		)->exportAsLocal()['video_card'];

		$this->assertSame('look at this', $card['title']);
		$this->assertNull($card['duration']);
		$this->assertNull($card['thumbnail'], 'the video itself is not a picture');
		$this->assertNull($card['views']);
	}

	public function testALiveBroadcastSaysSo(): void {
		$card = $this->post('', [], ['title' => 'Live now', 'live' => true])->exportAsLocal()['video_card'];

		$this->assertTrue($card['live']);
		$this->assertSame('Live now', $card['title']);
		$this->assertNull($card['duration']);
		$this->assertNull($card['thumbnail']);
	}

	/** The words behind a content warning are not shown on the card either. */
	public function testAWarnedPostIsTitledByItsWarning(): void {
		$note = $this->post('<p>what is behind the warning</p>', [$this->video('https://files.example/a.mp4', '')]);
		$note->setSpoilerText('Flashing lights');

		$this->assertSame('Flashing lights', $note->exportAsLocal()['video_card']['title']);
	}

	public function testATitleIsBounded(): void {
		$card = $this->post('<p>' . str_repeat('é', 300) . '</p>', [$this->video('https://files.example/a.mp4', '')])
			->exportAsLocal()['video_card'];

		$this->assertSame(Stream::VIDEO_CARD_TITLE, mb_strlen($card['title']));
	}

	public function testAPostWithoutWordsHasAnEmptyTitle(): void {
		$card = $this->post('', [$this->video('https://files.example/a.mp4', '')])->exportAsLocal()['video_card'];

		$this->assertSame('', $card['title']);
	}

	public function testADateThatCannotBeReadFallsBackToThePost(): void {
		$card = $this->post('', [], ['title' => 'x', 'originally_published_at' => 'last spring'])->exportAsLocal()['video_card'];

		$this->assertSame('2024-05-01T12:00:00.000Z', $card['published']);
	}

	/** The video of a post that also carries a picture is the one the card shows. */
	public function testTheVideoAttachmentIsTheOneDrawn(): void {
		$card = $this->post('<p>both</p>', [
			['id' => '1', 'type' => 'image', 'media_type' => 'image/jpeg', 'url' => 'https://files.example/p.jpg', 'preview_url' => 'https://files.example/p-small.jpg'],
			$this->video('https://files.example/v.mp4', 'https://files.example/v.jpg', ['duration' => 9]),
		])->exportAsLocal()['video_card'];

		$this->assertSame('https://files.example/v.jpg', $card['thumbnail']);
		$this->assertSame(9, $card['duration']);
	}

	public function testAPostThatIsNotAVideoHasNoCard(): void {
		$status = $this->post('<p>words</p>', [
			['id' => '1', 'type' => 'image', 'media_type' => 'image/jpeg', 'url' => 'https://files.example/p.jpg'],
		])->exportAsLocal();

		$this->assertArrayHasKey('video_card', $status);
		$this->assertNull($status['video_card']);
	}
}
