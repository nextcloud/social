<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Cron;

use OCA\Social\Cron\RunImport;
use OCA\Social\Db\ImportsRequest;
use OCA\Social\Model\ImportJob;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ImportQueueService;
use OCA\Social\Tests\Integration\PeakMemory;
use OCP\BackgroundJob\IJobList;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * An import an account queued, run by the background job the way cron runs
 * it, over a list five times as long: the peak memory must not grow with
 * it beyond the list itself.
 *
 * The list is read whole — the service says so, and a blocked-domains file
 * of five thousand lines is a quarter of a megabyte — so what is pinned is
 * the loop that writes it: one row per domain, nothing kept per row. A
 * memo that grew with every block, a model kept for each, would be several
 * kilobytes a row here, megabytes over the larger list.
 */
class RunImportMemoryTest extends TestCase {
	private string $userId = '';

	/** @var list<int> */
	private array $imports = [];

	protected function setUp(): void {
		parent::setUp();
		$this->userId = 'runimportmem' . bin2hex(random_bytes(4));
		Server::get(IUserManager::class)->createUser($this->userId, bin2hex(random_bytes(16)) . 'Aa1!');
		Server::get(AccountService::class)->createActor($this->userId, $this->userId);
	}

	protected function tearDown(): void {
		$jobs = Server::get(IJobList::class);
		foreach ($this->imports as $id) {
			$jobs->remove(RunImport::class, ['import' => $id]);
			Server::get(ImportsRequest::class)->delete($id);
		}
		// the account's domain blocks and everything else go with the user
		Server::get(IUserManager::class)->get($this->userId)?->delete();
		parent::tearDown();
	}

	/** Queues a blocked-domains file of `$count` lines and returns the import's id. */
	private function queue(int $count, string $tag): int {
		$file = Server::get(ITempManager::class)->getTemporaryFile('.csv');
		$this->assertIsString($file);
		// one domain a line, as Mastodon exports the domains an account blocked
		$lines = [];
		for ($i = 0; $i < $count; $i++) {
			$lines[] = 'host' . $i . '.' . $tag . '.runimportmem.example';
		}
		file_put_contents($file, implode("\n", $lines) . "\n");

		$job = Server::get(ImportQueueService::class)->queue($this->userId, ImportJob::KIND_DOMAIN_BLOCKS, $file);
		$this->imports[] = $job->getId();

		return $job->getId();
	}

	/** The peak of the background job over a list of `$count` domains. */
	private function peakOf(int $count, string $tag): int {
		$id = $this->queue($count, $tag);
		$job = Server::get(RunImport::class);
		$run = new ReflectionMethod(RunImport::class, 'run');

		$peak = PeakMemory::of(static fn () => $run->invoke($job, ['import' => $id]));

		$done = Server::get(ImportsRequest::class)->getById($id);
		$this->assertSame(ImportJob::STATUS_DONE, $done?->getStatus());
		$this->assertSame($count, $done?->getDone(), 'every domain was blocked');

		return $peak;
	}

	public function testABlockedDomainsImportHoldsOnlyItsList(): void {
		$this->peakOf(20, 'warm');
		$small = $this->peakOf(1000, 'small');
		$large = $this->peakOf(5000, 'large');

		// the file and the list of domains read from it, which the service
		// holds whole by design: about 300 bytes a line, as a string and as
		// an array entry
		$input = 4000 * 300;
		PeakMemory::assertFlat($small + $input, $large, 'Cron\\RunImport (blocked domains)');
	}
}
