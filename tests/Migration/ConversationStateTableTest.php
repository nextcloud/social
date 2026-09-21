<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\ConversationsRequestBuilder;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * The one table a conversation needs.
 *
 * The conversations themselves are derived from the thread a message hangs in
 * and are stored nowhere; what has a row is what an account has *done* with a
 * thread.
 */
class ConversationStateTableTest extends TestCase {
	use ReadsTheSchema;

	private const TABLE = ConversationsRequestBuilder::TABLE_CONVERSATION_STATE;

	public function testTheTableIsTheOneTheCodeReadsAndWrites(): void {
		$this->assertNotEmpty($this->columnNames(self::TABLE));
	}

	public function testBothIdsAreStoredTheShapeTheRestOfTheSchemaJoinsBy(): void {
		foreach (['actor_id' => 'actor_id_prim', 'root_id' => 'root_id_prim'] as $id => $prim) {
			[$type, $options] = $this->column(self::TABLE, $prim);
			$this->assertSame(Types::STRING, $type, $prim);
			$this->assertSame(32, $options['length'], $prim . ': a prim is an md5');
			$this->assertTrue($options['notnull'], $prim);

			// a prim is one-way, and the writer needs the real id to store a row
			[$type, $options] = $this->column(self::TABLE, $id);
			$this->assertSame(Types::TEXT, $type, $id);
			$this->assertTrue($options['notnull'], $id);
		}
	}

	public function testBothMarkersAreAMessageAndNotAFlag(): void {
		// a flag would be cleared on every incoming direct message — a write on
		// the delivery path — and could not tell "read" from "read up to here"
		foreach (['read_nid', 'hidden_nid'] as $marker) {
			[$type, $options] = $this->column(self::TABLE, $marker);

			$this->assertSame(Types::BIGINT, $type, $marker);
			$this->assertTrue($options['notnull'], $marker);
			$this->assertSame(
				0,
				$options['default'],
				$marker . ': a thread with no row has been neither read nor dismissed,'
				. ' which is what an instance upgrading into this table starts with'
			);
		}
	}

	public function testAnAccountHasOneStatePerThread(): void {
		// two rows for one account and one thread would mean one of them
		// silently deciding what the user has read; the uniqueness is also what
		// makes the marker write safe to retry
		$unique = array_values(array_filter(
			$this->indexesOf(self::TABLE),
			static fn (array $index): bool => $index[2]
		));

		$this->assertCount(1, $unique);
		$this->assertSame(['actor_id_prim', 'root_id_prim'], $unique[0][0]);
		$this->assertSame('social_convo_ar', $unique[0][1]);
	}

	public function testTheOnlyIndexIsTheOneReadPath(): void {
		// its leading column also answers "every state of one account", which
		// is what deleting an account needs, so there is no second index
		$this->assertCount(1, $this->indexesOf(self::TABLE));
	}

	public function testTheTableCarriesAnAutoincrementKey(): void {
		$this->assertSame(['id'], $this->primaryKeyOf(self::TABLE));

		[$type, $options] = $this->column(self::TABLE, 'id');
		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['autoincrement']);
	}
}
