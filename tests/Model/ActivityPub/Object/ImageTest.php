<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use PHPUnit\Framework\TestCase;

class ImageTest extends TestCase {
	public function testIsADocumentOfTypeImage(): void {
		$image = new Image();

		$this->assertInstanceOf(Document::class, $image);
		$this->assertSame('Image', $image->getType());
	}

	public function testImportBehavesLikeADocument(): void {
		$image = new Image();
		$image->setUrlCloud('https://cloud.example.org');

		$image->import(['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://a.example/avatar.png']);

		$this->assertSame('Image', $image->getType());
		$this->assertSame('image/png', $image->getMediaType());
		$this->assertStringStartsWith('https://cloud.example.org/documents/g/', $image->getId());
		$this->assertSame('Image', $image->jsonSerialize()['type']);
	}
}
