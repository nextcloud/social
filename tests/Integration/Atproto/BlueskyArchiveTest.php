<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\BlueskyArchiveService;
use OCA\Social\Atproto\Service\RecoveryToolPage;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * "Download your Bluesky account" against a real repository: the zip
 * holds the repository as a CAR that decodes to the blocks the repository
 * has, rooted at its head commit, and the DID's log as this server
 * confirmed it — the directory is not asked here.
 */
class BlueskyArchiveTest extends TestCase {
	private const DID = 'did:plc:integrationarchive00000';

	private RepositoryService $repositories;
	private AtprotoPlcLogRequest $plcLog;
	private string $path = '';

	protected function setUp(): void {
		$this->repositories = Server::get(RepositoryService::class);
		$this->plcLog = Server::get(AtprotoPlcLogRequest::class);
		$this->repositories->delete(self::DID);
		$this->forgetLog();
	}

	protected function tearDown(): void {
		$this->repositories->delete(self::DID);
		$this->forgetLog();
		if ($this->path !== '' && is_file($this->path)) {
			unlink($this->path);
		}
	}

	private function forgetLog(): void {
		foreach ($this->plcLog->getByDid(self::DID) as $row) {
			$this->plcLog->remove($row['id']);
		}
	}

	public function testTheZipHoldsTheRepositoryAsACarOfItsBlocksAndTheLog(): void {
		$records = [];
		for ($i = 0; $i < 40; $i++) {
			$records['app.bsky.feed.post/3kznmn7x' . str_pad(base_convert((string)$i, 10, 32), 5, '0', STR_PAD_LEFT)] = DagCbor::encode([
				'$type' => 'app.bsky.feed.post',
				'text' => 'post ' . $i,
				'createdAt' => '2026-10-14T10:00:00.000Z',
			]);
		}
		$this->repositories->import(self::DID, PrivateKey::generate(Curve::K256), $records);
		$genesis = ['type' => 'plc_operation', 'rotationKeys' => ['did:key:zQ3sh'], 'prev' => null];
		$id = $this->plcLog->record(self::DID, 'bafygenesis', $genesis);
		$this->plcLog->markSent($id);
		$this->plcLog->markConfirmed($id);
		$plc = $this->createStub(PlcClient::class);
		$plc->method('auditLog')->willReturn([]);
		$plc->method('document')->willReturn(null);
		$service = new BlueskyArchiveService(
			Server::get(IdentityService::class), $this->repositories, Server::get(AtprotoBlobRequest::class),
			Server::get(PictureService::class), Server::get(VideoBlobService::class), $plc, $this->plcLog,
			Server::get(AtprotoConfig::class), Server::get(ITimeFactory::class), Server::get(RecoveryToolPage::class), new NullLogger(),
		);
		$identity = new Identity(1, 'https://social.test/@archive', self::DID, 'archive.social.test', 'sealed', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0);

		$this->path = (string)tempnam(sys_get_temp_dir(), 'bluesky-archive-test');
		$file = fopen($this->path, 'wb');
		$service->zip($identity, static function (string $bytes) use ($file): void {
			fwrite($file, $bytes);
		});
		fclose($file);

		$zip = new ZipArchive();
		$this->assertTrue($zip->open($this->path, ZipArchive::RDONLY | ZipArchive::CHECKCONS));
		$car = Car::decode((string)$zip->getFromName('bluesky/repo.car'));
		$this->assertSame($this->repositories->getHead(self::DID)?->commitCid, $car['roots'][0]->toString());
		$this->assertSame(Car::decode(implode('', iterator_to_array($this->repositories->streamCar(self::DID), false)))['blocks'], $car['blocks']);
		$this->assertGreaterThan(40, count($car['blocks']), 'the records, the tree and the commit');
		$log = json_decode((string)$zip->getFromName('bluesky/plc-log.json'), true);
		$this->assertSame([['bafygenesis', $genesis]], array_map(static fn (array $entry): array => [$entry['cid'], $entry['operation']], $log));
		$this->assertStringContainsString(self::DID, (string)$zip->getFromName('bluesky/README.txt'));
		$this->assertNotFalse($zip->getFromName('bluesky/recovery/recovery-key.py'));
		$zip->close();
	}
}
