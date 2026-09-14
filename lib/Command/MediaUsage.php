<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the app's media is costing, and how much of it is somebody else's.
 *
 * An administrator looking at a Social instance that has grown to fifty
 * gigabytes has no way to find out what is in there. The two halves are very
 * different things: what the people here uploaded is theirs and is the only
 * copy, and what was cached off other servers is a copy that can be thrown
 * away and fetched again. Nothing told them apart, so nothing could be decided
 * about either — which is also why `cache_actor_days` (the sweep in
 * `CacheActorSweepService`) had no number an operator could put next to it.
 *
 * Both sides of the count are real: the rows come from `social_cache_doc` and
 * the size of every copy is read from appdata, the file at a time, rather than
 * from anything the row claims. A row whose file is gone is reported as such
 * instead of being counted as zero bytes.
 *
 * What it does not do is walk appdata looking for files no row names. The path
 * of a copy is derived from its own name (`aa/bb/cc/dd/<uuid>`), so finding an
 * orphan means listing a four-level tree with a directory per file, and the
 * number would not be actionable anyway — there is no safe way to delete a
 * file this app cannot name.
 *
 * No quota accounting: this is what is on disk, not what anybody is allowed.
 */
class MediaUsage extends SocialCommand {
	/** Rows read per query; the work is a file lookup per row, not the read. */
	private const PAGE = 500;

	/**
	 * The three ids this app gives its own documents. A cached copy of a
	 * remote Social instance's picture carries the same shape — hence the
	 * cloud url, which is what actually decides it.
	 */
	private const LOCAL_MARKERS = ['/documents/local/', '/documents/avatar/', '/documents/header/'];

	public function __construct(
		private CacheDocumentsRequest $cacheDocumentsRequest,
		private CacheDocumentService $cacheDocumentService,
		private ConfigService $configService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:media:usage')
			->setDescription('Report what local uploads and cached remote media occupy on disk');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$cloudUrl = $this->configService->getCloudUrl();
		} catch (SocialAppConfigException $e) {
			$output->writeln(
				'<error>The Social app is not configured, so a document id cannot be told from'
				. ' a remote one: ' . $e->getMessage() . '</error>'
			);

			return 1;
		}

		$usage = $this->collect($cloudUrl);

		if ($input->getOption('output') !== self::OUTPUT_FORMAT_PLAIN) {
			$this->writeArrayInOutputFormat($input, $output, $usage, '');

			return 0;
		}

		$this->report($output, $usage);

