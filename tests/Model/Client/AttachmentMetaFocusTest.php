<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\AttachmentMetaFocus;
use PHPUnit\Framework\TestCase;

class AttachmentMetaFocusTest extends TestCase {
	public function testDefaultsToTheCenter(): void {
		$focus = new AttachmentMetaFocus();

		$this->assertSame(0.0, $focus->getX());
		$this->assertSame(0.0, $focus->getY());
		$this->assertSame(['x' => 0.0, 'y' => 0.0], $focus->jsonSerialize());
	}

	public function testCoordinatesCanBeSet(): void {
		$focus = new AttachmentMetaFocus(0.5, -0.25);

		$this->assertSame(0.5, $focus->getX());
		$this->assertSame(-0.25, $focus->getY());

		$focus->setX(-1)->setY(1);
		$this->assertSame(['x' => -1.0, 'y' => 1.0], $focus->jsonSerialize());
	}
}
