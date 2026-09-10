<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Command;

use OCA\Social\Command\CheckInstall;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * `social:check:install` is the command an administrator runs when federation
 * is broken, and `--index` is the most destructive thing in the app after
 * `social:reset`: it truncates both index tables before rebuilding them, so an
 * interrupted run leaves every timeline empty.
 */
class CheckInstallTest extends CommandTestCase {
	public function testPlainRunReportsTheConfiguration(): void {
		$tester = $this->tester(CheckInstall::class);

		$code = $this->runNonInteractive($tester, []);
		$display = $tester->getDisplay();

		$this->assertSame(0, $code);
		$this->assertStringContainsString('invalid followers removed', $display);
		$this->assertStringContainsString('Your current configuration', $display);
	}

	public function testTheAdviceItPrintsNamesACommandThatExists(): void {
		$tester = $this->tester(CheckInstall::class);
		$this->runNonInteractive($tester, []);
		$display = $tester->getDisplay();

		if (!str_contains($display, 'social:reset')) {
			// the addresses match on this instance, so no advice was printed
			$this->addToAssertionCount(1);

			return;
		}

		$this->assertMatchesRegularExpression('/occ social:reset --uri=/', $display);

		// the point of the assertion: the option the advice names is real
		$reset = $this->command(\OCA\Social\Command\Reset::class);
		$this->assertTrue($reset->getDefinition()->hasOption('uri'));
	}

	public function testIndexIsRefusedNonInteractivelyWithoutForce(): void {
		$connection = Server::get(IDBConnection::class);
		$before = $this->countRows($connection, CoreRequestBuilder::TABLE_STREAM_DEST);
		$tester = $this->tester(CheckInstall::class);

		// A ConfirmationQuestion answers itself with false when nobody is
		// there, so this used to truncate nothing and exit 0 — and if the
		// confirmation had defaulted the other way it would have truncated
		// the index of a production instance from a cron script.
		$code = $this->runNonInteractive($tester, ['--index' => true]);

		$this->assertSame(1, $code);
		$this->assertSame($before, $this->countRows($connection, CoreRequestBuilder::TABLE_STREAM_DEST));
	}

	public function testDecliningTheIndexRebuildLeavesTheIndexAlone(): void {
		$connection = Server::get(IDBConnection::class);
		$before = $this->countRows($connection, CoreRequestBuilder::TABLE_STREAM_DEST);
		$tester = $this->tester(CheckInstall::class);

		$code = $this->runInteractive($tester, ['--index' => true], ['n']);

		$this->assertSame(0, $code);
		$this->assertSame($before, $this->countRows($connection, CoreRequestBuilder::TABLE_STREAM_DEST));
	}

	/**
	 * The rebuild itself, on whatever this instance holds: it must survive a
	 * table it has to page through, and it must leave the index in a state
	 * that describes the streams — the failure mode being an empty index.
	 */
	public function testForcedIndexRebuildRestoresTheIndex(): void {
		$connection = Server::get(IDBConnection::class);
		$streams = $this->countRows($connection, CoreRequestBuilder::TABLE_STREAM);
		if ($streams === 0) {
			$this->markTestSkipped('no streams on this instance to index');
		}

		$tester = $this->tester(CheckInstall::class);

		$code = $this->runNonInteractive($tester, ['--index' => true, '--force' => true]);
		$display = $tester->getDisplay();

		$this->assertContains($code, [0, 1], $display);
		if ($code === 1) {
			// rows it could not parse are allowed, being silent about them is not
			$this->assertStringContainsString('could not be indexed', $display);
		}
		$this->assertGreaterThan(
			0,
			$this->countRows($connection, CoreRequestBuilder::TABLE_STREAM_DEST),
			'the rebuild truncates the index first, so an empty index means it never refilled it'
		);
	}

	private function countRows(IDBConnection $connection, string $table): int {
		$qb = $connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))->from($table);
		$cursor = $qb->executeQuery();
		$total = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $total;
	}
}
