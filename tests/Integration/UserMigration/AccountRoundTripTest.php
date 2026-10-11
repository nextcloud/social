<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\UserMigration;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\FilterController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\MediaApiController;
use OCA\Social\Controller\RelationController;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\MigrationArchiveService;
use OCP\Server;
use ZipArchive;

/**
 * A whole account out through the exporter and back in on a fresh account,
 * compared entity by entity through the Mastodon API.
 *
 * `alice` is built through the routes a client calls: a post with a picture
 * uploaded through `POST /api/v1/media`, one behind a content warning, one
 * with a poll; follows of two local accounts and two of `roundtrip.example`
 * (which answer with an `Accept` through the inbox), followers of both kinds,
 * two lists, a v2 filter with two keywords, a bookmark and a favourite on a
 * local and on a remote post, a profile with a bio, fields, a banner, an
 * avatar and its flags, one timed mute, one that hides notifications, a
 * block and a blocked server. The archive is written by `SocialMigrator`
 * through `MigrationArchiveService::export()` — the migrator Nextcloud's
 * account export runs too — and read back through
 * `MigrationArchiveService::import()`, then the posts of the same archive
 * through `PostImportService`.
 *
 * Two setups, one comparison (`RoundTripTestCase::importAndCompare()`):
 *
 * - **One server** (the default, and CI's): the archive is imported into
 *   `dora` on this same instance, after `alice` exported it.
 * - **Two servers**: with `SOCIAL_ROUNDTRIP_TARGET_ROOT` naming a second,
 *   freshly installed Nextcloud with this app, the export half writes a
 *   bundle — the archive, the views of `alice`, and the accounts and posts
 *   the second server would have fetched while importing, since there is no
 *   network — and runs `AccountRoundTripImportTest` there in a phpunit of
 *   its own. That server must answer at an address of its own
 *   (`social_url`), or this server's accounts would look local to it.
 *   `SOCIAL_ROUNDTRIP_EXPORT_TO` keeps the bundle in a directory, to run the
 *   import half by hand later (`SOCIAL_ROUNDTRIP_BUNDLE`).
 *
 * What deliberately does not travel is pinned as such: the private key and
 * the OAuth tokens are in no file of the archive, the avatar is the
 * Nextcloud account's (core's migrator carries it), the followers are a
 * record, the account import writes no post, the posts import writes a poll
 * as its text. Two things nothing promised are pinned as they are: filters,
 * and a list's replies policy and exclusivity (Mastodon's `lists.csv` carries
 * neither).
 */
class AccountRoundTripTest extends RoundTripTestCase {
	public const ENV_TARGET = 'SOCIAL_ROUNDTRIP_TARGET_ROOT';
	public const ENV_EXPORT_TO = 'SOCIAL_ROUNDTRIP_EXPORT_TO';

	private const REMOTES = ['fern', 'gus', 'hal', 'ivy', 'jay', 'ken'];

