<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Migration;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20261012507100;
use OCA\Social\Service\AccountService;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `Version1000Date20261012507100` against the real database: a banner stored
 * without a parent is hung off the account its handle names, an attachment
 * and a banner whose handle no actor has are left alone, and a second run
 * changes nothing.
 */
class BannerParentBackfillTest extends TestCase {
	private string $handle = '';

	/** @var string[] document id prims of this run */
	private array $documents = [];

	protected function tearDown(): void {
		foreach ($this->documents as $prim) {
			RowSeeder::delete(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, ['id_prim' => $prim]);
		}
		if ($this->handle !== '') {
			Server::get(IUserManager::class)->get($this->handle)?->delete();
			Server::get(ActorsRequest::class)->delete($this->handle);
		}
		parent::tearDown();
	}

	private function document(string $path, string $account): string {
		$id = 'https://cloud.example' . $path . bin2hex(random_bytes(8));
		$this->documents[] = md5($id);
		RowSeeder::insert(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, [
			'id' => $id, 'id_prim' => md5($id), 'account' => $account, 'parent_id' => '', 'parent_id_prim' => '',
		]);

		return md5($id);
	}

	/** @return array{string, string} parent_id and parent_id_prim */
	private function parentOf(string $prim): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('parent_id', 'parent_id_prim')->from(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($prim)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return [(string)$row['parent_id'], (string)$row['parent_id_prim']];
	}

	private function runStep(): void {
		$schema = $this->createStub(ISchemaWrapper::class);
		Server::get(Version1000Date20261012507100::class)
			->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);
	}

	public function testABannerIsHungOffItsAccountAndRunAgainNothingMoves(): void {
		$this->handle = 'bannerfill' . bin2hex(random_bytes(3));
		Server::get(IUserManager::class)->createUser($this->handle, bin2hex(random_bytes(16)) . 'Aa1!');
		Server::get(AccountService::class)->createActor($this->handle, $this->handle);
		$actorId = Server::get(ActorsRequest::class)->getFromUsername($this->handle)->getId();

		$banner = $this->document('/documents/header/', $this->handle);
		$upload = $this->document('/documents/local/', $this->handle);
		$nobody = $this->document('/documents/header/', 'bannerfillgone' . bin2hex(random_bytes(3)));

		for ($run = 1; $run <= 2; $run++) {
			$this->runStep();

			$this->assertSame([$actorId, md5($actorId)], $this->parentOf($banner), 'run ' . $run . ': the banner hangs off its account');
			$this->assertSame(['', ''], $this->parentOf($upload), 'run ' . $run . ': an upload is not a banner');
			$this->assertSame(['', ''], $this->parentOf($nobody), 'run ' . $run . ': a handle no actor has places nothing');
		}
	}
}
