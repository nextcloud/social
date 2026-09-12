<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CheckInstall extends SocialCommand {
	use TArrayTools;

	/**
	 * How many stream rows the index rebuild holds at once.
	 *
	 * It used to load every row in the instance, after having truncated both
	 * index tables — so on a large instance the rebuild ran out of memory and
	 * left every timeline empty, with no way back but running it again on a
	 * bigger memory_limit.
	 */
	private const INDEX_CHUNK = 500;

	/** how many individual failures the rebuild reports before it only counts them */
	private const INDEX_ERRORS_SHOWN = 10;

	private CacheActorService $cacheActorService;
	private StreamDestRequest $streamDestRequest;
	private ConfigService $configService;
	private IDBConnection $connection;

	public function __construct(
		private StreamRequest $streamRequest,
		StreamDestRequest $streamDestRequest,
		private StreamTagsRequest $streamTagsRequest,
		CacheActorService $cacheActorService,
		private CheckService $checkService,
		ConfigService $configService,
		private MiscService $miscService,
		IDBConnection $connection,
	) {
		parent::__construct();
		$this->streamDestRequest = $streamDestRequest;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
		$this->connection = $connection;
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:check:install')
			->addOption('index', '', InputOption::VALUE_NONE, 'regenerate your index')
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'skip the confirmation of --index (required with --no-interaction)'
			)
			->setDescription('Check the integrity of the installation');
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$index = $this->regenerateIndexIfAsked($input, $output);
		if ($index !== null) {
			return $index;
		}

		$result = $this->checkService->checkInstallationStatus();

		// 'invalidFollowers' was never a key of what checkInstallationStatus()
		// returns, so this line always said 0
		$output->writeln('- ' . $this->getInt('invalidFollows', $result, 0) . ' invalid followers removed');
		$output->writeln('- ' . $this->getInt('invalidNotes', $result, 0) . ' invalid notes removed');

		$output->writeln('');
		$this->reportChecks($output);

		$output->writeln('');
		$output->writeln('- Your current configuration: ');
		$output->writeln(json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT));

		return 0;
	}

	/**
	 * Reports an address that no longer matches the server's, because somebody
	 * debugging a federation problem is already looking here.
	 *
	 * The .well-known probe is deliberately not run: it needs the request and
	 * the session cache that a console command does not have. The app shows
	 * that one on its first screen.
	 */
	private function reportChecks(OutputInterface $output): void {
		$addresses = $this->checkService->cloudAddresses();

		if ($this->checkService->checkCloudAddress()) {
			$output->writeln('- <info>the configured address matches this server</info>');

			return;
		}

		$output->writeln(
			'<error>Social builds every id from ' . $addresses['configured']
			. ', but this server reports ' . $addresses['expected'] . '.</error>'
		);
		$output->writeln('  Accounts here cannot be found under the address the server advertises.');
		$output->writeln('  Point overwrite.cli.url back at the first, or accept the rename with');
		$output->writeln(
			'  "occ social:reset --uri=' . $addresses['expected']
			. '" — which deletes everything Social holds.'
		);
	}

	/**
	 * @return int|null the exit code, or null when --index was not asked for
	 */
	private function regenerateIndexIfAsked(InputInterface $input, OutputInterface $output): ?int {
		if (!$input->getOption('index')) {
			return null;
		}

		$output->writeln('<error>This command will regenerate the index of the Social App.</error>');
		$output->writeln(
			'<error>This operation can takes a while, and the Social App might not be stable during the process.</error>'
		);
		$output->writeln('');

		if (!$input->getOption('force')) {
			if (!$input->isInteractive()) {
				// A ConfirmationQuestion answers itself with its default —
				// false — when nobody is there, so this used to exit 0 having
				// rebuilt nothing.
				$output->writeln(
					'<error>Refusing to rebuild the index non-interactively without --force.</error>'
				);

				return 1;
			}

			$helper = $this->questionHelper();
			$question = new ConfirmationQuestion(
				'<info>Do you confirm this operation?</info> (y/N) ', false, '/^(y|Y)/i'
			);

			if (!$helper->ask($input, $output, $question)) {
				$output->writeln('cancelled, the index was left alone.');

				return 0;
			}
		}

		$this->streamDestRequest->emptyStreamDest();
		$this->streamTagsRequest->emptyStreamTags();

		return $this->regenerateIndex($output);
	}

	/**
	 * Rebuilds social_stream_dest and social_stream_tag from social_stream, a
	 * bounded number of rows at a time.
	 *
	 * Paged on `nid` rather than an OFFSET so the walk stays cheap on a table
	 * with millions of rows, and one row at a time from there, so peak memory
	 * is a chunk and not the instance.
	 */
	private function regenerateIndex(OutputInterface $output): int {
		$progressBar = new ProgressBar($output, $this->countStreams());
		$progressBar->start();

		$errors = [];
		$failed = 0;
		$lastNid = 0;

		while (true) {
			$chunk = $this->streamChunk($lastNid, self::INDEX_CHUNK);
			if ($chunk === []) {
				break;
			}

			foreach ($chunk as $row) {
				$lastNid = (int)$row['nid'];

				try {
					$stream = $this->streamRequest->getStream((string)$row['id_prim']);
					$this->streamDestRequest->generateStreamDest($stream);
					$this->streamTagsRequest->generateStreamTags($stream);
				} catch (Exception $e) {
					$failed++;
					if (count($errors) < self::INDEX_ERRORS_SHOWN) {
						$errors[] = '  nid ' . $lastNid . ': ' . get_class($e) . ' - ' . $e->getMessage();
					}
				}

				$progressBar->advance();
			}
		}

		$progressBar->finish();
		$output->writeln('');

		if ($failed === 0) {
			return 0;
		}

		$output->writeln('<comment>' . $failed . ' stream(s) could not be indexed:</comment>');
		foreach ($errors as $error) {
			$output->writeln($error);
		}
		if ($failed > count($errors)) {
			$output->writeln('  … and ' . ($failed - count($errors)) . ' more');
		}

		return 1;
	}

	private function countStreams(): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(CoreRequestBuilder::TABLE_STREAM);

		$cursor = $qb->executeQuery();
		$total = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $total;
	}

	/**
	 * The next $limit stream rows after $afterNid, as (nid, id_prim) pairs.
	 *
	 * Only the two columns the rebuild needs: the full row is read back one at
	 * a time through StreamRequest, which is what knows how to parse it.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function streamChunk(int $afterNid, int $limit): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('nid', 'id_prim')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->gt('nid', $qb->createNamedParameter($afterNid, IQueryBuilder::PARAM_INT)))
			->orderBy('nid', 'asc')
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;
	}
}
