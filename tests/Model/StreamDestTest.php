<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\StreamDest;
use PHPUnit\Framework\TestCase;

class StreamDestTest extends TestCase {
	public function testImportFromDatabaseReadsTheRow(): void {
		$dest = new StreamDest();

		$dest->importFromDatabase([
			'stream_id' => 'abc',
			'actor_id' => 'def',
			'type' => 'recipient',
			'subtype' => 'to',
		]);

		$this->assertSame('abc', $dest->getStreamId());
		$this->assertSame('def', $dest->getActorId());
		$this->assertSame('recipient', $dest->getType());
		$this->assertSame('to', $dest->getSubtype());
	}

	public function testJsonSerializeUsesCamelCaseKeys(): void {
		$dest = (new StreamDest())
			->setStreamId('abc')
			->setActorId('def')
			->setType('recipient')
			->setSubtype('cc');

		$this->assertSame(
			['streamId' => 'abc', 'actorId' => 'def', 'type' => 'recipient', 'subtype' => 'cc'],
			$dest->jsonSerialize()
		);
	}
}
