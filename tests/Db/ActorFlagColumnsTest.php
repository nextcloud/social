<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CoreRequestBuilder;
use PHPUnit\Framework\TestCase;

/**
 * `discoverable`, `indexable`, `alsoKnownAs` and `movedTo` had nowhere to
 * live on a local actor: the model carried the first and the third, the actor
 * document emitted neither, and no column stored any of the four — so a local
 * user could never opt into directories or full-text search, and could not
 * declare an alias, which is what Mastodon asks for before it accepts a Move
 * towards here. Neither write path can be exercised without a database, so
 * the source is read, the way StreamSensitiveColumnTest reads it.
 */
class ActorFlagColumnsTest extends TestCase {
	private const REQUEST = __DIR__ . '/../../lib/Db/ActorsRequest.php';
	private const BUILDER = __DIR__ . '/../../lib/Db/ActorsRequestBuilder.php';
	private const CACHE_BUILDER = __DIR__ . '/../../lib/Db/CacheActorsRequestBuilder.php';

	private const COLUMNS = ['discoverable', 'indexable', 'also_known_as', 'moved_to'];

	/**
	 * The `bot` flag and the actor's `type` are one fact: a client reads `bot`,
	 * a peer reads `type`, and `Person::setBot()` keeps them together. The
	 * local-actor parse then set the type back to `Person` unconditionally, so
	 * an account marked automated said `bot: true` to a client and `Person` to
	 * every server it federated with.
	 */
	public function testTheLocalActorParseDoesNotOverwriteTheBotType(): void {
		$source = (string)file_get_contents(self::BUILDER);

		$this->assertStringNotContainsString(
			"setType('Person')",
			$source,
			'the type is decided by the bot flag, not hardcoded on every read'
		);
		$this->assertMatchesRegularExpression(
			'/setType\(\$actor->isBot\(\)/',
			$source
		);
	}

	public function testTheActorTableDeclaresTheColumns(): void {
		$tables = (new \ReflectionClass(CoreRequestBuilder::class))
			->getStaticPropertyValue('tables');

		foreach (self::COLUMNS as $column) {
			$this->assertContains($column, $tables[CoreRequestBuilder::TABLE_ACTORS]);
		}
	}

	public function testTheActorSelectReadsTheColumns(): void {
		$source = (string)file_get_contents(self::BUILDER);

		foreach (self::COLUMNS as $column) {
			$this->assertStringContainsString(
				"'a." . $column . "'",
				$source,
				'a column no query selects parses as its default, i.e. never set'
			);
		}
	}

	public function testCreateStoresTheFlags(): void {
		$source = (string)file_get_contents(self::REQUEST);

		foreach (['discoverable', 'indexable'] as $column) {
			$this->assertMatchesRegularExpression(
				"/setValue\(\s*'" . $column . "'/",
				$source,
				'create() does not write ' . $column . ', so a new actor loses it'
			);
		}
	}

	public function testEveryColumnHasAnUpdatePath(): void {
		$source = (string)file_get_contents(self::REQUEST);

		foreach (self::COLUMNS as $column) {
			$this->assertMatchesRegularExpression(
				"/->set\(\s*'" . $column . "'/",
				$source,
				'nothing updates ' . $column . ' on an existing actor'
			);
		}
	}

	public function testTheCacheParserResolvesTheMovedTarget(): void {
		$source = (string)file_get_contents(self::CACHE_BUILDER);

		$this->assertStringContainsString(
			'setMovedToActor(',
			$source,
			'a cached actor that moved is handed to the client with a `moved` stub only'
		);
	}
}
