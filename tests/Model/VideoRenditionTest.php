<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\VideoRendition;
use PHPUnit\Framework\TestCase;

/**
 * The two pieces of text a rung turns into: its own playlist, and the line
 * about it in the master.
 */
class VideoRenditionTest extends TestCase {
	private function rung(int $height = 720): VideoRendition {
		$rendition = new VideoRendition();
		$rendition->setHeight($height)
			->setBandwidth(1200000)
			->setSize(9000000)
			->setLocalCopy('rung-uuid')
			->setPlaylist(
				"#EXTM3U\n#EXT-X-MAP:URI=\"" . VideoRendition::URI_PLACEHOLDER . "\"\n"
				. VideoRendition::URI_PLACEHOLDER . "\n"
			);

		return $rendition;
	}

	/**
	 * The placeholder is resolved in exactly one place, so a playlist is never
	 * written to a client with a filename from the encoder left in it.
	 */
	public function testEveryPlaceholderBecomesTheAddressTheServerOffers(): void {
		$playlist = $this->rung()->playlistFor('https://cloud.example/media/hls/uuid/720/file');

		$this->assertStringNotContainsString(VideoRendition::URI_PLACEHOLDER, $playlist);
		$this->assertSame(2, substr_count($playlist, 'https://cloud.example/media/hls/uuid/720/file'));
	}

	/**
	 * `BANDWIDTH` is what a player picks on and is mandatory in a master
	 * playlist; `RESOLUTION` is not given, because the width is not stored —
	 * `scale=-2` takes it from the source's aspect ratio — and an attribute
	 * that has to be right is better absent than guessed.
	 */
	public function testTheMasterEntryCarriesTheBandwidthAndNotAGuessedResolution(): void {
		$entry = $this->rung()->masterEntry('https://cloud.example/media/hls/uuid/720');

		$this->assertStringContainsString('#EXT-X-STREAM-INF:BANDWIDTH=1200000', $entry);
		$this->assertStringContainsString('NAME="720p"', $entry);
		$this->assertStringNotContainsString('RESOLUTION', $entry);
		$this->assertStringEndsWith("\nhttps://cloud.example/media/hls/uuid/720", $entry);
	}

	public function testWhatAClientIsToldAboutARung(): void {
		$this->assertSame(
			['height' => 720, 'bandwidth' => 1200000, 'size' => 9000000],
			$this->rung()->jsonSerialize()
		);
	}
}
