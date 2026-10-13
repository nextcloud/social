<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\StreamAction;
use PHPUnit\Framework\TestCase;

class StreamActionTest extends TestCase {
	public function testConstructorStoresActorAndStream(): void {
		$action = new StreamAction('https://a.example/users/alice', 'https://a.example/n/1');

		$this->assertSame('https://a.example/users/alice', $action->getActorId());
		$this->assertSame('https://a.example/n/1', $action->getStreamId());
		$this->assertSame(0, $action->getId());
		$this->assertSame([], $action->getValues());
		$this->assertSame([], $action->getAffected());
	}

	public function testUpdateValueBoolTracksAcceptedKeysAsAffected(): void {
		$action = new StreamAction();

		$action->updateValueBool(StreamAction::LIKED, true);
		$action->updateValueBool(StreamAction::BOOSTED, false);
		$action->updateValueBool(StreamAction::LIKED, false);

		$this->assertTrue($action->hasValue(StreamAction::LIKED));
		$this->assertFalse($action->getValueBool(StreamAction::LIKED));
		$this->assertFalse($action->getValueBool(StreamAction::BOOSTED));
		$this->assertFalse($action->hasValue(StreamAction::REPLIED));
		$this->assertSame([StreamAction::LIKED, StreamAction::BOOSTED], $action->getAffected(), 'each key once, in first-touched order');
	}

	public function testUpdateValueAndIntStoreTypedValues(): void {
		$action = new StreamAction();

		$action->updateValue(StreamAction::REPLIED, 'yes');
		$action->updateValueInt('custom_count', 3);

		$this->assertSame('yes', $action->getValue(StreamAction::REPLIED));
		$this->assertSame(3, $action->getValueInt('custom_count'));
		$this->assertSame([StreamAction::REPLIED], $action->getAffected(), 'unknown keys are stored but not marked affected');
		$this->assertSame([StreamAction::REPLIED => 'yes', 'custom_count' => 3], $action->getValues());
	}

	public function testSetDefaultValuesOnlyFillsMissingKeys(): void {
		$action = new StreamAction();
		$action->updateValueBool(StreamAction::LIKED, true);

		$action->setDefaultValues([StreamAction::LIKED => false, StreamAction::BOOSTED => false]);

		$this->assertTrue($action->getValueBool(StreamAction::LIKED));
		$this->assertFalse($action->getValueBool(StreamAction::BOOSTED));
		$this->assertSame([StreamAction::LIKED], $action->getAffected(), 'defaults do not count as changes');
	}

	public function testImportFromDatabaseReadsTheInteractionFlags(): void {
		$action = new StreamAction();

		$action->importFromDatabase([
			'id' => '7',
			'actor_id' => 'https://a.example/users/alice',
			'stream_id' => 'https://a.example/n/1',
			'liked' => '1',
			'boosted' => '0',
			'replied' => true,
			'bookmarked' => '1',
		]);

		$this->assertSame(7, $action->getId());
		$this->assertSame('https://a.example/users/alice', $action->getActorId());
		$this->assertSame('https://a.example/n/1', $action->getStreamId());
		$this->assertSame(
			[
				StreamAction::LIKED => true,
				StreamAction::BOOSTED => false,
				StreamAction::REPLIED => true,
				StreamAction::BOOKMARKED => true,
				// PeerTube's other counter, absent from this row and so false
				StreamAction::DISLIKED => false,
				// when each mark was set; '0' on a row from before they were dated
				StreamAction::LIKED_NID => '0',
				StreamAction::BOOKMARKED_NID => '0',
			],
			$action->getValues()
		);
	}

	public function testJsonSerializeExposesIdActorStreamAndValues(): void {
		$action = new StreamAction('actor', 'stream');
		$action->setId(2);
		$action->updateValueBool(StreamAction::BOOSTED, true);

		$this->assertSame([
			'id' => 2,
			'actorId' => 'actor',
			'streamId' => 'stream',
			'values' => [StreamAction::BOOSTED => true],
		], $action->jsonSerialize());
	}

	public function testImportFromDatabaseReadsWhenEachMarkWasSet(): void {
		$action = new StreamAction();

		$action->importFromDatabase([
			'liked' => '1',
			'liked_nid' => '1760000000000123456',
			'bookmarked' => '0',
			'bookmarked_nid' => 0,
		]);

		// as decimal strings: a mark id is on a post nid's scale, above PHP_INT_MAX on 32-bit
		$this->assertSame('1760000000000123456', $action->getValue(StreamAction::LIKED_NID));
		$this->assertSame('0', $action->getValue(StreamAction::BOOKMARKED_NID));
	}
}
