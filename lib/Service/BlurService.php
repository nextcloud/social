<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use GdImage;
use kornrunner\Blurhash\Blurhash;

class BlurService {
	public function __construct() {
	}

	public function generateBlurHash(GdImage $image): string {
		$width = imagesx($image);
		$height = imagesy($image);

		$pixels = [];
		for ($y = 0; $y < $height; ++$y) {
			$row = [];
			for ($x = 0; $x < $width; ++$x) {
				$index = imagecolorat($image, $x, $y);
				$colors = imagecolorsforindex($image, $index);

				$row[] = [$colors['red'], $colors['green'], $colors['blue']];
			}
			$pixels[] = $row;
		}

		return Blurhash::encode($pixels, 4, 3);
	}
}
