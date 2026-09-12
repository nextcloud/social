<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCP\IDBConnection;
use OCP\IRequest;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Fills the tables with a plausible amount of content and times the queries
 * that serve a timeline, because a query's cost cannot be judged on the dozen
 * rows a development instance has.
 *
 * Everything it writes carries the same host, so `--clean` can take it all
 * back out again and nothing else is touched.
 */
class Benchmark extends SocialCommand {
	/** every actor, note and follow this command writes lives under this host */
	public const HOST = 'benchmark.invalid';

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamDestRequest $streamDestRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private FollowsRequest $followsRequest,
		private AccountService $accountService,
		private ActorsRequest $actorsRequest,
		private IDBConnection $connection,
		private IRequest $request,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:benchmark')
			->setDescription('Seed a realistic amount of content and time the timeline queries')
			->addOption('actors', '', InputOption::VALUE_REQUIRED, 'remote actors to seed', '200')
			->addOption('notes', '', InputOption::VALUE_REQUIRED, 'notes to seed', '5000')
			->addOption('follows', '', InputOption::VALUE_REQUIRED, 'of those actors, how many the viewer follows', '150')
			->addOption('viewer', '', InputOption::VALUE_REQUIRED, 'the local account the timelines are read as', '')
			->addOption('seed-only', '', InputOption::VALUE_NONE, 'seed without timing')
			->addOption('time-only', '', InputOption::VALUE_NONE, 'time what is already seeded')
			->addOption('clean', '', InputOption::VALUE_NONE, 'remove everything this command wrote')
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'seed without asking (required with --no-interaction)'
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('clean')) {
			$output->writeln('removed ' . $this->clean() . ' seeded rows');

			return 0;
		}

		$viewer = $this->viewer((string)$input->getOption('viewer'), $output);
		if ($viewer === null) {
			return 1;
		}

		if (!$input->getOption('time-only')) {
			$confirmed = $this->confirmSeeding(
				$input,
				$output,
				(int)$input->getOption('actors'),
				(int)$input->getOption('notes')
			);
			if (!$confirmed) {
				return 1;
			}

			$this->seed(
				(int)$input->getOption('actors'),
				(int)$input->getOption('notes'),
				(int)$input->getOption('follows'),
				$viewer,
				$output
			);
		}

		if (!$input->getOption('seed-only')) {
			$this->time($viewer, $output);
		}

		return 0;
	}

	/**
	 * This command ships to every install (appinfo/info.xml registers it), and
	 * seeding writes thousands of rows into whichever database occ is pointed
	 * at. There is nothing in the name to warn somebody that `social:benchmark`
	 * is not read-only, so it asks — and refuses to guess when nobody is
	 * there to answer.
	 */
	private function confirmSeeding(InputInterface $input, OutputInterface $output, int $actors, int $notes): bool {
		$output->writeln(
			'<error>This writes ' . $notes . ' notes and ' . $actors
			. ' remote actors into the database this occ is pointed at.</error>'
		);
		$output->writeln(
			'Everything it writes carries the host <info>' . self::HOST
			. '</info>, so "--clean" takes it all back out — but do not run it on a'
			. ' production instance.'
		);
		$output->writeln('');

		if ($input->getOption('force')) {
			return true;
		}

		if (!$input->isInteractive()) {
			$output->writeln(
				'<error>Refusing to seed non-interactively without --force.</error>'
			);

			return false;
		}

		$question = new ConfirmationQuestion(
			'<info>Seed this database?</info> (y/N) ', false, '/^(y|Y)/i'
		);

		if ((bool)$this->questionHelper()->ask($input, $output, $question)) {
			return true;
		}

		$output->writeln('cancelled, nothing was written.');

		return false;
	}

	private function viewer(string $username, OutputInterface $output): ?Person {
		try {
			if ($username === '') {
				$actors = $this->actorsRequest->getAll();
				if ($actors === []) {
					$output->writeln('<error>no local account to read as; create one first</error>');

					return null;
				}

				return $actors[0];
			}

			return $this->accountService->getActor($username);
		} catch (\Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return null;
		}
	}

	private function seed(int $actors, int $notes, int $follows, Person $viewer, OutputInterface $output): void {
		$output->writeln(sprintf('seeding %d actors, %d notes, %d follows…', $actors, $notes, $follows));

		$ids = [];
		for ($i = 0; $i < $actors; $i++) {
			$id = 'https://' . self::HOST . '/users/actor' . $i;
			$ids[] = $id;

			$actor = new Person();
			$actor->setId($id);
			$actor->setPreferredUsername('actor' . $i)
				->setFollowers($id . '/followers');
			$actor->setAccount('actor' . $i . '@' . self::HOST);
			$this->cacheActorsRequest->save($actor);

			if ($i < $follows) {
				$follow = new Follow();
				$follow->setId($id . '/follow/' . $viewer->getPreferredUsername());
				$follow->setActorId($viewer->getId());
				$follow->setObjectId($id);
				// the followed actor's followers collection, which is what the
				// home timeline matches stream_dest rows against
				$follow->setFollowId($id . '/followers');
				$this->followsRequest->save($follow);
				$this->followsRequest->accepted($follow);
			}
		}

		$now = time();
		for ($i = 0; $i < $notes; $i++) {
			$author = $ids[$i % count($ids)];
			$note = new Note();
			$note->setId('https://' . self::HOST . '/notes/' . $i);
			$note->setAttributedTo($author);
			$note->setTo(ACore::CONTEXT_PUBLIC);
			$note->addCc($author . '/followers');
			$note->setVisibility('public');
			$note->setContent('<p>seeded note ' . $i . ' #benchmark</p>');
			// spread them over the last thirty days, newest first
			$note->setPublishedTime($now - $i * 500);
			$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $now - $i * 500));
			$this->streamRequest->save($note);
			$this->streamDestRequest->generateStreamDest($note);
		}

		$output->writeln('seeded.');
	}

	private function time(Person $viewer, OutputInterface $output): void {
		$this->streamRequest->setViewer($viewer);

		$output->writeln('');
		$output->writeln('timing the timeline queries as ' . $viewer->getPreferredUsername() . ':');

		foreach ([ProbeOptions::HOME, ProbeOptions::PUBLIC, ProbeOptions::DIRECT, ProbeOptions::NOTIFICATIONS] as $probe) {
			foreach ([20, 100] as $limit) {
				$options = new ProbeOptions($this->request);
				$options->setProbe($probe)->setLimit($limit);

				// the first read warms the caches; the reported number is the
				// second, which is what a served request actually costs
				$this->measure($options);
				$elapsed = $this->measure($options, $rows);

				$output->writeln(sprintf(
					'  %-14s limit %-4d %7.1f ms  (%d rows)',
					$probe, $limit, $elapsed, $rows
				));
			}
		}
	}

	private function measure(ProbeOptions $options, ?int &$rows = null): float {
		$start = microtime(true);
		$streams = $this->streamRequest->getTimeline($options);
		$rows = count($streams);

		return (microtime(true) - $start) * 1000;
	}

	/**
	 * Everything seeded carries the host in its id, so one LIKE per table
	 * takes it all back out. A full scan is the right trade here: this runs
	 * by hand on a development instance, never on a request.
	 */
	private function clean(): int {
		$like = '%' . $this->connection->escapeLikeParameter(self::HOST) . '%';
		$removed = 0;

		foreach ([
			[CoreRequestBuilder::TABLE_STREAM, 'id'],
			[CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id'],
			[CoreRequestBuilder::TABLE_CACHE_ACTORS, 'id'],
			[CoreRequestBuilder::TABLE_FOLLOWS, 'object_id'],
		] as [$table, $column]) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->like($column, $qb->createNamedParameter($like)));
			$removed += $qb->executeStatement();
		}

		return $removed;
	}
}
