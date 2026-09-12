<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\RequestQueue;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Retries or drops queued deliveries.
 *
 * `occ social:queue:status` could tell an administrator that N deliveries to a
 * host keep failing and nothing could be done about it: a delivery that fails
 * for a reason since fixed sat there until its fifteenth attempt deleted it,
 * and one that will never succeed (a peer that is gone, an activity a peer
 * refuses) kept a worker busy until then. This is the other half: put them
 * back in the queue, or take them out of it.
 *
 * It reads and writes the queue tables directly rather than through the queue
 * services, in the same way `social:benchmark` does — the services have no
 * "reset this row" operation, and inventing one for a maintenance command
 * would put a footgun in the delivery path.
 */
class QueueRetry extends SocialCommand {
	/** how many rows one run will touch unless --limit says otherwise */
	private const DEFAULT_LIMIT = 500;

	/**
	 * Both queue tables carry the same (id, token, status, tries) shape and
	 * the same status numbering — StreamQueue::STATUS_* and
	 * RequestQueue::STATUS_* are 0/1/9 in both — so one set of constants
	 * serves both.
	 */

	public function __construct(
		private IDBConnection $connection,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:queue:retry')
			->addOption(
				'token', 't', InputOption::VALUE_REQUIRED,
				'act on one delivery only, by its token', ''
			)
			->addOption(
				'min-tries', '', InputOption::VALUE_REQUIRED,
				'act on requests that have already failed at least this many times', '1'
			)
			->addOption(
				'limit', '', InputOption::VALUE_REQUIRED,
				'how many rows to touch at most', (string)self::DEFAULT_LIMIT
			)
			->addOption(
				'stream', '', InputOption::VALUE_NONE,
				'act on the inbound stream queue instead of the outbound delivery queue'
			)
			->addOption(
				'flush', '', InputOption::VALUE_NONE,
				'delete the matching rows instead of queueing them again'
			)
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'do not ask for confirmation (required with --no-interaction)'
			)
			->setDescription('Retry or drop queued deliveries that keep failing');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$table = $input->getOption('stream')
			? CoreRequestBuilder::TABLE_STREAM_QUEUE
			: CoreRequestBuilder::TABLE_REQUEST_QUEUE;
		$token = (string)$input->getOption('token');
		$minTries = max(0, (int)$input->getOption('min-tries'));
		$limit = max(1, (int)$input->getOption('limit'));
		$flush = (bool)$input->getOption('flush');

		$matched = $this->matching($table, $token, $minTries, $limit);
		if ($matched === []) {
			$output->writeln('nothing in <info>' . $table . '</info> matches.');

			return 0;
		}

		$this->report($output, $table, $matched, $limit);

		if (!$this->confirm($input, $output, $flush, count($matched))) {
			return $input->isInteractive() ? 0 : 1;
		}

		$ids = array_map('intval', array_column($matched, 'id'));
		$touched = $flush ? $this->flush($table, $ids) : $this->retry($table, $ids);

		$output->writeln(
			($flush ? 'dropped ' : 'queued again: ') . $touched . ' row(s).'
		);

		if (!$flush) {
			$output->writeln('They are sent on the next run of the queue cron, or by "occ social:queue:process".');
		}

		return 0;
	}

	/**
	 * The rows this run would act on.
	 *
	 * A successful row (status 9) is left alone: it is history, not a pending
	 * delivery, and re-queueing it would send the activity a second time.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function matching(string $table, string $token, int $minTries, int $limit): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', 'token', 'status', 'tries')
			->from($table)
			->andWhere($qb->expr()->neq(
				'status',
				$qb->createNamedParameter(RequestQueue::STATUS_SUCCESS, IQueryBuilder::PARAM_INT)
			))
			->orderBy('tries', 'desc')
			->addOrderBy('id', 'asc')
			->setMaxResults($limit);

		if ($token !== '') {
			$qb->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		} else {
			$qb->andWhere($qb->expr()->gte(
				'tries',
				$qb->createNamedParameter($minTries, IQueryBuilder::PARAM_INT)
			));
		}

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * @param list<array<string, mixed>> $matched
	 */
	private function report(OutputInterface $output, string $table, array $matched, int $limit): void {
		$tries = array_map('intval', array_column($matched, 'tries'));
		$tokens = array_unique(array_map('strval', array_column($matched, 'token')));

		$output->writeln(
			count($matched) . ' row(s) in <info>' . $table . '</info>, '
			. count($tokens) . ' distinct delivery token(s), '
			. 'between ' . min($tries) . ' and ' . max($tries) . ' failed attempts.'
		);

		if (count($matched) === $limit) {
			$output->writeln(
				'<comment>That is the --limit: run this again to work through the rest.</comment>'
			);
		}

		$output->writeln('');
	}

	private function confirm(InputInterface $input, OutputInterface $output, bool $flush, int $rows): bool {
		if ($input->getOption('force')) {
			return true;
		}

		if (!$input->isInteractive()) {
			$output->writeln('<error>Refusing to run non-interactively without --force.</error>');

			return false;
		}

		$question = new ConfirmationQuestion(
			$flush
				? '<error>Delete ' . $rows . ' queued row(s)? Those activities are never delivered.</error> (y/N) '
				: '<info>Queue ' . $rows . ' row(s) for delivery again?</info> (y/N) ',
			false,
			'/^(y|Y)/i'
		);

		if ((bool)$this->questionHelper()->ask($input, $output, $question)) {
			return true;
		}

		$output->writeln('cancelled, the queue was left alone.');

		return false;
	}

	/**
	 * Back to standby with the attempt count cleared, so the row gets the full
	 * run of retries again rather than being abandoned on its next failure.
	 *
	 * @param list<int> $ids
	 */
	private function retry(string $table, array $ids): int {
		$touched = 0;
		foreach (array_chunk($ids, 100) as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb->update($table)
				->set('status', $qb->createNamedParameter(RequestQueue::STATUS_STANDBY, IQueryBuilder::PARAM_INT))
				->set('tries', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$touched += $qb->executeStatement();
		}

		return $touched;
	}

	/**
	 * @param list<int> $ids
	 */
	private function flush(string $table, array $ids): int {
		$touched = 0;
		foreach (array_chunk($ids, 100) as $chunk) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$touched += $qb->executeStatement();
		}

		return $touched;
	}
}
