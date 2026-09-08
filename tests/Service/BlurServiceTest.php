<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use GdImage;
use OCA\Social\Service\BlurService;
use PHPUnit\Framework\TestCase;

class BlurServiceTest extends TestCase {
	private const BASE83 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';

	private function solidImage(int $r, int $g, int $b, int $size = 4): GdImage {
		$image = imagecreatetruecolor($size, $size);
		imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

		return $image;
	}

	public function testGeneratesAFourByThreeComponentBlurhash(): void {
		$hash = (new BlurService())->generateBlurHash($this->solidImage(200, 30, 30));

		// 1 size flag + 1 max-AC + 4 DC + 2 per AC component (4 * 3 - 1 of them)
		$this->assertSame(28, strlen($hash));
		$this->assertMatchesRegularExpression('/^[' . preg_quote(self::BASE83, '/') . ']+$/', $hash);
		// the first character encodes (x - 1) + (y - 1) * 9 = 3 + 2 * 9 = 21
		$this->assertSame(self::BASE83[21], $hash[0]);
	}

	public function testHashIsDeterministicForIdenticalPixels(): void {
		$service = new BlurService();

		$this->assertSame(
			$service->generateBlurHash($this->solidImage(10, 200, 90)),
			$service->generateBlurHash($this->solidImage(10, 200, 90)),
		);
	}

	public function testDifferentColorsGiveDifferentHashes(): void {
		$service = new BlurService();

		$this->assertNotSame(
			$service->generateBlurHash($this->solidImage(255, 0, 0)),
			$service->generateBlurHash($this->solidImage(0, 0, 255)),
		);
	}

	public function testGradientProducesNonZeroAcComponents(): void {
		$image = imagecreatetruecolor(8, 8);
		for ($x = 0; $x < 8; $x++) {
			imageline($image, $x, 0, $x, 7, imagecolorallocate($image, $x * 32, 0, 255 - $x * 32));
		}
		$solid = (new BlurService())->generateBlurHash($this->solidImage(128, 0, 128, 8));

		$gradient = (new BlurService())->generateBlurHash($image);

		// a solid color has every AC component at the neutral value, a gradient must not
		$this->assertSame(28, strlen($gradient));
		$this->assertNotSame(substr($solid, 6), substr($gradient, 6));
	}
}
