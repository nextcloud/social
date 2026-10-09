<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\StatusRevision;
use PHPUnit\Framework\TestCase;

/**
 * A StatusEdit carries the attachments of its version, so an edit that only
 * changed a description or a focal point is visible in the history.
 */
class StatusRevisionTest extends TestCase {
	private function attachment(string $description, float $x, float $y): MediaAttachment {
		$meta = (new AttachmentMeta())->import(['focus' => ['x' => $x, 'y' => $y]]);

		return (new MediaAttachment())
			->setId('11')
			->setType('image')
			->setMediaType('image/jpeg')
			->setUrl('https://files.example/cat.jpg')
			->setPreviewUrl('https://files.example/small/cat.jpg')
			->setDescription($description)
			->setMeta($meta);
	}

	/** @return array<string, mixed> the first attachment as a client reads it */
	private function exported(StatusRevision $revision): array {
		$json = json_decode((string)json_encode($revision), true);
		$this->assertCount(1, $json['media_attachments']);

		return $json['media_attachments'][0];
	}

	public function testAVersionTakesTheAttachmentsThePostCarries(): void {
		$note = new Note();
		$note->setId('https://cloud.example/users/alice/posts/1');
		$note->setAttachments([$this->attachment('Before', 0.0, 0.0)]);

		$revision = StatusRevision::fromStream($note);

		$this->assertSame('Before', $this->exported($revision)['description']);
	}

	public function testTheStoredAttachmentsComeBackAsMastodonMediaAttachments(): void {
		$written = (new StatusRevision())->setMediaAttachments([$this->attachment('After', 0.5, -0.25)]);

		$read = (new StatusRevision())->importFromDatabase(['media' => $written->getMediaAttachmentsAsJson()]);
		$media = $this->exported($read);

		$this->assertSame('11', $media['id']);
		$this->assertSame('image', $media['type']);
		$this->assertSame('https://files.example/cat.jpg', $media['url']);
		$this->assertSame('https://files.example/small/cat.jpg', $media['preview_url']);
		$this->assertSame('After', $media['description']);
		$this->assertSame(['x' => 0.5, 'y' => -0.25], $media['meta']['focus']);
	}

	public function testARevisionWrittenBeforeAttachmentsWereRecordedHasNone(): void {
		$read = (new StatusRevision())->importFromDatabase(['content' => '<p>old</p>', 'media' => null]);

		$this->assertSame([], $read->jsonSerialize()['media_attachments']);
	}

	public function testAVersionWithoutAttachmentsStoresAnEmptyList(): void {
		$this->assertSame('[]', (new StatusRevision())->getMediaAttachmentsAsJson());
	}

	/** @return array<string, array{string, string}> */
	public static function storedTimes(): array {
		return [
			'a post made here' => ['2026-10-09T17:51:21+00:00', '2026-10-09T17:51:21.000Z'],
			'an edit made here' => ['2026-10-09T17:52:00Z', '2026-10-09T17:52:00.000Z'],
			'a peer in another zone' => ['2026-10-09T19:51:21+02:00', '2026-10-09T17:51:21.000Z'],
			'Mastodon\'s own form' => ['2026-10-09T17:51:21.000Z', '2026-10-09T17:51:21.000Z'],
		];
	}

	/**
	 * `created_at` is a client API time whatever spelling the version was
	 * stored with, the way Mastodon writes it in a StatusEdit.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('storedTimes')]
	public function testAVersionIsDatedTheWayTheClientApiDatesThings(string $stored, string $expected): void {
		$revision = (new StatusRevision())->setPublished($stored);

		$this->assertSame($expected, $revision->jsonSerialize()['created_at']);
	}
}
