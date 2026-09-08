<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Model\StreamAction;
use OCA\Social\Service\StreamActionService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The per-viewer flag row (liked/boosted/replied/bookmarked) against the real
 * table, through the same StreamActionService the API uses. Pins the two things
 * only a real database shows: per-field updates leave the other flags alone
 * (the row is written field-wise to avoid clobbering concurrent actions), and
 * re-setting a flag to its current value is a no-op — not an UPDATE without a
 * SET clause, which is invalid SQL and 500'd the favourite endpoint.
 */
class StreamActionsFlagsTest extends TestCase {
	private const ACTOR = 'https://cloud.example.org/aflags/users/viewer';
	private const STREAM = 'https://remote.example/aflags/notes/1';

	private StreamActionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(StreamActionService::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$request = Server::get(\OCA\Social\Db\StreamActionsRequest::class);
		$action = new StreamAction(self::ACTOR, self::STREAM);
		$request->delete($action);
	}

	private function values(): array {
		return Server::get(\OCA\Social\Db\StreamActionsRequest::class)
			->getAction(self::ACTOR, self::STREAM)
			->getValues();
	}

	public function testEachFlagIsIndependent(): void {
		$this->service->setActionBool(self::ACTOR, self::STREAM, StreamAction::LIKED, true);
		$this->service->setActionBool(self::ACTOR, self::STREAM, StreamAction::BOOKMARKED, true);
		$this->service->setActionBool(self::ACTOR, self::STREAM, StreamAction::BOOSTED, true);

		$this->service->setActionBool(self::ACTOR, self::STREAM, StreamAction::LIKED, false);

		$values = $this->values();
		$this->assertFalse($values[StreamAction::LIKED], 'unliking clears only the like');
		$this->assertTrue($values[StreamAction::BOOKMARKED], 'the bookmark survives');
		$this->assertTrue($values[StreamAction::BOOSTED], 'the boost survives');
	}

	public function testSettingAFlagToItsCurrentValueIsANoOpNotAnSqlError(): void {
		$this->service->setActionBool(self::ACTOR, self::STREAM, StreamAction::LIKED, true);
		// used to build "UPDATE social_stream_act WHERE ..." — no SET clause
		$this->service->setActionBool(self::ACTOR, self::STREAM, StreamAction::LIKED, true);

		$this->assertTrue($this->values()[StreamAction::LIKED]);
	}
}