	/** @var string[] files this test made outside the database */
	private array $files = [];

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_dir($file)) {
				array_map('unlink', glob($file . '/*') ?: []);
				@rmdir($file);
			} else {
				@unlink($file);
			}
		}
		$this->files = [];
		parent::tearDown();
	}

	public function testAFullAccountComesBackEntityByEntity(): void {
		$seed = $this->buildAlice();
		$handles = array_map(static fn (array $actor): string => $actor['account'], $seed['actors']);
		$views = $this->views(self::SOURCE, $handles);
		$this->assertScenarioIsWhole($views);

		$archive = Server::get(MigrationArchiveService::class)->export(self::PREFIX . self::SOURCE);
		$this->files[] = $archive;
		$this->assertWhatStaysBehindIsNotInTheArchive($archive);

		$target = (string)getenv(self::ENV_TARGET);
		$keep = (string)getenv(self::ENV_EXPORT_TO);
		$bundle = $keep;
		if ($bundle === '') {
			$bundle = sys_get_temp_dir() . '/social-roundtrip-' . bin2hex(random_bytes(6));
			$this->files[] = $bundle;
		}
		$this->writeBundle($bundle, $archive, [
			'socialUrl' => $this->socialUrl(),
			'actorId' => $this->account(self::SOURCE)->getId(),
			'handles' => $handles,
			'views' => $views,
			'seed' => $seed,
		]);

		if ($target !== '') {
			$this->importOnTheSecondServer($target, $bundle);

			return;
		}

		$this->importAndCompare($bundle);
	}

	/**
	 * Builds `alice` and the world around her.
	 *
	 * @return array{actors: list<array<string, string>>, posts: list<array<string, string>>}
	 *                                                                                        the accounts and the posts of other
	 *                                                                                        people the archive names, as a server
	 *                                                                                        importing it would cache them
	 */
	private function buildAlice(): array {
		$alice = self::SOURCE;
		$remote = [];
		foreach (self::REMOTES as $name) {
			$remote[$name] = $this->remote($name);
		}
		$lou = $this->seedActor([
			'id' => 'https://' . self::BLOCKED_DOMAIN . '/users/lou',
			'username' => 'lou',
			'account' => 'lou@' . self::BLOCKED_DOMAIN,
			'name' => 'Lou',
		]);

		$bread = $this->publish('bob', ['status' => 'The bread is rising', 'visibility' => 'public']);
		$fernPost = $this->seedPost([
			'id' => $remote['fern']->getId() . '/statuses/1', 'attributedTo' => $remote['fern']->getId(),
			'content' => '<p>Seedlings are up</p>', 'published' => '2026-09-01T10:00:00Z',
		]);
		$gusPost = $this->seedPost([
			'id' => $remote['gus']->getId() . '/statuses/1', 'attributedTo' => $remote['gus']->getId(),
			'content' => '<p>Rain all week</p>', 'published' => '2026-09-02T10:00:00Z',
		]);

		// the follows, before the account is locked; the remote ones answered
		// by their server
		foreach (['bob', 'carol'] as $name) {
			$this->follow($alice, $name);
		}
		foreach (['fern', 'gus'] as $name) {
			$this->ok($this->call(AccountApiController::class, 'accountFollow', ['id' => (string)$remote[$name]->getNid()], [], $alice, 'POST'));
			$follow = Server::get(FollowsRequest::class)->getByPersons($this->account($alice)->getId(), $remote[$name]->getId());
			$this->deliver([
				'@context' => ACore::CONTEXT_ACTIVITYSTREAMS,
				'id' => $remote[$name]->getId() . '#accepts/' . bin2hex(random_bytes(4)),
				'type' => 'Accept',
				'actor' => $remote[$name]->getId(),
				'object' => ['id' => $follow->getId(), 'type' => 'Follow', 'actor' => $this->account($alice)->getId(), 'object' => $remote[$name]->getId()],
			]);
		}
		$this->follow('bob', $alice);
		$this->deliver([
			'@context' => ACore::CONTEXT_ACTIVITYSTREAMS,
			'id' => $remote['hal']->getId() . '#follows/' . bin2hex(random_bytes(4)),
			'type' => 'Follow',
			'actor' => $remote['hal']->getId(),
			'object' => $this->account($alice)->getId(),
		]);

		$friends = $this->ok($this->call(ListController::class, 'create', [], ['title' => 'Friends', 'replies_policy' => 'followed'], $alice, 'POST'));
		$work = $this->ok($this->call(ListController::class, 'create', [], ['title' => 'Work', 'exclusive' => 'true'], $alice, 'POST'));
		$this->ok($this->call(ListController::class, 'addAccounts', ['id' => (int)$friends->id], ['account_ids' => [(string)$this->account('bob')->getNid(), (string)$remote['fern']->getNid()]], $alice, 'POST'));
		$this->ok($this->call(ListController::class, 'addAccounts', ['id' => (int)$work->id], ['account_ids' => [(string)$this->account('carol')->getNid(), (string)$remote['gus']->getNid()]], $alice, 'POST'));

		$this->ok($this->call(FilterController::class, 'create', [], [
			'title' => 'Spoilers',
			'context' => ['home', 'public'],
			'filter_action' => 'warn',
			'keywords_attributes' => [['keyword' => 'finale', 'whole_word' => 'true'], ['keyword' => 'season three', 'whole_word' => 'false']],
		], $alice, 'POST'));

		foreach ([$bread->id, $fernPost] as $nid) {
			$this->act($alice, (string)$nid, 'bookmark');
		}
		foreach ([$bread->id, $gusPost] as $nid) {
			$this->act($alice, (string)$nid, 'favourite');
		}

		$this->ok($this->call(AccountApiController::class, 'accountMute', ['id' => (string)$remote['ivy']->getNid()], ['notifications' => 'true', 'duration' => 86400], $alice, 'POST'));
		$this->ok($this->call(AccountApiController::class, 'accountMute', ['id' => (string)$remote['jay']->getNid()], ['notifications' => 'false'], $alice, 'POST'));
		$this->ok($this->call(AccountApiController::class, 'accountBlock', ['id' => (string)$remote['ken']->getNid()], [], $alice, 'POST'));
		$this->ok($this->call(RelationController::class, 'blockDomain', [], ['domain' => self::BLOCKED_DOMAIN], $alice, 'POST'));

		$this->publishTheThreePosts();
		$this->dressTheProfile();

		$actors = [];
		foreach (array_merge(['bob', 'carol'], self::REMOTES, ['lou']) as $name) {
			$actor = match ($name) {
				'bob', 'carol' => $this->account($name),
				'lou' => $lou,
				default => $remote[$name],
			};
			$actors[] = [
				'id' => $actor->getId(),
				'username' => $actor->getPreferredUsername(),
				'account' => $actor->getAccount(),
				'name' => $actor->getName(),
			];
		}

		$posts = [];
		foreach ([(string)$bread->uri, $remote['fern']->getId() . '/statuses/1', $remote['gus']->getId() . '/statuses/1'] as $id) {
			$post = Server::get(StreamRequest::class)->getStreamById($id);
			$posts[] = [
				'id' => $post->getId(),
				'attributedTo' => $post->getAttributedTo(),
				'content' => $post->getContent(),
				'published' => $post->getPublished(),
			];
		}

		return ['actors' => $actors, 'posts' => $posts];
	}

	/** A picture uploaded and posted, a post behind a warning, and a poll. */
	private function publishTheThreePosts(): void {
		$file = $this->picture(64, 48);
		$upload = $this->call(MediaApiController::class, 'mediaNew', [], [
			'description' => 'a red square on black',
		], self::SOURCE, 'POST', '/apps/social/api/v1/media', [
			'file' => ['name' => 'square.png', 'type' => 'image/png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)],
		]);
		$this->ok($upload);

		$this->publish(self::SOURCE, ['status' => 'A red square', 'media_ids' => [$upload->body->id], 'visibility' => 'public', 'language' => 'en']);
		$this->publish(self::SOURCE, ['status' => 'The last episode was wonderful', 'spoiler_text' => 'TV', 'visibility' => 'unlisted', 'language' => 'en']);
		$this->publish(self::SOURCE, [
			'status' => 'Cats or dogs?', 'visibility' => 'public', 'language' => 'en',
			'poll' => ['options' => ['cats', 'dogs'], 'expires_in' => 86400, 'multiple' => 'false'],
		]);
	}

	/** The bio, fields and flags through `update_credentials`, with the banner; the avatar as the Nextcloud account's picture. */
	private function dressTheProfile(): void {
		$banner = $this->picture(120, 40);
		$this->ok($this->call(AccountApiController::class, 'updateCredentials', [], [
			'note' => 'Gardener and baker',
			'locked' => 'true',
			'discoverable' => 'false',
			'indexable' => 'true',
			'fields_attributes' => [['name' => 'Garden', 'value' => 'Tomatoes'], ['name' => 'Oven', 'value' => 'Sourdough']],
		], self::SOURCE, 'PATCH', '/apps/social/api/v1/accounts/update_credentials', [
			'header' => ['name' => 'banner.png', 'type' => 'image/png', 'tmp_name' => $banner, 'error' => UPLOAD_ERR_OK, 'size' => filesize($banner)],
		]));

		Server::get(AvatarService::class)->setFromFile(self::PREFIX . self::SOURCE, $this->picture(32, 32));
	}

	/**
	 * Fails unless what was built is all there, so that an empty view on both
	 * sides of the comparison cannot pass for a round trip.
	 *
	 * @param array<string, mixed> $views
	 */
	private function assertScenarioIsWhole(array $views): void {
		$this->assertSame('Gardener and baker', $views['profile']['note']);
		$this->assertSame([120, 40], $views['profile']['header']);
		$this->assertTrue($views['profile']['locked']);
		$this->assertCount(4, $views['following']);
		$this->assertSame([$this->handleOf('bob'), 'hal@' . self::HOST], $views['followers']);
		$this->assertSame(['ivy@' . self::HOST, 'jay@' . self::HOST], $views['mutes']);
		$this->assertSame(['ken@' . self::HOST], $views['blocks']);
		$this->assertSame([self::BLOCKED_DOMAIN], $views['domain_blocks']);
		$this->assertSame(['Friends', 'Work'], array_keys($views['lists']));
		$this->assertSame('followed', $views['lists']['Friends']['replies_policy']);
		$this->assertTrue($views['lists']['Work']['exclusive']);
		$this->assertSame([[$this->handleOf('bob'), 'fern@' . self::HOST]], [$views['lists']['Friends']['accounts']]);
		$this->assertCount(2, $views['bookmarks']);
		$this->assertCount(2, $views['favourites']);
		$this->assertSame(['Spoilers'], array_keys($views['filters']));
		$this->assertCount(3, $views['statuses']);
		$this->assertTrue($views['relationships']['ivy@' . self::HOST]['muting_notifications']);
		$this->assertNotNull($views['relationships']['ivy@' . self::HOST]['mute_expires_at']);
		$this->assertFalse($views['relationships']['jay@' . self::HOST]['muting_notifications']);
		$this->assertTrue($views['relationships']['lou@' . self::BLOCKED_DOMAIN]['domain_blocking']);
	}

	/**
	 * The archive holds the account's files in Mastodon's layout, and none of
	 * what is documented to stay behind: no private key, no OAuth token, and
	 * no copy of the avatar, which is the Nextcloud account's.
	 */
	private function assertWhatStaysBehindIsNotInTheArchive(string $path): void {
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path, ZipArchive::RDONLY));
		$names = [];
		$everything = '';
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$names[] = $name;
			$everything .= (string)$zip->getFromIndex($i);
		}
		$actor = json_decode((string)$zip->getFromName('social/actor.json'), true);
		$outbox = json_decode((string)$zip->getFromName('social/outbox.json'), true);
		$followers = (string)$zip->getFromName('social/followers.csv');
		$zip->close();

		foreach (['social/lists.csv', 'social/blocked_domains.csv', 'social/following_accounts.csv', 'social/muted_accounts.csv', 'social/export.json'] as $file) {
			$this->assertContains($file, $names);
		}
		$this->assertSame(1, count(preg_grep('#^social/media_attachments/files/[0-9]+/original\.png$#', $names) ?: []), implode("\n", $names));
		$this->assertSame('media_attachments/header.png', $actor['headerFile'] ?? null);
		$this->assertArrayNotHasKey('avatarFile', $actor, 'the avatar is the Nextcloud account\'s, carried by core\'s migrator');
		$this->assertSame([], preg_grep('#avatar#', $names) ?: []);

		$this->assertArrayNotHasKey('privateKey', $actor);
		$this->assertStringContainsString('PUBLIC KEY', (string)$actor['publicKey']);
		$this->assertStringNotContainsString('PRIVATE KEY', $everything);
		$this->assertStringNotContainsString($this->token(self::SOURCE), $everything, 'no OAuth token travels');

		$this->assertSame(3, $outbox['totalItems']);
		$this->assertStringContainsString('hal@' . self::HOST, $followers, 'the followers are written down as a record');
	}

	/** Runs the import half on another server, in a phpunit of its own. */
	private function importOnTheSecondServer(string $root, string $bundle): void {
		$this->assertFileExists($root . '/lib/base.php', self::ENV_TARGET . ' names no Nextcloud server');
		$app = dirname(__DIR__, 3);
		$command = [
			PHP_BINARY, '-d', 'memory_limit=2G', $app . '/vendor/bin/phpunit',
			'-c', $app . '/tests/Integration/phpunit.xml', '--filter', 'AccountRoundTripImportTest',
		];
		$env = array_merge(getenv(), [
			'NEXTCLOUD_ROOT' => $root,
			AccountRoundTripImportTest::ENV_BUNDLE => $bundle,
			self::ENV_TARGET => '',
		]);

		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $app, $env);
		$this->assertIsResource($process);
		$output = (string)stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$exit = proc_close($process);

		$this->assertSame(0, $exit, $output);
		$this->assertMatchesRegularExpression('/OK \(1 test,/', $output, 'the import half ran rather than skipped: ' . $output);
	}

	private function picture(int $width, int $height): string {
		$file = (string)tempnam(sys_get_temp_dir(), 'social-roundtrip');
		$image = imagecreatetruecolor($width, $height);
		imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height - 1, (int)imagecolorallocate($image, 200, 40, 40));
		imagepng($image, $file);
		$this->files[] = $file;

		return $file;
	}
}
