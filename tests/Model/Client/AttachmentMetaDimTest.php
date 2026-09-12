<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\AttachmentMetaDim;
use PHPUnit\Framework\TestCase;

class AttachmentMetaDimTest extends TestCase {
	public function testConstructorDerivesSizeAndAspectFromWidthAndHeight(): void {
		$dim = new AttachmentMetaDim([1200, 800]);

		$this->assertSame(1200, $dim->getWidth());
		$this->assertSame(800, $dim->getHeight());
		$this->assertSame('1200x800', $dim->getSize());
		$this->assertSame(1.5, $dim->getAspect());
	}

	public static function emptyDimensionProvider(): array {
		return [
			'no dimensions' => [[]],
			'one value' => [[100]],
			'zero width' => [[0, 100]],
			'negative height' => [[100, -1]],
			'strings' => [['0', '0']],
		];
	}

	/**
	 * @dataProvider emptyDimensionProvider
	 */
	public function testConstructorLeavesUnusableDimensionsEmpty(array $dimensions): void {
		$dim = new AttachmentMetaDim($dimensions);

		$this->assertNull($dim->getWidth());
		$this->assertNull($dim->getHeight());
		$this->assertSame('', $dim->getSize());
		$this->assertNull($dim->getAspect());
	}

	public function testImportReadsTheMastodonFields(): void {
		$dim = new AttachmentMetaDim();

		$dim->import(['width' => 640, 'height' => 480, 'size' => '640x480', 'aspect' => 2, 'duration' => 12, 'bitrate' => 96000, 'frame_rate' => '30']);

		$this->assertSame(640, $dim->getWidth());
		$this->assertSame(480, $dim->getHeight());
		$this->assertSame('640x480', $dim->getSize());
		$this->assertSame(2.0, $dim->getAspect());
		$this->assertSame(12.0, $dim->getDuration());
		$this->assertSame(96000.0, $dim->getBitrate());
		$this->assertSame(30.0, $dim->getFrameRate());
	}

	public function testJsonSerializeDropsEmptyValues(): void {
		$this->assertSame([], (new AttachmentMetaDim())->jsonSerialize());
		$this->assertSame(
			['width' => 1200, 'height' => 800, 'size' => '1200x800', 'aspect' => 1.5],
			(new AttachmentMetaDim([1200, 800]))->jsonSerialize()
		);
	}
}
