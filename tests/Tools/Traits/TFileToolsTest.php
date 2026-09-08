<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Traits;

use OCA\Social\Tools\Traits\TFileTools;
use PHPUnit\Framework\TestCase;

class TFileToolsTest extends TestCase {
	public function testChecksumOfAStreamIsItsMd5(): void {
		$tools = new class {
			use TFileTools;

			public function checksum($stream): string {
				return $this->getChecksumFromStream($stream);
			}
		};
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, 'hello fediverse');
		rewind($stream);

		$this->assertSame(md5('hello fediverse'), $tools->checksum($stream));

		rewind($stream);
		$this->assertSame(md5('hello fediverse'), $tools->checksum($stream), 'reading again from the start gives the same checksum');
		fclose($stream);
	}
}
