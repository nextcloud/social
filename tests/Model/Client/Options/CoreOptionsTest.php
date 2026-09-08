<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client\Options;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\Options\CoreOptions;
use PHPUnit\Framework\TestCase;

class CoreOptionsTest extends TestCase {
	public function testDefaultsToTheActivityPubFormat(): void {
		$this->assertSame(ACore::FORMAT_ACTIVITYPUB, (new CoreOptions())->getFormat());
	}

	public function testFormatCanBeSwitched(): void {
		$options = new CoreOptions();

		$this->assertSame($options, $options->setFormat(ACore::FORMAT_LOCAL));
		$this->assertSame(ACore::FORMAT_LOCAL, $options->getFormat());
	}
}