		return 0;
	}

	/**
	 * Walks every document row and adds its copies up.
	 *
	 * @return array{
	 *     local: array{attachments: array{files: int, bytes: int}, avatars: array{files: int, bytes: int}},
	 *     remote: array{attachments: array{files: int, bytes: int}, avatars: array{files: int, bytes: int}},
	 *     rows: int, streamed: int, missing: int, elsewhere: int,
	 *     files: int, bytes: int
	 * }
	 */
	private function collect(string $cloudUrl): array {
		$empty = ['attachments' => ['files' => 0, 'bytes' => 0], 'avatars' => ['files' => 0, 'bytes' => 0]];
		$usage = [
			'local' => $empty,
			'remote' => $empty,
			'rows' => 0,
			'streamed' => 0,
			'missing' => 0,
			'elsewhere' => 0,
			'files' => 0,
			'bytes' => 0,
		];

		$after = 0;
		while (true) {
			$rows = $this->cacheDocumentsRequest->getUsagePage(self::PAGE, $after);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$after = max($after, $row['nid']);
				$usage['rows']++;

				$side = str_starts_with($row['id'], $cloudUrl) && $this->looksLocal($row['id'])
					? 'local' : 'remote';
				// the parent being a cached actor is what makes a document that
				// actor's picture; a local avatar has no parent at all, and is
				// recognised by the id this app gave it
				$kind = ($row['actor_local'] !== null || $this->looksLikeAvatar($row['id']))
					? 'avatars' : 'attachments';

				foreach ([$row['local_copy'], $row['resized_copy']] as $copy) {
					$this->addCopy($usage, $side, $kind, $copy);
				}
			}
		}

		return $usage;
	}

	/**
	 * One stored copy, added to the right bucket — or to one of the three
	 * counts of copies that are not bytes of ours.
	 *
	 * @param array<string, mixed> $usage
	 */
	private function addCopy(array &$usage, string $side, string $kind, string $copy): void {
		if ($copy === '') {
			return;
		}

		if ($copy === Document::COPY_STREAMED) {
			// a pointer at a file on the server that hosts it; no bytes here
			$usage['streamed']++;

			return;
		}

		if ($copy === 'avatar' || $copy === 'header') {
			// served straight out of Nextcloud's own avatar store, never copied
			$usage['elsewhere']++;

			return;
		}

		$size = $this->cacheDocumentService->cachedFileSize($copy);
		if ($size === null) {
			$usage['missing']++;

			return;
		}

		$usage[$side][$kind]['files']++;
		$usage[$side][$kind]['bytes'] += $size;
		$usage['files']++;
		$usage['bytes'] += $size;
	}

	private function looksLocal(string $id): bool {
		foreach (self::LOCAL_MARKERS as $marker) {
			if (str_contains($id, $marker)) {
				return true;
			}
		}

		return false;
	}

	private function looksLikeAvatar(string $id): bool {
		return str_contains($id, '/documents/avatar/') || str_contains($id, '/documents/header/');
	}

	/**
	 * @param array<string, mixed> $usage
	 */
	private function report(OutputInterface $output, array $usage): void {
		$output->writeln($usage['rows'] . ' document row(s) in social_cache_doc');
		$output->writeln('');

		foreach (['local' => 'Uploaded here', 'remote' => 'Cached from other servers'] as $side => $title) {
			$files = $usage[$side]['attachments']['files'] + $usage[$side]['avatars']['files'];
			$bytes = $usage[$side]['attachments']['bytes'] + $usage[$side]['avatars']['bytes'];

			$output->writeln(sprintf('<info>%-28s</info> %6d files  %10s', $title, $files, $this->human($bytes)));
			$output->writeln(sprintf(
				'  %-26s %6d files  %10s', 'attachments',
				$usage[$side]['attachments']['files'], $this->human($usage[$side]['attachments']['bytes'])
			));
			$output->writeln(sprintf(
				'  %-26s %6d files  %10s', 'avatars and headers',
				$usage[$side]['avatars']['files'], $this->human($usage[$side]['avatars']['bytes'])
			));
		}

		$output->writeln('');
		$output->writeln(sprintf(
			'<info>%-28s</info> %6d files  %10s', 'Total on disk', $usage['files'], $this->human($usage['bytes'])
		));

		if ($usage['streamed'] > 0) {
			$output->writeln(
				$usage['streamed'] . ' copy/copies are streamed from the server that holds them, so they cost nothing here'
			);
		}
		if ($usage['elsewhere'] > 0) {
			$output->writeln(
				$usage['elsewhere'] . ' copy/copies are served from Nextcloud\'s own avatar store rather than from this app'
			);
		}
		if ($usage['missing'] > 0) {
			$output->writeln(
				'<comment>' . $usage['missing'] . ' copy/copies are named by a row but are not in appdata'
				. '; a cached remote one is fetched again when it is next asked for</comment>'
			);
		}
	}

	/**
	 * Bytes as an administrator reads them. Powers of two, because that is
	 * what `du` and the Files app report.
	 */
	private function human(int $bytes): string {
		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		$value = (float)$bytes;
		$unit = 0;
		while ($value >= 1024.0 && $unit < 4) {
			$value = $value / 1024.0;
			$unit++;
		}

		return ($unit === 0)
			? $bytes . ' B'
			: sprintf('%.1f %s', $value, $units[$unit]);
	}
}
