<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Moves whose job died — the worker was killed, the server restarted —
 * while they ran. Core drops a queued job before it runs it, so nothing
 * else would ever end such a move, and it would hold the account for good:
 * "Try again" waits for a failed move, a new one for none under way.
 *
 * A move counts as stopped when it is `running`, has not been written to
 * for AFTER — a running move writes as it copies, blob by blob and page by
 * page of follows — and no job for it waits in the queue. It is queued
 * again RESUMES times (`progress.resumed`, forgotten when the person goes
 * on with it), then fails with `move-interrupted`, so the person can try
 * again or call it off. A move away that stopped while the DID was handed
 * over is first asked of the directory: once the DID points at the other
 * server, the move goes on from the activation, and can no longer be
 * called off. A move here driven by a tool waits for the person's decision
 * again before the account is active, and is done after it.
 */
class StaleMoves {
	/** how long a running move goes without being written to before it counts as stopped */
	public const AFTER = 3600;
	/** how often a stopped move is queued again before it fails */
	public const RESUMES = 1;
	/** how many stopped moves one run looks at */
	private const LIMIT = 20;

	public function __construct(
		private AtprotoMoveRequest $moves,
		private IJobList $jobList,
		private PlcClient $plc,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return int how many stopped moves were queued again or ended
	 */
	public function recover(): int {
		$recovered = 0;
		foreach ($this->moves->getStale($this->time->getTime() - self::AFTER, self::LIMIT) as $move) {
			if ($this->jobList->has(AtprotoMove::class, ['move' => $move->id])) {
				continue;
			}
			try {
				$this->settleHandOver($move);
			} catch (AtprotoException $e) {
				$this->logger->info('Stopped Bluesky account move left for the next run: the directory could not be asked', ['did' => $move->did, 'exception' => $e]);
				continue;
			}
			$resumed = (int)($move->progress['resumed'] ?? 0);
			if ($resumed < self::RESUMES) {
				$move->progress['resumed'] = $resumed + 1;
				$this->moves->update($move);
				$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);
				$this->logger->warning('Bluesky account move queued again: its job stopped', ['did' => $move->did, 'direction' => $move->direction, 'step' => $move->step]);
			} else {
				$this->end($move);
				$this->moves->update($move);
				$this->logger->warning('Bluesky account move ended: its job stopped again', ['did' => $move->did, 'direction' => $move->direction, 'step' => $move->step, 'state' => $move->state]);
			}
			$recovered++;
		}

		return $recovered;
	}

	/**
	 * A move away that stopped while the DID was handed over goes on from
	 * the activation once the directory names the other server.
	 *
	 * @throws AtprotoException when the directory cannot be asked
	 */
	private function settleHandOver(Move $move): void {
		if ($move->direction !== Move::AWAY) {
			return;
		}
		if ($move->step === Move::STEP_IDENTITY) {
			$endpoint = (string)($this->plc->data($move->did)['services']['atproto_pds']['endpoint'] ?? '');
			if ($endpoint !== '' && rtrim($endpoint, '/') === rtrim($move->pds, '/')) {
				$move->step = Move::STEP_ACTIVATE;
			}
		}
		$move->progress['stage'] = MoveAwayService::stageOf($move);
	}

	/**
	 * A move stopped once too often: failed, for the person to try again or
	 * call off; driven by a tool, waiting for the person's decision on the
	 * blobs again, or done once the account is active here.
	 */
	private function end(Move $move): void {
		if ($move->direction !== Move::INBOUND) {
			$move->fail('The move stopped before it was finished', 'move-interrupted');

			return;
		}
		if (in_array($move->step, [Move::STEP_FOLLOWS, Move::STEP_POSTS], true)) {
			$move->step = Move::STEP_DONE;
			$move->state = Move::DONE;

			return;
		}
		$move->state = Move::NEEDS_DECISION;
		$move->progress = ['code' => 'blobs-missing'] + $move->progress;
	}
}
