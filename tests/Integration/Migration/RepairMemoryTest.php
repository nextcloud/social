<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Migration;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\BackfillRemoteVisibility;
use OCA\Social\Migration\BackfillStreamPostFields;
use OCA\Social\Migration\Version1000Date20260917000001;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCA\Social\Tests\Integration\PeakMemory;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * The upgrade steps that walk a table, run over a table five times as
 * large: the peak memory must not grow with it.
 *
 * They run inside `occ upgrade` with the instance in maintenance mode, or
 * from a command somebody typed on an instance of any size, so a step that
 * holds the whole table at once is a step that fails the upgrade of a large
 * instance at the memory limit — after having done half its work. A step
 * that pages holds one page whatever the table holds. See `PeakMemory` for
 * the tolerance and why.
 *
 * Each step is run once on a few rows first, so that what PHP loads once per
 * process (classes, the app's configuration) is not counted against the
 * first of the two measured runs.
 */
class RepairMemoryTest extends TestCase {
	private const BASE = 'https://cloud.example.org/repairmemory';
	private const AUTHOR = 'https://remote.example/repairmemory/users/author';
	private const STRANGER = 'https://remote.example/repairmemory/users/stranger';

	private const MARKERS = [
		'migration_remote_visibility_backfilled',
		'migration_stream_post_fields_backfilled',
		ConfigService::SOCIAL_DEST_NID_FILLED,
	];

	/** @var array<string, string> */
	private array $markers = [];

	/** @var list<string> */
	private array $streams = [];

	private int $sequence = 0;

	protected function setUp(): void {
		parent::setUp();
		$config = Server::get(IAppConfig::class);
		foreach (self::MARKERS as $marker) {
			$this->markers[$marker] = $config->getValueString('social', $marker, '');
		}

		$author = new Person();
		$author->setId(self::AUTHOR)->setPreferredUsername('repairmemory-author');
		$author->setAccount('repairmemory-author@remote.example')->setFollowers(self::AUTHOR . '/followers');
		Server::get(CacheActorsRequest::class)->save($author);
	}

	protected function tearDown(): void {
		$this->removeSeeded();
		Server::get(CacheActorsRequest::class)->deleteCacheById(self::AUTHOR);
		$config = Server::get(IAppConfig::class);
		foreach ($this->markers as $marker => $value) {
			$config->setValueString('social', $marker, $value);
		}
		parent::tearDown();
	}

	private function removeSeeded(): void {
		RowSeeder::deleteIn(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', $this->streams);
		RowSeeder::deleteIn(CoreRequestBuilder::TABLE_STREAM, 'id_prim', $this->streams);
		$this->streams = [];
	}

	private function resetMarkers(): void {
		$config = Server::get(IAppConfig::class);
		foreach (self::MARKERS as $marker) {
			$config->setValueString('social', $marker, '0');
		}
	}

	/**
	 * The peak of `$run` over `$rows` rows written by `$seed`, which are taken
	 * out again afterwards.
	 *
	 * @param callable(int): void $seed
	 */
	private function peakOver(int $rows, callable $seed, callable $run): int {
		$seed($rows);
		$this->resetMarkers();
		try {
			return PeakMemory::of($run);
		} finally {
			$this->removeSeeded();
		}
	}

	/**
	 * @param callable(int): void $seed
	 */
	private function assertFlatOver(int $small, callable $seed, callable $run, string $what): void {
		$this->peakOver(20, $seed, $run);
		$smallPeak = $this->peakOver($small, $seed, $run);
		$largePeak = $this->peakOver(5 * $small, $seed, $run);

		PeakMemory::assertFlat($smallPeak, $largePeak, $what);
	}

	/**
	 * Posts, as the stream table holds them before the step runs.
	 *
	 * @param callable(int, string): array<string, mixed> $columns the row of the `$i`th post
	 */
	private function seedStreams(int $count, callable $columns): void {
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$id = self::BASE . '/notes/' . (++$this->sequence);
			$prim = md5($id);
			$this->streams[] = $prim;
			$rows[] = array_merge(['id' => $id, 'id_prim' => $prim], $columns($i, $prim));
		}
		RowSeeder::insertAll(CoreRequestBuilder::TABLE_STREAM, $rows);
	}

	private function silent(): IOutput {
		return $this->createStub(IOutput::class);
	}

	public function testTheRecipientNidBackfillHoldsOnePageWhateverTheTableHolds(): void {
		// its page is five thousand posts, so the smaller run is one page and
		// the larger five
		$page = (int)(new ReflectionClassConstant(Version1000Date20260917000001::class, 'BATCH'))->getValue();
		$schema = $this->createStub(ISchemaWrapper::class);
		$step = Server::get(Version1000Date20260917000001::class);

		$this->assertFlatOver(
			$page,
			function (int $count): void {
				$this->seedStreams($count, static fn (int $i, string $prim): array => []);
				RowSeeder::insertAll(CoreRequestBuilder::TABLE_STREAM_DEST, array_map(
					static fn (string $prim): array => ['stream_id' => $prim, 'nid' => 0, 'type' => 'recipient'],
					$this->streams
				));
			},
			fn () => $step->postSchemaChange($this->silent(), static fn () => $schema, []),
			'Version1000Date20260917000001 (the recipient rows\' nid)'
		);
	}

	public function testTheRemoteVisibilityBackfillHoldsOneChunk(): void {
		$step = Server::get(BackfillRemoteVisibility::class);

		// two chunks of a thousand against ten: while the next chunk is
		// fetched the last one is still held, so a single chunk is the one
		// run that peaks lower
		$this->assertFlatOver(
			2000,
			fn (int $count) => $this->seedStreams($count, static fn (int $i): array => [
				'local' => 0, 'visibility' => '', 'attributed_to' => self::AUTHOR, 'to_array' => '[]',
				// half followers-only, half direct: the two the chunked walk decides
				'to' => ($i % 2 === 0) ? '' : self::STRANGER,
				'cc' => ($i % 2 === 0) ? json_encode([self::AUTHOR . '/followers']) : '[]',
			]),
			fn () => $step->run($this->silent()),
			'BackfillRemoteVisibility'
		);
	}

	public function testThePostFieldsBackfillHoldsOneChunk(): void {
		$step = Server::get(BackfillStreamPostFields::class);
		$source = static fn (int $i): string => (string)json_encode([
			'type' => 'Note', 'id' => self::BASE . '/source/' . $i, 'contentMap' => ['en' => '<p>post ' . $i . '</p>'],
			'tag' => [['type' => 'Hashtag', 'name' => '#memory', 'href' => self::BASE . '/tags/memory']],
		]);

		$this->assertFlatOver(
			1000,
			fn (int $count) => $this->seedStreams($count, static fn (int $i): array => [
				'source' => $source($i), 'tags' => '', 'language' => '', 'quote' => '',
				'quote_authorization' => '', 'updated' => null, 'to' => ACore::CONTEXT_PUBLIC,
			]),
			fn () => $step->run($this->silent()),
			'BackfillStreamPostFields'
		);
	}
}
