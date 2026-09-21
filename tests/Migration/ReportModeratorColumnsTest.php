<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/** Where a report records who is handling it. */
class ReportModeratorColumnsTest extends TestCase {
	use ReadsTheSchema;

	public function testTheModeratorsAreBoundedUserIdsAndNotActorIds(): void {
		foreach (['assigned_to', 'action_taken_by'] as $column) {
			[$type, $options] = $this->column('social_report', $column);

			$this->assertSame(Types::STRING, $type);
			// a Nextcloud user id, which the server bounds at 64 characters —
			// an administrator moderates as a user of this server and need not
			// have a Social account at all
			$this->assertSame(64, $options['length']);
		}
	}

	public function testNothingIsAssignedUntilSomebodyTakesIt(): void {
		foreach (['assigned_to', 'action_taken_by', 'action_taken_at'] as $column) {
			// nullable, because a report is assigned to nobody far more often
			// than to somebody, and a report resolved before these columns
			// existed has no moderator and no moment recorded
			[, $options] = $this->column('social_report', $column);

			$this->assertFalse($options['notnull']);
		}
	}

	public function testTheMomentOfTheDecisionIsADate(): void {
		[$type] = $this->column('social_report', 'action_taken_at');

		$this->assertSame(Types::DATETIME, $type);
	}
}
