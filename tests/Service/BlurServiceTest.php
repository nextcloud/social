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

	/** Left half red, right half blue, at any size. */
	private function halvesImage(int $width, int $height): GdImage {
		$image = imagecreatetruecolor($width, $height);
		imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 220, 30, 30));
		imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 30, 30, 220));

		return $image;
	}

	/**
	 * The encoder holds one PHP array per pixel, so the hash is taken from
	 * a 32-px copy: a resized 800x500 preview took near 200 MB at full size.
	 */
	public function testALargeImageIsHashedWithinAFewMegabytes(): void {
		$image = $this->halvesImage(800, 500);
		$service = new BlurService();

		gc_collect_cycles();
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$hash = $service->generateBlurHash($image);
		$peak = memory_get_peak_usage() - $before;

		$this->assertSame(28, strlen($hash));
		$this->assertMatchesRegularExpression('/^[' . preg_quote(self::BASE83, '/') . ']+$/', $hash);
		$this->assertLessThan(16 * 1024 * 1024, $peak, 'peak ' . round($peak / 1048576, 1) . ' MB above the start');
	}

	/** The copy keeps the picture's shape: landscape, upright, and a palette image alike. */
	public function testTheSampledCopyKeepsTheAspectRatio(): void {
		$service = new BlurService();

		$landscape = $this->halvesImage(800, 500);
		$this->assertSame(
			$service->generateBlurHash(imagescale($landscape, 32, 20)),
			$service->generateBlurHash($landscape),
		);

		$upright = $this->halvesImage(500, 800);
		$this->assertSame(
			$service->generateBlurHash(imagescale($upright, 20, 32)),
			$service->generateBlurHash($upright),
		);
		$this->assertNotSame($service->generateBlurHash($landscape), $service->generateBlurHash($upright));

		$palette = imagecreate(300, 200);
		imagecolorallocate($palette, 30, 30, 220);
		imagefilledrectangle($palette, 0, 0, 149, 199, imagecolorallocate($palette, 220, 30, 30));
		$this->assertSame($service->generateBlurHash($this->halvesImage(300, 200)), $service->generateBlurHash($palette));
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
