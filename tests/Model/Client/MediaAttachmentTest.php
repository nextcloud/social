<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\MediaAttachment;
use PHPUnit\Framework\TestCase;

class MediaAttachmentTest extends TestCase {
	private function mastodonAttachment(): array {
		return [
			'id' => '55',
			'type' => 'image',
			'url' => 'https://files.mastodon.social/media/cat.jpg',
			'preview_url' => 'https://files.mastodon.social/media/small/cat.jpg',
			'remote_url' => 'https://remote.example/media/cat.jpg',
			'text_url' => null,
			'description' => 'A cat',
			'blurhash' => 'UBL_:rOp',
			'meta' => [
				'original' => ['width' => 1200, 'height' => 800, 'size' => '1200x800'],
				'small' => ['width' => 600, 'height' => 400, 'size' => '600x400'],
				'focus' => ['x' => 0, 'y' => 0],
			],
		];
	}

	public function testImportReadsAMastodonMediaAttachment(): void {
		$media = new MediaAttachment();

		$result = $media->import($this->mastodonAttachment());

		$this->assertSame($media, $result);
		$this->assertSame('55', $media->getId());
		$this->assertSame('image', $media->getType());
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $media->getUrl());
		$this->assertSame('https://files.mastodon.social/media/small/cat.jpg', $media->getPreviewUrl());
		$this->assertSame('https://remote.example/media/cat.jpg', $media->getRemoteUrl());
		$this->assertSame('A cat', $media->getDescription());
		$this->assertSame('UBL_:rOp', $media->getBlurHash());
		$this->assertInstanceOf(AttachmentMeta::class, $media->getMeta());
		$this->assertSame(1200, $media->getMeta()->getOriginal()->getWidth());
		$this->assertSame(400, $media->getMeta()->getSmall()->getHeight());
	}

	public function testAsLocalDropsEmptyFieldsAndKeepsTheMeta(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());
		$media->setDescription('');

		$local = $media->asLocal();

		$this->assertSame('55', $local['id']);
		$this->assertSame('image', $local['type']);
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $local['url']);
		$this->assertSame('https://remote.example/media/cat.jpg', $local['remote_url']);
		$this->assertSame($media->getMeta(), $local['meta']);
		$this->assertArrayNotHasKey('description', $local);
		$this->assertSame('UBL_:rOp', $local['blurhash']);
	}

	public function testAsDocumentBuildsAnActivityPubDocument(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());

		$this->assertSame([
			'type' => 'Document',
			'mediaType' => '',
			'url' => 'https://files.mastodon.social/media/cat.jpg',
			// the wire carries the alt text as `name`
			'name' => 'A cat',
			'blurhash' => 'UBL_:rOp',
			'width' => 1200,
			'height' => 800,
		], $media->asDocument());
	}

	public function testAsDocumentWithoutMetaUsesZeroDimensions(): void {
		$document = (new MediaAttachment())->asDocument();

		$this->assertSame(0, $document['width']);
		$this->assertSame(0, $document['height']);
	}

	public function testAsDocumentWithoutDimensionsUsesZero(): void {
		$media = new MediaAttachment();
		$media->import(['id' => '1', 'url' => 'https://a.example/x.png']);

		$document = $media->asDocument();

		$this->assertSame(0, $document['width']);
		$this->assertSame(0, $document['height']);
	}

	public function testJsonSerializeFollowsTheExportFormat(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());

		$this->assertSame(ACore::FORMAT_LOCAL, $media->getExportFormat());
		$this->assertSame($media->asLocal(), $media->jsonSerialize());

		$media->setExportFormat(ACore::FORMAT_ACTIVITYPUB);
		$this->assertSame($media->asDocument(), $media->jsonSerialize());
	}
}
