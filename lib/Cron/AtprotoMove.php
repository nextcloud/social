<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\Move\PostRepublisher;
use OCA\Social\Db\AtprotoMoveRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Carries a move of a Bluesky account on (§13), whatever is left of it:
 * away — the repository, the blobs, the preferences, the DID, the
 * activation — or here — the repository, the blobs, the preferences, then,
 * once the person entered the e-mailed code, the DID and the follows —
 * or, for one the other side moved here, the follows once it is active;
 * or, asked to `republish`, a page of the posts a finished move took off
 * Bluesky.
 */
class AtprotoMove extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private AtprotoMoveRequest $moves,
		private MoveAwayService $away,
		private MoveInService $in,
		private InboundMoveService $inbound,
		private PostRepublisher $republisher,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$moveId = is_array($argument) ? (int)($argument['move'] ?? 0) : 0;
		$move = $moveId > 0 ? $this->moves->get($moveId) : null;
		if ($move === null) {
			return;
		}
		if (is_array($argument) && ($argument['republish'] ?? false) === true) {
			$this->republisher->run($move);

			return;
		}
		if ($move->direction === Move::IN) {
			$this->in->run($move);
		} elseif ($move->direction === Move::INBOUND) {
			$this->inbound->run($move);
		} else {
			$this->away->run($moveId);
		}
	}
}
