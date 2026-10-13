<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Repository;

use Closure;
use OCA\Social\Atproto\Model\RepoHead;
use OCA\Social\Atproto\Protocol\Cid;

/**
 * The in-memory tables with a second writer in them: `$other` is a whole
 * commit of the same repository that runs once, at the first chance a
 * concurrent process would get. A commit that locks the head gives it
 * that chance while waiting for the lock and then reads what it left; a
 * commit that does not lock has it run after its own pre-state was read,
 * which is the race two web requests run.
 */
class InterleavingRepoRequest extends InMemoryRepoRequest {
	public ?Closure $other = null;

	#[\Override]
	public function lockHead(string $did): ?RepoHead {
		$this->runOther();

		return parent::lockHead($did);
	}

	#[\Override]
	public function putRecord(string $did, string $collection, string $rkey, Cid $cid, string $bytes, string $localId): void {
		$this->runOther();
		parent::putRecord($did, $collection, $rkey, $cid, $bytes, $localId);
	}

	private function runOther(): void {
		$other = $this->other;
		$this->other = null;
		if ($other !== null) {
			$other();
		}
	}
}
