<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\UserMigration;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\MigrationArchiveService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\PostImportService;
use OCP\Server;
use OCP\UserMigration\UserMigrationException;

/**
 * Moving in from Mastodon: an export shaped after Mastodon's documented
 * format (see `MastodonExport`) through the imports the Migration page runs,
 * and the account compared through the Mastodon API afterwards.
 *
 * The posts archive goes through `PostImportService`, the six CSV files
 * through `MigrationService` — the follows first, as the page asks, because a
 * list holds only accounts this one follows. The accounts and the posts
 * those files name are cached here first, as a fetch would have cached them.
 *
 * Pinned as it is rather than as a gap closed: nothing reads `likes.json`,
 * `bookmarks.json` or `actor.json` out of Mastodon's archive — the
 * favourites, the profile and its pictures stay as they were (bookmarks come
 * in through `bookmarks.csv`) — and the account-archive import refuses the
 * archive, which is Mastodon's and not this app's.
 */
class MastodonExportImportTest extends RoundTripTestCase {
	/** @var string[] */
	private array $files = [];

	#[\Override]
	protected function tearDown(): void {
		array_map('unlink', array_filter($this->files, 'is_file'));
		$this->files = [];
		parent::tearDown();
	}

	public function testAMastodonExportComesInThroughTheMigrationImports(): void {
		$dora = self::TARGET;
		$userId = self::PREFIX . $dora;
		foreach (['fern', 'gus', 'jay', 'ken'] as $name) {
			$this->remote($name);
		}
		$fern = 'https://' . self::HOST . '/users/fern';
		$gus = 'https://' . self::HOST . '/users/gus';
		$this->seedPost(['id' => $fern . '/statuses/7', 'attributedTo' => $fern, 'content' => '<p>Bean poles</p>', 'published' => '2024-02-01T10:00:00Z']);
		$this->seedPost(['id' => $gus . '/statuses/8', 'attributedTo' => $gus, 'content' => '<p>Frost tonight</p>', 'published' => '2024-02-02T10:00:00Z']);
		$before = $this->views($dora, []);

		$archive = (string)tempnam(sys_get_temp_dir(), 'social-mastodon-export');
		$this->files[] = $archive;
		MastodonExport::archive($archive, $this->picture(40, 30), $gus . '/statuses/8', $fern . '/statuses/7');

		$tally = Server::get(PostImportService::class)->import(
			Server::get(AccountService::class)->getActorFromUserId($userId), $archive, false
		);
		$this->assertSame(['imported' => 3, 'skipped' => 2, 'media' => 1], array_intersect_key($tally, ['imported' => 0, 'skipped' => 0, 'media' => 0]), (string)json_encode($tally));

		$migration = Server::get(MigrationService::class);
		$this->assertSame(2, $migration->importFollows($userId, MastodonExport::followingCsv('fern@' . self::HOST, 'gus@' . self::HOST))['followed']);
		$this->assertSame(1, $migration->importBlocks($userId, MastodonExport::blockedCsv('ken@' . self::HOST))['blocked']);
		$this->assertSame(1, $migration->importMutes($userId, MastodonExport::mutedCsv(['jay@' . self::HOST => true]))['muted']);
		$lists = $migration->importLists($userId, MastodonExport::listsCsv(['Garden' => ['fern@' . self::HOST, 'gus@' . self::HOST]]));
		$this->assertSame(['lists' => 1, 'added' => 2], array_intersect_key($lists, ['lists' => 0, 'added' => 0]), (string)json_encode($lists));
		$this->assertSame(1, $migration->importBookmarks($userId, MastodonExport::bookmarksCsv($fern . '/statuses/7'))['bookmarked']);
		$this->assertSame(1, $migration->importDomainBlocks($userId, MastodonExport::blockedDomainsCsv(self::BLOCKED_DOMAIN))['blocked']);

		$views = $this->views($dora, ['fern@' . self::HOST, 'gus@' . self::HOST, 'jay@' . self::HOST, 'ken@' . self::HOST]);

		$this->assertSame([
			[
				'text' => 'First light on the allotment', 'spoiler_text' => '', 'sensitive' => false, 'visibility' => 'public',
				'created_at' => '2024-03-01T09:00:00.000Z',
				'media' => [['type' => 'image', 'description' => 'a row of seedlings', 'size' => [40, 30]]], 'poll' => null,
			],
			[
				'text' => 'The finale was a letdown', 'spoiler_text' => 'TV spoilers', 'sensitive' => true, 'visibility' => 'public',
				'created_at' => '2024-03-02T09:00:00.000Z', 'media' => [], 'poll' => null,
			],
			[
				'text' => 'They came up after all', 'spoiler_text' => '', 'sensitive' => false, 'visibility' => 'unlisted',
				'created_at' => '2024-03-03T09:00:00.000Z', 'media' => [], 'poll' => null,
			],
		], $views['statuses'], 'the boost and the direct message are not the account\'s to bring');
		$this->assertReplyKeepsItsParent($dora);

		foreach (['fern', 'gus'] as $name) {
			$this->assertTrue($views['relationships'][$name . '@' . self::HOST]['following'], $name . ' is followed, or asked to be');
		}
		$this->assertSame(['ken@' . self::HOST], $views['blocks']);
		$this->assertSame(['jay@' . self::HOST], $views['mutes']);
		$this->assertFalse($views['relationships']['jay@' . self::HOST]['muting_notifications'], 'Hide notifications was true');
		$this->assertSame(['Garden'], array_keys($views['lists']));
		$this->assertSame(['fern@' . self::HOST, 'gus@' . self::HOST], $views['lists']['Garden']['accounts']);
		$this->assertSame([$fern . '/statuses/7'], $views['bookmarks']);
		$this->assertSame([self::BLOCKED_DOMAIN], $views['domain_blocks']);

		// read by nothing: likes.json names a post this server holds, and
		// actor.json a bio, a field and two pictures
		$this->assertSame([], $views['favourites']);
		$this->assertSame($before['profile'], $views['profile']);

		try {
			Server::get(MigrationArchiveService::class)->import($userId, $archive);
			$this->fail('a Mastodon archive is not an Aloha Social one');
		} catch (UserMigrationException $e) {
			$this->assertStringContainsString('social/actor.json is not in it', $e->getMessage());
		}

		$again = Server::get(PostImportService::class)->import(
			Server::get(AccountService::class)->getActorFromUserId($userId), $archive, false
		);
		$this->assertSame([0, 3], [$again['imported'], $again['already']], 'a second run writes nothing twice');
	}

	/** The reply in the archive answers the first post, and still does here. */
	private function assertReplyKeepsItsParent(string $name): void {
		$statuses = $this->ok($this->call(
			AccountApiController::class, 'accountStatuses', ['account' => (string)$this->account($name)->getNid()], ['limit' => 40], $name
		));
		$byText = [];
		foreach ($statuses as $status) {
			$byText[trim(strip_tags((string)$status->content))] = $status;
		}

		$this->assertSame($byText['First light on the allotment']->id, $byText['They came up after all']->in_reply_to_id);
	}

	private function picture(int $width, int $height): string {
		$image = imagecreatetruecolor($width, $height);
		imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height - 1, (int)imagecolorallocate($image, 40, 160, 40));
		ob_start();
		imagepng($image);

		return (string)ob_get_clean();
	}
}
