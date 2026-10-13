<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\MarkStreamColumnsFilled;
use OCA\Social\Migration\Version1000Date20260912000010;
use OCA\Social\Migration\Version1000Date20260917000001;
use OCA\Social\Migration\Version1000Date20260917000003;
use OCA\Social\Migration\Version1000Date20260917000005;
use OCA\Social\Migration\Version1000Date20261013100500;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade steps that fill in rows rather than change the schema, run
 * against the real database the way an upgrade runs them: rows stored in the
 * shape the previous version left them, the step, and the rows read back.
 * Each is run twice, because an upgrade that stopped half way is run again,
 * and the second run must change nothing the first one got right.
 *
 * Their raw SQL (`*PREFIX*`, backticks, CASE … WHEN with bound parameters)
 * is exactly what the unit suite cannot reach.
 */
class RowBackfillMigrationsTest extends TestCase {
	private IDBConnection $connection;
	/** @var string[] stream prims of this run */
	private array $streams = [];
	/** @var int[] client ids of this run */
	private array $clients = [];
	private string $destNidFilled;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = Server::get(IDBConnection::class);
		$this->destNidFilled = Server::get(IAppConfig::class)->getValueString('social', ConfigService::SOCIAL_DEST_NID_FILLED, '0');
	}

	protected function tearDown(): void {
		foreach ($this->streams as $prim) {
			RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $prim]);
			RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM_DEST, ['stream_id' => $prim]);
		}
		foreach ($this->clients as $id) {
			RowSeeder::delete(CoreRequestBuilder::TABLE_CLIENT, ['id' => $id]);
			RowSeeder::delete(CoreRequestBuilder::TABLE_CLIENT_AUTH, ['client_id' => $id]);
		}
		Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_DEST_NID_FILLED, $this->destNidFilled);
		parent::tearDown();
	}

	private function runStep(string $class): void {
		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);

		Server::get($class)->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);
	}

	/** @param array<string, mixed> $values */
	private function stream(array $values = []): string {
		$prim = md5(random_bytes(16));
		$this->streams[] = $prim;
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, array_merge(['id_prim' => $prim], $values));

		return $prim;
	}

	private function value(string $table, string $column, string $keyColumn, string $key): mixed {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($column)->from($table)
			->where($qb->expr()->eq($keyColumn, $qb->createNamedParameter($key)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value;
	}

	/** Every recipient row gets its post's nid, in one CASE statement per page of posts. */
	public function testTheRecipientRowsAreGivenTheNidOfTheirPost(): void {
		$one = $this->stream();
		$two = $this->stream();
		foreach ([$one, $one, $two] as $prim) {
			RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM_DEST, ['stream_id' => $prim, 'nid' => 0]);
		}
		Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_DEST_NID_FILLED, '0');

		$this->runStep(Version1000Date20260917000001::class);

		foreach ([$one, $two] as $prim) {
			$nid = (string)$this->value(CoreRequestBuilder::TABLE_STREAM, 'nid', 'id_prim', $prim);
			$this->assertSame(0, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM_DEST, ['stream_id' => $prim, 'nid' => 0]));
			$this->assertSame(
				$prim === $one ? 2 : 1,
				RowSeeder::count(CoreRequestBuilder::TABLE_STREAM_DEST, ['stream_id' => $prim, 'nid' => $nid])
			);
		}
		$this->assertSame('1', Server::get(IAppConfig::class)->getValueString('social', ConfigService::SOCIAL_DEST_NID_FILLED), 'the read path may rely on it now');

		$this->runStep(Version1000Date20260917000001::class);
		$this->assertSame(2, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM_DEST, [
			'stream_id' => $one, 'nid' => (string)$this->value(CoreRequestBuilder::TABLE_STREAM, 'nid', 'id_prim', $one),
		]), 'run again, nothing moves');
	}

	/**
	 * A row stored before `archived` existed carries NULL there; it is given
	 * a 0 and the flag says every list may drop the `OR` that admitted the
	 * NULL. A row that was archived keeps its 1.
	 */
	public function testThePostsWithNoArchiveFlagAreGivenOne(): void {
		$flagBefore = Server::get(IAppConfig::class)->getValueString('social', ConfigService::SOCIAL_STREAM_ARCHIVED_FILLED, '0');
		$blank = $this->stream(['archived' => null]);
		$kept = $this->stream(['archived' => true]);
		Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_STREAM_ARCHIVED_FILLED, '0');

		try {
			$this->runStep(Version1000Date20261013100500::class);

			$this->assertSame(0, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $blank, 'archived' => null]));
			$this->assertSame(1, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $blank, 'archived' => false]));
			$this->assertSame(1, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $kept, 'archived' => true]));
			$this->assertSame(
				'1',
				Server::get(IAppConfig::class)->getValueString('social', ConfigService::SOCIAL_STREAM_ARCHIVED_FILLED),
				'the lists may drop the OR now'
			);

			$this->runStep(Version1000Date20261013100500::class);
			$this->assertSame(1, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $kept, 'archived' => true]), 'run again, nothing moves');
		} finally {
			Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_STREAM_ARCHIVED_FILLED, $flagBefore);
		}
	}

	/**
	 * The media filters may drop their fallback over the attachments once no
	 * row is left without a `media_kind` — and not before: a row the
	 * earlier backfill has not reached keeps the flag down.
	 */
	public function testTheMediaFiltersAreToldWhenEveryRowHasItsKind(): void {
		$flagBefore = Server::get(IAppConfig::class)->getValueString('social', ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED, '0');
		$unfilled = $this->stream(['media_kind' => null]);
		Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED, '0');

		try {
			$this->runStep(Version1000Date20261013100500::class);
			$this->assertSame(
				'0',
				Server::get(IAppConfig::class)->getValueString('social', ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED, '0'),
				'a row without a kind keeps the fallback'
			);

			RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $unfilled]);
			$this->runStep(Version1000Date20261013100500::class);
			$this->assertSame(
				'1',
				Server::get(IAppConfig::class)->getValueString('social', ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED, '0'),
				'no row without a kind: the filters may rely on the column'
			);
		} finally {
			Server::get(IAppConfig::class)->setValueString('social', ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED, $flagBefore);
		}
	}

	/**
	 * Nextcloud installs an app with its migrations schema-only, so the
	 * install step is what raises both flags there: on tables with nothing
	 * left to fill, both go up.
	 */
	public function testTheInstallStepRaisesBothFlagsWhereNothingIsLeftToFill(): void {
		$config = Server::get(IAppConfig::class);
		$before = [
			ConfigService::SOCIAL_STREAM_ARCHIVED_FILLED => $config->getValueString('social', ConfigService::SOCIAL_STREAM_ARCHIVED_FILLED, '0'),
			ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED => $config->getValueString('social', ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED, '0'),
		];
		$blank = $this->stream(['archived' => null, 'media_kind' => 'image']);
		foreach ($before as $key => $value) {
			$config->setValueString('social', $key, '0');
		}

		try {
			$qb = $this->connection->getQueryBuilder();
			$qb->select($qb->func()->count('*'))->from(CoreRequestBuilder::TABLE_STREAM)->where($qb->expr()->isNull('media_kind'));
			$result = $qb->executeQuery();
			$withoutKind = (int)$result->fetchOne();
			$result->closeCursor();

			Server::get(MarkStreamColumnsFilled::class)->run($this->createStub(IOutput::class));

			$this->assertSame(1, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $blank, 'archived' => false]));
			$this->assertSame('1', $config->getValueString('social', ConfigService::SOCIAL_STREAM_ARCHIVED_FILLED, '0'));
			$this->assertSame(
				$withoutKind === 0 ? '1' : '0',
				$config->getValueString('social', ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED, '0'),
				'up exactly when no post is without a kind'
			);
		} finally {
			foreach ($before as $key => $value) {
				$config->setValueString('social', $key, $value);
			}
		}
	}

	/** Only the posts not yet worked out: a kind already stored is left alone, even a wrong one. */
	public function testThePostsWithNoMediaKindAreGivenOne(): void {
		$image = $this->stream(['media_kind' => null, 'attachments' => '[{"type":"image"}]', 'subtype' => '']);
		$video = $this->stream(['media_kind' => null, 'attachments' => '[]', 'subtype' => 'Video']);
		$none = $this->stream(['media_kind' => null, 'attachments' => '', 'subtype' => '']);
		$known = $this->stream(['media_kind' => 'audio', 'attachments' => '[{"type":"image"}]', 'subtype' => '']);

		for ($run = 0; $run < 2; $run++) {
			$this->runStep(Version1000Date20260917000003::class);

			$this->assertSame('image', $this->value(CoreRequestBuilder::TABLE_STREAM, 'media_kind', 'id_prim', $image));
			$this->assertSame('video', $this->value(CoreRequestBuilder::TABLE_STREAM, 'media_kind', 'id_prim', $video));
			$this->assertSame(Stream::MEDIA_KIND_NONE, (string)$this->value(CoreRequestBuilder::TABLE_STREAM, 'media_kind', 'id_prim', $none));
			$this->assertSame('audio', $this->value(CoreRequestBuilder::TABLE_STREAM, 'media_kind', 'id_prim', $known));
		}
	}

	public function testThePostsWithNoNewsKindAreGivenOne(): void {
		$link = '<p>Read <a href="https://news.example/article">this</a></p>';
		$news = $this->stream(['news_kind' => null, 'content' => $link, 'subtype' => '']);
		$plain = $this->stream(['news_kind' => null, 'content' => '<p>just words</p>', 'subtype' => '']);
		$known = $this->stream(['news_kind' => 'kept', 'content' => $link, 'subtype' => '']);

		for ($run = 0; $run < 2; $run++) {
			$this->runStep(Version1000Date20260917000005::class);

			$this->assertSame(Stream::newsKindOf($link, ''), $this->value(CoreRequestBuilder::TABLE_STREAM, 'news_kind', 'id_prim', $news));
			$this->assertNotSame('', Stream::newsKindOf($link, ''), 'a link is news');
			$this->assertSame('', (string)$this->value(CoreRequestBuilder::TABLE_STREAM, 'news_kind', 'id_prim', $plain));
			$this->assertNotNull($this->value(CoreRequestBuilder::TABLE_STREAM, 'news_kind', 'id_prim', $plain), 'worked out, not left NULL');
			$this->assertSame('kept', $this->value(CoreRequestBuilder::TABLE_STREAM, 'news_kind', 'id_prim', $known));
		}
	}

	/** @param array<string, mixed> $values */
	private function client(array $values): int {
		$clientId = bin2hex(random_bytes(8));
		RowSeeder::insert(CoreRequestBuilder::TABLE_CLIENT, array_merge([
			'app_client_id' => $clientId, 'auth_user_id' => '', 'auth_account' => '', 'auth_scopes' => '["read"]',
			'auth_code' => '', 'token' => '',
		], $values));
		$id = (int)$this->value(CoreRequestBuilder::TABLE_CLIENT, 'id', 'app_client_id', $clientId);
		$this->clients[] = $id;

		return $id;
	}

	/**
	 * A token in use before authorizations had a table of their own keeps
	 * working: it is carried across with its user, account and scopes. An
	 * app registered and never authorized, or one whose token was already
	 * blanked, has nothing to carry.
	 */
	public function testEachClientsAuthorizationIsCarriedIntoARowOfItsOwn(): void {
		$authorized = $this->client(['auth_user_id' => 'alice', 'auth_account' => 'alice', 'token' => 'hashed-token-' . bin2hex(random_bytes(4))]);
		$pending = $this->client(['auth_user_id' => 'bob', 'auth_code' => 'code-' . bin2hex(random_bytes(4))]);
		$never = $this->client([]);
		$blanked = $this->client(['auth_user_id' => 'carol']);

		for ($run = 0; $run < 2; $run++) {
			$this->runStep(Version1000Date20260912000010::class);

			$this->assertSame(1, RowSeeder::count(CoreRequestBuilder::TABLE_CLIENT_AUTH, ['client_id' => $authorized, 'user_id' => 'alice', 'account' => 'alice', 'scopes' => '["read"]']));
			$this->assertSame(1, RowSeeder::count(CoreRequestBuilder::TABLE_CLIENT_AUTH, ['client_id' => $pending, 'user_id' => 'bob']));
			$this->assertSame(0, RowSeeder::count(CoreRequestBuilder::TABLE_CLIENT_AUTH, ['client_id' => $never]));
			$this->assertSame(0, RowSeeder::count(CoreRequestBuilder::TABLE_CLIENT_AUTH, ['client_id' => $blanked]));
		}
		$this->assertSame(
			(string)$this->value(CoreRequestBuilder::TABLE_CLIENT, 'token', 'id', (string)$authorized),
			(string)$this->value(CoreRequestBuilder::TABLE_CLIENT_AUTH, 'token', 'client_id', (string)$authorized),
			'the token itself, so the app signed in with it stays signed in'
		);
	}
}
