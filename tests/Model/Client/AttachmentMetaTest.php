<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\AttachmentMetaFocus;
use PHPUnit\Framework\TestCase;

class AttachmentMetaTest extends TestCase {
	public function testImportReadsMastodonMetaWithNestedDimensionsAndFocus(): void {
		$meta = new AttachmentMeta();

		$meta->import([
			'width' => 1200,
			'height' => 800,
			'size' => '1200x800',
			'length' => '0:00:12.5',
			'fps' => 30,
			'audio_encode' => 'aac',
			'audio_bitrate' => '128 kb/s',
			'audio_channels' => 'stereo',
			'description' => 'A cat',
			'blurhash' => 'UBL_:rOp',
			'original' => ['width' => 1200, 'height' => 800, 'size' => '1200x800', 'frame_rate' => '30'],
			'small' => ['width' => 600, 'height' => 400, 'size' => '600x400'],
			'focus' => ['x' => 1, 'y' => -1],
		]);

		$this->assertSame(1200, $meta->getWidth());
		$this->assertSame(800, $meta->getHeight());
		$this->assertSame('1200x800', $meta->getSize());
		$this->assertSame('0:00:12.5', $meta->getLength());
		$this->assertSame(30.0, $meta->getFps());
		$this->assertSame('aac', $meta->getAudioEncode());
		$this->assertSame('128 kb/s', $meta->getAudioBitrate());
		$this->assertSame('stereo', $meta->getAudioChannels());
		$this->assertSame('A cat', $meta->getDescription());
		$this->assertSame('UBL_:rOp', $meta->getBlurHash());
		$this->assertSame(1200, $meta->getOriginal()->getWidth());
		$this->assertSame(30.0, $meta->getOriginal()->getFrameRate());
		$this->assertSame('600x400', $meta->getSmall()->getSize());
		$this->assertSame(1.0, $meta->getFocus()->getX());
		$this->assertSame(-1.0, $meta->getFocus()->getY());
	}

	public function testImportLeavesOptionalNumbersNullWhenAbsent(): void {
		$meta = new AttachmentMeta();

		$meta->import(['size' => '1x1']);

		$this->assertNull($meta->getWidth());
		$this->assertNull($meta->getHeight());
		$this->assertNull($meta->getAspect());
		$this->assertNull($meta->getDuration());
		$this->assertSame(0.0, $meta->getFocus()->getX());
	}

	public function testJsonSerializeDropsUnsetValues(): void {
		$meta = new AttachmentMeta();
		$meta->setOriginal(new AttachmentMetaDim([1200, 800]))
			->setSmall(new AttachmentMetaDim([600, 400]))
			->setFocus(new AttachmentMetaFocus(0, 0))
			->setDescription('A cat');

		$json = $meta->jsonSerialize();

		$this->assertSame(['original', 'small', 'focus', 'description'], array_keys($json));
		$this->assertSame('A cat', $json['description']);
		$this->assertSame(1200, $json['original']->getWidth());
	}

	public function testSettersRoundTrip(): void {
		$meta = new AttachmentMeta();

		$meta->setWidth(10)->setHeight(5)->setAspect(2.0)->setDuration(1.5)->setFps(24.0)->setLength('0:01')->setBlurHash('x');

		$this->assertSame(10, $meta->getWidth());
		$this->assertSame(5, $meta->getHeight());
		$this->assertSame(2.0, $meta->getAspect());
		$this->assertSame(1.5, $meta->getDuration());
		$this->assertSame(24.0, $meta->getFps());
		$this->assertSame('0:01', $meta->getLength());
		$this->assertSame('x', $meta->getBlurHash());
	}
}
