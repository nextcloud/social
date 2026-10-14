<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Service;

use DateTime;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\BlueskyArchiveService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Tests\Helper\MigrationArchive;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Bluesky account as files (R2): the repository as the CAR the
 * repository streams, every blob that still reads under its CID, the
 * DID's log from the directory or this server's own, the document, the
 * offline recovery script and a README that says what each is — each
 * handed over as a stream, none built up in memory.
 */
#[AllowMockObjectsWithoutExpectations]
class BlueskyArchiveServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private PlcClient&MockObject $plc;
	private RepositoryService&MockObject $repositories;
	/** @var BlobRef[] */
	private array $blobs = [];
	/** @var array<int, array{id: int, did: string, cid: string, operation: array, creation: int, sent: int, confirmed: int, dead: int, reason: string}> */
	private array $log = [];

	protected function setUp(): void {
		$this->plc = $this->createMock(PlcClient::class);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('streamCar')->willReturnCallback(static function (): \Generator {
			yield 'CAR header';
			yield ' and blocks';
		});
	}

	private function service(): BlueskyArchiveService {
		$identities = $this->createMock(IdentityService::class);
		$identities->method('document')->willReturn(['id' => self::DID, 'from' => 'local log']);
		$blobs = $this->createMock(AtprotoBlobRequest::class);
		$blobs->method('list')->willReturnCallback(fn (string $did, int $limit, string $cursor): array => array_slice(array_values(array_filter($this->blobs, static fn (BlobRef $blob): bool => $cursor === '' || strcmp($blob->cid->toString(), $cursor) > 0)), 0, $limit));
		$pictures = $this->createMock(PictureService::class);
		$pictures->method('read')->willReturnCallback(static fn (BlobRef $blob): string => $blob->documentId === 'gone' ? throw new AtprotoException('not readable') : 'picture ' . $blob->documentId);
		$videos = $this->createMock(VideoBlobService::class);
		$videos->method('open')->willReturnCallback(static function (BlobRef $blob): array {
			$stream = fopen('php://memory', 'w+b');
			fwrite($stream, 'video ' . $blob->documentId);
			rewind($stream);

			return ['stream' => $stream, 'size' => 7];
		});
		$plcLog = $this->createMock(AtprotoPlcLogRequest::class);
		$plcLog->method('getByDid')->willReturnCallback(fn (): array => $this->log);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('pdsEndpoint')->willReturn('https://social.test');
		$config->method('plcDirectory')->willReturn('https://plc.directory');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-14 12:00:00'));

		return new BlueskyArchiveService(
			$identities, $this->repositories, $blobs, $pictures, $videos, $this->plc, $plcLog, $config,
			$this->createMock(ITempManager::class), $time, new NullLogger(),
		);
	}

	private static function identity(string $state = Identity::STATE_ACTIVE): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', 'did:key:z', '', $state, '', 0);
	}

	/** A destination that takes streams only: a file passed as a string would have been whole in memory. */
	private static function archive(): MigrationArchive {
		return new class extends MigrationArchive {
			public function addFileContents(string $path, string $content): void {
				throw new \LogicException($path . ' was handed over as a string');
			}
		};
	}

	public function testTheArchiveHoldsTheRepositoryTheBlobsTheLogAndTheRecoveryScript(): void {
		$picture = new BlobRef(self::DID, Cid::forRaw('a picture'), 'p1', 'image/jpeg', 9);
		$video = new BlobRef(self::DID, Cid::forRaw('a video'), 'v1', 'video/mp4', 7);
		$this->blobs = [$picture, $video];
		$audit = [['did' => self::DID, 'operation' => ['type' => 'plc_operation'], 'cid' => 'bafy1', 'nullified' => false, 'createdAt' => '2026-10-01T00:00:00.000Z']];
		$this->plc->method('auditLog')->willReturn($audit);
		$this->plc->method('document')->willReturn(['id' => self::DID, 'from' => 'directory']);
		$archive = self::archive();

		$summary = $this->service()->write(self::identity(), $archive);

		$this->assertSame(['repository' => true, 'blobs' => 2, 'missing' => [], 'log' => 'directory'], $summary);
		$expected = [
			'bluesky/README.txt',
			'bluesky/blobs/' . $picture->cid->toString(),
			'bluesky/blobs/' . $video->cid->toString(),
			'bluesky/did.json',
			'bluesky/plc-log.json',
			'bluesky/recovery/bip39-english.txt',
			'bluesky/recovery/recovery-key.py',
			'bluesky/repo.car',
		];
		sort($expected);
		$this->assertSame($expected, $archive->paths());
		$this->assertSame('CAR header and blocks', $archive->contents('bluesky/repo.car'));
		$this->assertSame('picture p1', $archive->contents('bluesky/blobs/' . $picture->cid->toString()));
		$this->assertSame('video v1', $archive->contents('bluesky/blobs/' . $video->cid->toString()));
		$this->assertSame($audit, json_decode($archive->contents('bluesky/plc-log.json'), true));
		$this->assertSame('directory', json_decode($archive->contents('bluesky/did.json'), true)['from']);
		$resources = __DIR__ . '/../../../resources/atproto/';
		$this->assertSame(file_get_contents($resources . 'recovery-key.py'), $archive->contents('bluesky/recovery/recovery-key.py'));
		$this->assertSame(file_get_contents($resources . 'bip39-english.txt'), $archive->contents('bluesky/recovery/bip39-english.txt'));
		$readme = $archive->contents('bluesky/README.txt');
		foreach ([self::DID, 'alice.social.test', 'repo.car', 'goat repo import repo.car', 'recovery-key.py', 'goat plc sign --plc-signing-key RECOVERY_KEY', '72'] as $text) {
			$this->assertStringContainsString($text, $readme);
		}
	}

	/** The account export puts the same files under its own folder. */
	public function testTheFilesGoUnderTheRootTheCallerGives(): void {
		$archive = self::archive();

		$this->service()->write(self::identity(), $archive, 'social/bluesky/');

		$this->assertContains('social/bluesky/repo.car', $archive->paths());
		$this->assertSame([], array_filter($archive->paths(), static fn (string $path): bool => !str_starts_with($path, 'social/bluesky/')));
	}

	/** A blob whose file is gone is left out and named, rather than failing the export. */
	public function testABlobThatNoLongerReadsIsNamedInTheReadme(): void {
		$gone = new BlobRef(self::DID, Cid::forRaw('gone'), 'gone', 'image/png', 4);
		$this->blobs = [$gone];
		$archive = self::archive();

		$summary = $this->service()->write(self::identity(), $archive);

		$this->assertSame([$gone->cid->toString()], $summary['missing']);
		$this->assertNotContains('bluesky/blobs/' . $gone->cid->toString(), $archive->paths());
		$this->assertStringContainsString('1 could not be read here and are not included: ' . $gone->cid->toString(), $archive->contents('bluesky/README.txt'));
	}

	/** Blobs are read a page at a time, after the last CID of the page before. */
	public function testEveryBlobIsReadAcrossPages(): void {
		for ($i = 0; $i < 150; $i++) {
			$this->blobs[] = new BlobRef(self::DID, Cid::forRaw('picture ' . $i), 'p' . $i, 'image/jpeg', 1);
		}
		usort($this->blobs, static fn (BlobRef $a, BlobRef $b): int => strcmp($a->cid->toString(), $b->cid->toString()));

		$this->assertSame(150, $this->service()->write(self::identity(), self::archive())['blobs']);
	}

	/** Without the directory, the log is this server's: the operations it confirmed, in the audit log's shape. */
	public function testWithoutTheDirectoryTheLogAndTheDocumentAreThisServersOwn(): void {
		$this->plc->method('auditLog')->willThrowException(new AtprotoException('PLC directory could not be reached'));
		$this->plc->method('document')->willThrowException(new AtprotoException('PLC directory could not be reached'));
		$this->log = [
			['id' => 1, 'did' => self::DID, 'cid' => 'bafygenesis', 'operation' => ['type' => 'plc_operation', 'prev' => null], 'creation' => 1760000000, 'sent' => 1, 'confirmed' => 1, 'dead' => 0, 'reason' => ''],
			['id' => 2, 'did' => self::DID, 'cid' => 'bafyrefused', 'operation' => ['type' => 'plc_operation'], 'creation' => 1760000100, 'sent' => 1, 'confirmed' => 0, 'dead' => 1, 'reason' => 'refused'],
			['id' => 3, 'did' => self::DID, 'cid' => 'bafypending', 'operation' => ['type' => 'plc_operation'], 'creation' => 1760000200, 'sent' => 0, 'confirmed' => 0, 'dead' => 0, 'reason' => ''],
		];
		$archive = self::archive();

		$this->assertSame('local', $this->service()->write(self::identity(), $archive)['log']);

		$this->assertSame([[
			'did' => self::DID,
			'operation' => ['type' => 'plc_operation', 'prev' => null],
			'cid' => 'bafygenesis',
			'nullified' => false,
			'createdAt' => '2025-10-09T08:53:20.000Z',
		]], json_decode($archive->contents('bluesky/plc-log.json'), true));
		$this->assertSame('local log', json_decode($archive->contents('bluesky/did.json'), true)['from']);
		$this->assertStringContainsString('could not be asked', $archive->contents('bluesky/README.txt'));
	}

	public function testAnAccountWithoutRepositoryStillGetsItsLog(): void {
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('streamCar')->willThrowException(new AtprotoException('No repository'));
		$archive = self::archive();

		$this->assertFalse($this->service()->write(self::identity(), $archive)['repository']);
		$this->assertNotContains('bluesky/repo.car', $archive->paths());
		$this->assertContains('bluesky/plc-log.json', $archive->paths());
	}

	public function testOnlyAnAccountHostedHereIsExported(): void {
		$this->assertTrue(BlueskyArchiveService::exportable(self::identity()));
		$this->assertTrue(BlueskyArchiveService::exportable(self::identity(Identity::STATE_DEACTIVATED)));
		$this->assertFalse(BlueskyArchiveService::exportable(self::identity(Identity::STATE_MOVED_AWAY)));
		$this->assertFalse(BlueskyArchiveService::exportable(self::identity(Identity::STATE_TOMBSTONED)));
		$this->assertFalse(BlueskyArchiveService::exportable(null));
	}

	public function testTheFileIsNamedByTheHandleAndTheDay(): void {
		$this->assertSame('bluesky-alice.social.test-2026-10-14.zip', $this->service()->filename(self::identity()));
	}
}
