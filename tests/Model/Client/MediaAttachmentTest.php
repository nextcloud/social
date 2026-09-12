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
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class MediaAttachmentTest extends TestCase {
	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/** Registers a URL generator that builds links for this instance. */
	private function withUrlGenerator(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $args): string => isset($args['nid'])
				? 'https://cloud.example.org/media/stream/' . $args['nid']
				: 'https://cloud.example.org/media/' . ($args['uuid'] ?? '')
		);
		\OC::$server->register(IURLGenerator::class, $urlGenerator);
	}

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

	/**
	 * Mastodon sends every one of these keys on every attachment, so a client
	 * is entitled to declare them non-optional. They used to be run through
	 * `array_filter()` with no callback, which drops every *falsy* value: an
	 * attachment with no alt text lost `description`, one with no preview lost
	 * `preview_url`, and the first attachment ever cached (id `"0"`) lost its
	 * `id` — and the status carrying it then failed to decode too.
	 */
	public function testAsLocalNullsEmptyFieldsRatherThanDroppingThem(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());
		$media->setDescription('');

		$local = $media->asLocal();

		$this->assertSame('55', $local['id']);
		$this->assertSame('image', $local['type']);
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $local['url']);
		$this->assertSame('https://remote.example/media/cat.jpg', $local['remote_url']);
		$this->assertSame('UBL_:rOp', $local['blurhash']);
		$this->assertArrayHasKey('description', $local);
		$this->assertNull($local['description']);
		$this->assertSame(
			['id', 'type', 'url', 'preview_url', 'remote_url', 'meta', 'description', 'blurhash'],
			array_keys($local)
		);
	}

	public function testTheMetaIsAnObjectOnTheWire(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());

		$json = json_decode((string)json_encode($media->asLocal()), false);

		$this->assertIsObject($json->meta, 'meta is a dictionary, never a list');
		$this->assertSame(1200, $json->meta->original->width);
	}

	public function testAnAttachmentWithNoMetaAtAllReportsItAsNull(): void {
		// an AttachmentMeta holding nothing json-encodes as `[]`, which a client
		// decoding a dictionary rejects; null is what Mastodon sends instead
		$this->assertNull((new MediaAttachment())->asLocal()['meta']);
	}

	public function testAnAttachmentWithIdZeroKeepsIt(): void {
		$media = new MediaAttachment();
		$media->import(['id' => '0', 'type' => 'image', 'url' => 'https://a.example/x.png']);

		$this->assertSame('0', $media->asLocal()['id']);
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

	/**
	 * Media links are stored absolute, so a row written before the instance
	 * moved — or written by cron under a different overwrite.cli.url than a
	 * web request would have used — points at a host that no longer serves it.
	 * The uuid is the only part still worth keeping.
	 */
	public function testAMediaLinkFromAnOldAddressIsRebuiltForThisInstance(): void {
		$this->withUrlGenerator();
		$media = new MediaAttachment();
		$media->import([
			'id' => '190',
			'type' => 'image',
			'url' => 'http://localhost:8099/index.php/apps/social/media/a0a962e5-7e98-433b-80e2-09106a0b074f.png',
			'preview_url' => 'http://localhost/nextcloud/index.php/apps/social/media/272c3a32-c626-45a0-b08f-a03e7bb4ab2d.png',
		]);

		$local = $media->asLocal();

		$this->assertSame(
			'https://cloud.example.org/media/a0a962e5-7e98-433b-80e2-09106a0b074f.png',
			$local['url'],
		);
		$this->assertSame(
			'https://cloud.example.org/media/272c3a32-c626-45a0-b08f-a03e7bb4ab2d.png',
			$local['preview_url'],
		);
	}

	/**
	 * A federated video's `url` names a cache row rather than a copy, so there
	 * is no uuid in it -- and it has the same problem the uuid links had: it
	 * was written under whichever `overwrite.cli.url` the inbox request ran
	 * under, which on many instances is not the address a reader is on.
	 */
	public function testAStreamedVideoLinkIsRebuiltForThisInstanceToo(): void {
		$this->withUrlGenerator();
		$media = new MediaAttachment();
		$media->import([
			'id' => '711',
			'type' => 'video',
			'url' => 'http://devel/nextcloud/index.php/apps/social/media/stream/711',
			'preview_url' => 'http://localhost/nextcloud/index.php/apps/social/media/272c3a32-c626-45a0-b08f-a03e7bb4ab2d.png',
		]);

		$local = $media->asLocal();

		$this->assertSame('https://cloud.example.org/media/stream/711', $local['url']);
		$this->assertSame(
			'https://cloud.example.org/media/272c3a32-c626-45a0-b08f-a03e7bb4ab2d.png',
			$local['preview_url'],
		);
	}

	/** Somebody else's path that happens to end that way is not ours. */
	public function testARemoteLinkEndingInStreamIsLeftAlone(): void {
		$this->withUrlGenerator();
		$media = new MediaAttachment();
		$media->import(['id' => '1', 'url' => 'https://peertube.example/live/stream/42x']);

		$this->assertSame('https://peertube.example/live/stream/42x', $media->asLocal()['url']);
	}

	public function testALinkThatIsNotOneOfOurUuidsIsLeftAlone(): void {
		$this->withUrlGenerator();
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());

		$local = $media->asLocal();

		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $local['url']);
		$this->assertSame('https://files.mastodon.social/media/small/cat.jpg', $local['preview_url']);
		$this->assertSame('https://remote.example/media/cat.jpg', $local['remote_url']);
	}

	public function testAnAttachmentWithoutLinksReportsThemAsNull(): void {
		$this->withUrlGenerator();

		$local = (new MediaAttachment())->asLocal();

		$this->assertArrayHasKey('url', $local);
		$this->assertNull($local['url']);
		$this->assertArrayHasKey('preview_url', $local);
		$this->assertNull($local['preview_url']);
	}

	public function testJsonSerializeFollowsTheExportFormat(): void {
		$media = new MediaAttachment();
		$media->import($this->mastodonAttachment());

		$this->assertSame(ACore::FORMAT_LOCAL, $media->getExportFormat());
		$this->assertEquals($media->asLocal(), $media->jsonSerialize());

		$media->setExportFormat(ACore::FORMAT_ACTIVITYPUB);
		$this->assertSame($media->asDocument(), $media->jsonSerialize());
	}
}
