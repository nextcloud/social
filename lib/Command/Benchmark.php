<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
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
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCP\DB\QueryBuilder\IQueryBuilder;
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

	/** How many seeded posts one round of `--clean` takes with it. */
	private const CLEAN_PAGE = 1000;

	/**
	 * How many rows one `INSERT` carries.
	 *
	 * Five hundred tuples of a dozen columns is a statement of a few thousand
	 * placeholders — comfortably inside every driver's limit, and large enough
	 * that the per-statement cost stops mattering.
	 */
	private const CHUNK = 500;

	/** `--followers`, read in `execute()` and used by the seeder. */
	private int $followersWanted = 0;

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
			->addOption(
				'followers', '', InputOption::VALUE_REQUIRED,
				'seeded actors that follow the viewer back, to size the delivery fan-out', '0'
			)
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

			$this->followersWanted = (int)$input->getOption('followers');
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

	/**
	 * Writes the rows, in bulk.
	 *
	 * **Not through the model layer.** An earlier version of this built a
	 * `Note`, called `StreamRequest::save()` and then `generateStreamDest()`
	 * for each post: three statements and a transaction per row, which is
	 * about 400 rows a second and makes ten million posts a seven-hour wait —
	 * so nobody ever seeded enough to find out what the queries cost, which is
	 * the entire purpose of the command. Multi-row `INSERT`s of
	 * `self::CHUNK` tuples do the same work at tens of thousands of rows a
	 * second.
	 *
	 * The cost of going around the model is that this has to write the columns
	 * the model would have written, and a column added later is one this
	 * forgets. That is the right trade for a development-only command and the
	 * wrong one for anything else: what is seeded has to be *shaped* like real
	 * data — the prim hashes, the recipient rows, the follower collections —
	 * because a query plan is only worth measuring against rows the planner
	 * sees the way it sees real ones.
	 */
	private function seed(int $actors, int $notes, int $follows, Person $viewer, OutputInterface $output): void {
		$followers = max(0, $this->followersWanted);
		$output->writeln(sprintf(
			'seeding %s actors, %s notes, %s follows, %s followers…',
			number_format($actors), number_format($notes),
			number_format($follows), number_format($followers)
		));

		$started = microtime(true);
		$ids = $this->seedActors($actors, $output);
		$this->seedFollows($ids, $follows, $followers, $viewer, $output);
		$this->seedNotes($ids, $notes, $output);

		$output->writeln(sprintf('seeded in %.1f s.', microtime(true) - $started));
	}

	/**
	 * The remote accounts everything else hangs off.
	 *
	 * @return string[] their ids, in the order they were written
	 */
	private function seedActors(int $actors, OutputInterface $output): array {
		$ids = [];
		$rows = [];
		$now = $this->stamp();

		for ($i = 0; $i < $actors; $i++) {
			$id = 'https://' . self::HOST . '/users/actor' . $i;
			$ids[] = $id;
			$rows[] = [
				'id' => $id,
				'id_prim' => md5($id),
				'type' => Person::TYPE,
				'account' => 'actor' . $i . '@' . self::HOST,
				'preferred_username' => 'actor' . $i,
				'name' => 'Actor ' . $i,
				'inbox' => $id . '/inbox',
				'shared_inbox' => 'https://' . self::HOST . '/inbox',
				'outbox' => $id . '/outbox',
				'followers' => $id . '/followers',
				'following' => $id . '/following',
				'source' => '{}',
				'details' => '{}',
				'local' => 0,
				'creation' => $now,
			];

			if (count($rows) >= self::CHUNK) {
				$this->insertMany(CoreRequestBuilder::TABLE_CACHE_ACTORS, $rows);
				$rows = [];
				$this->progress($output, 'actors', $i + 1, $actors);
			}
		}

		$this->insertMany(CoreRequestBuilder::TABLE_CACHE_ACTORS, $rows);
		$this->progress($output, 'actors', $actors, $actors, true);

		return $ids;
	}

	/**
	 * Who follows whom.
	 *
	 * Two directions, because they cost different things. The viewer following
	 * seeded accounts is what the **home timeline** reads: its page query
	 * resolves the viewer's follows and then the posts addressed to each of
	 * their follower collections. Seeded accounts following the viewer is what
	 * **delivery** reads: one queue row per distinct inbox when the viewer
	 * posts. An instance seeded with only the first measures reads and says
	 * nothing about writes.
	 *
	 * @param string[] $ids
	 */
	private function seedFollows(
		array $ids, int $follows, int $followers, Person $viewer, OutputInterface $output,
	): void {
		if ($ids === []) {
			return;
		}

		$rows = [];
		$now = $this->stamp();
		$written = 0;
		$wanted = min($follows, count($ids)) + min($followers, count($ids));

		for ($i = 0; $i < min($follows, count($ids)); $i++) {
			$id = $ids[$i];
			// the followed actor's **followers collection**, which is what a
			// recipient row names — not the actor itself
			$rows[] = $this->followRow(
				$id . '/follow/' . $viewer->getPreferredUsername(),
				$viewer->getId(), $id, $id . '/followers', $now
			);
			$written++;
			if (count($rows) >= self::CHUNK) {
				$this->insertMany(CoreRequestBuilder::TABLE_FOLLOWS, $rows);
				$rows = [];
				$this->progress($output, 'follows', $written, $wanted);
			}
		}

		for ($i = 0; $i < min($followers, count($ids)); $i++) {
			$id = $ids[$i];
			$rows[] = $this->followRow(
				$id . '/follows-back/' . $viewer->getPreferredUsername(),
				$id, $viewer->getId(), $viewer->getFollowers(), $now
			);
			$written++;
			if (count($rows) >= self::CHUNK) {
				$this->insertMany(CoreRequestBuilder::TABLE_FOLLOWS, $rows);
				$rows = [];
				$this->progress($output, 'follows', $written, $wanted);
			}
		}

		$this->insertMany(CoreRequestBuilder::TABLE_FOLLOWS, $rows);
		$this->progress($output, 'follows', $wanted, $wanted, true);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function followRow(
		string $id, string $actorId, string $objectId, string $followId, string $now,
	): array {
		return [
			'id' => $id,
			'id_prim' => md5($id),
			'type' => Follow::TYPE,
			'actor_id' => $actorId,
			'actor_id_prim' => md5($actorId),
			'object_id' => $objectId,
			'object_id_prim' => md5($objectId),
			'follow_id' => $followId,
			'follow_id_prim' => md5($followId),
			'accepted' => 1,
			'creation' => $now,
		];
	}

	/**
	 * The posts, and the recipient rows that put them in a timeline.
	 *
	 * Both are written here rather than one being derived from the other,
	 * because `generateStreamDest()` is a statement per recipient and the
	 * whole point of this path is not to do that. What it writes is what that
	 * method would have: the public collection, and the author's own follower
	 * collection.
	 *
	 * @param string[] $ids
	 */
	private function seedNotes(array $ids, int $notes, OutputInterface $output): void {
		if ($ids === [] || $notes < 1) {
			return;
		}

		$streamRows = [];
		$destRows = [];
		$now = time();
		$publicPrim = md5(ACore::CONTEXT_PUBLIC);

		for ($i = 0; $i < $notes; $i++) {
			$author = $ids[$i % count($ids)];
			$id = 'https://' . self::HOST . '/notes/' . $i;
			$prim = md5($id);
			// spread over the recent past, newest first, so that a page of
			// twenty is a page of twenty *recent* posts as it would be in life
			$published = $now - $i * 5;
			$followers = $author . '/followers';

			$streamRows[] = [
				'id' => $id,
				'id_prim' => $prim,
				'type' => Note::TYPE,
				'subtype' => '',
				'to' => ACore::CONTEXT_PUBLIC,
				'to_array' => '[]',
				'cc' => json_encode([$followers]),
				'bcc' => '[]',
				'content' => '<p>seeded note ' . $i . ' #benchmark</p>',
				'summary' => '',
				'published' => gmdate('Y-m-d\TH:i:s\Z', $published),
				'published_time' => $this->stamp($published),
				'attributed_to' => $author,
				'attributed_to_prim' => md5($author),
				'visibility' => Stream::TYPE_PUBLIC,
				'hashtags' => '["benchmark"]',
				'details' => '{}',
				'source' => '{}',
				'instances' => '[]',
				'attachments' => '[]',
				'cache' => '{}',
				'local' => 0,
				'filter_duplicate' => 0,
				'creation' => $this->stamp($published),
			];

			$destRows[] = ['stream_id' => $prim, 'actor_id' => $publicPrim, 'type' => 'recipient', 'subtype' => 'to'];
			$destRows[] = ['stream_id' => $prim, 'actor_id' => md5($followers), 'type' => 'recipient', 'subtype' => 'cc'];

			if (count($streamRows) >= self::CHUNK) {
				$this->insertMany(CoreRequestBuilder::TABLE_STREAM, $streamRows);
				$this->insertMany(CoreRequestBuilder::TABLE_STREAM_DEST, $destRows);
				$streamRows = [];
				$destRows = [];
				$this->progress($output, 'notes', $i + 1, $notes);
			}
		}

		$this->insertMany(CoreRequestBuilder::TABLE_STREAM, $streamRows);
		$this->insertMany(CoreRequestBuilder::TABLE_STREAM_DEST, $destRows);
		$this->progress($output, 'notes', $notes, $notes, true);
	}

	/**
	 * One `INSERT` carrying many rows.
	 *
	 * Written as SQL rather than through `IQueryBuilder`, which has no
	 * multi-row form: the placeholders are positional and every value is bound,
	 * so it is the same escaping the builder would do and it runs unchanged on
	 * MySQL, PostgreSQL and SQLite. The column list comes from the first row,
	 * and every row is required to have the same keys — which they do, because
	 * each is built by one loop above.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function insertMany(string $table, array $rows): void {
		if ($rows === []) {
			return;
		}

		$columns = array_keys($rows[0]);
		$quoted = array_map(static fn (string $c): string => '`' . $c . '`', $columns);
		if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
			$quoted = array_map(static fn (string $c): string => '"' . $c . '"', $columns);
		}

		$tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
		$values = [];
		foreach ($rows as $row) {
			foreach ($columns as $column) {
				$values[] = $row[$column];
			}
		}

		$sql = 'INSERT INTO `*PREFIX*' . $table . '` (' . implode(', ', $quoted) . ') VALUES '
			. implode(', ', array_fill(0, count($rows), $tuple));

		$this->connection->executeStatement($sql, $values);
	}

	/** The datetime string every `creation` column in this app carries. */
	private function stamp(?int $at = null): string {
		return date('Y-m-d H:i:s', $at ?? time());
	}

	/**
	 * Says how far it has got, on one line.
	 *
	 * Seeding ten million rows is minutes, and a command that prints nothing
	 * for minutes is one somebody kills.
	 */
	private function progress(
		OutputInterface $output, string $what, int $done, int $total, bool $last = false,
	): void {
		if ($total < 1) {
			return;
		}

		$output->write(sprintf(
			"\r  %-8s %s / %s (%d%%)   ",
			$what, number_format($done), number_format($total), (int)($done / $total * 100)
		));

		if ($last) {
			$output->writeln('');
		}
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
	 * Takes back out everything `seed()` wrote.
	 *
	 * The seeded rows carry the host in their ids — but only in the columns
	 * that hold an id. The rows that *point* at them do not: a recipient row
	 * keys its post by `stream_id`, which is the md5 of the post's id, so
	 * `LIKE '%benchmark.invalid%'` could never match one. That is how a
	 * previous version of this left 60,000 recipient rows behind while
	 * reporting that it had removed everything: twelve times as many rows as
	 * the instance's own, with no post to belong to, on the table every
	 * timeline reads.
	 *
	 * So the posts are found first and their dependants deleted by the key
	 * they are actually keyed on, in pages — this can be a hundred thousand
	 * rows and an `IN` list has a size limit on every database.
	 *
	 * A full scan is the right trade for the rest: this runs by hand on a
	 * development instance, never on a request.
	 */
	private function clean(): int {
		$like = '%' . $this->connection->escapeLikeParameter(self::HOST) . '%';
		$removed = 0;

		// what the seeded posts are keyed by, a page at a time
		while (true) {
			$prims = $this->seededStreamPrims($like);
			if ($prims === []) {
				break;
			}

			foreach ([
				[CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id'],
				[CoreRequestBuilder::TABLE_STREAM_TAGS, 'stream_id'],
				[CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'stream_id_prim'],
			] as [$table, $column]) {
				$qb = $this->connection->getQueryBuilder();
				$qb->delete($table)->where(
					$qb->expr()->in($column, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
				);
				$removed += $qb->executeStatement();
			}

			$qb = $this->connection->getQueryBuilder();
			$qb->delete(CoreRequestBuilder::TABLE_STREAM)->where(
				$qb->expr()->in('id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY))
			);
			$removed += $qb->executeStatement();
		}

		// the actors, and the follows in either direction: a seeded follow has
		// the viewer as its actor, so matching only the object would leave it
		foreach ([
			[CoreRequestBuilder::TABLE_CACHE_ACTORS, ['id']],
			[CoreRequestBuilder::TABLE_FOLLOWS, ['id', 'object_id', 'follow_id']],
		] as [$table, $columns]) {
			foreach ($columns as $column) {
				$qb = $this->connection->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->like($column, $qb->createNamedParameter($like)));
				$removed += $qb->executeStatement();
			}
		}

		return $removed;
	}

	/**
	 * @param string $like the seeded host, as a LIKE pattern
	 * @return string[] the `id_prim` of one page of seeded posts
	 */
	private function seededStreamPrims(string $like): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id_prim')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->like('id', $qb->createNamedParameter($like)))
			->setMaxResults(self::CLEAN_PAGE);

		$cursor = $qb->executeQuery();
		$prims = [];
		while ($row = $cursor->fetch()) {
			$prim = (string)($row['id_prim'] ?? '');
			if ($prim !== '') {
				$prims[] = $prim;
			}
		}
		$cursor->closeCursor();

		return $prims;
	}
}
