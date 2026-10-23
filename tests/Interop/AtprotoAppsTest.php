<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Accounts\IAccountManager;
use OCP\IAvatarManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 3c: a Bluesky app signs in to this instance as its PDS with an app
 * password, reads its timeline through it from the development AppView, and
 * what it writes — a post, a like — happens here as the Social action it
 * stands for and reaches Bluesky as the record Social wrote.
 */
class AtprotoAppsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	#[\Override]
	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('ap');
	}

	public function testABlueskyAppSignsInReadsAndWritesThroughThisServer(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$password = Server::get(AppPasswordService::class)->create($this->alice->userId, 'interop')['password'];
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');

		[$status] = $app->signIn($identity->handle, 'wrong-pass-word-here');
		$this->assertSame(401, $status, 'a wrong password is refused');
		[$status] = $app->signIn($identity->handle, $this->alice->password);
		$this->assertSame(401, $status, 'a Nextcloud account password is never accepted by the PDS');
		[$status, $answer] = $app->signIn($identity->handle, $password);
		$this->assertSame(200, $status, json_encode($answer));
		$this->assertSame($identity->did, $answer['did']);
		[$status] = $app->procedure('com.atproto.server.deleteSession', [], $app->refreshJwt);
		$this->assertSame(200, $status, 'the handle sign-in can be ended');

		$email = 'atproto-' . bin2hex(random_bytes(6)) . '@example.org';
		$user = Server::get(IUserManager::class)->get($this->alice->userId);
		$this->assertNotNull($user);
		$user->setEMailAddress($email);
		$accounts = Server::get(IAccountManager::class);
		$account = $accounts->getAccount($user);
		$account->setProperty(IAccountManager::PROPERTY_EMAIL, $email, IAccountManager::SCOPE_LOCAL, IAccountManager::NOT_VERIFIED);
		$accounts->updateAccount($account);
		[$status, $answer] = $app->signIn($email, $password);
		$this->assertSame(200, $status, 'the Nextcloud email address signs into this PDS: ' . json_encode($answer));
		$this->assertSame($identity->did, $answer['did']);

		[$status, $session] = $app->query('com.atproto.server.getSession');
		$this->assertSame(200, $status);
		$this->assertSame($identity->handle, $session['handle']);
		$this->assertSame($email, $session['email']);
		$this->assertFalse($session['emailConfirmed'], 'an address without Nextcloud verification is not reported as verified');

		$profileDid = $this->network->createUser('appprofile' . bin2hex(random_bytes(3)));
		$profileWords = ['App profile post one ' . bin2hex(random_bytes(3)), 'App profile post two ' . bin2hex(random_bytes(3))];
		foreach ($profileWords as $profileWord) {
			$this->network->postText($profileWord);
		}
		$this->assertNotNull($this->network->await(function () use ($profileDid, $profileWords): ?bool {
			$profile = $this->network->profile($profileDid);
			$texts = array_map(static fn (array $item): string => (string)($item['post']['record']['text'] ?? ''), $this->network->authorFeed($profileDid));

			return isset($profile['did']) && count(array_intersect($profileWords, $texts)) === count($profileWords) ? true : null;
		}), 'the profile and multiple posts are indexed before the signed-in app reads them');
		[$status, $profile] = $app->query('app.bsky.actor.getProfile', ['actor' => $profileDid]);
		$this->assertSame(200, $status, json_encode($profile));
		$this->assertSame($profileDid, $profile['did']);
		[$status, $authorFeed] = $app->query('app.bsky.feed.getAuthorFeed', ['actor' => $profileDid, 'limit' => 10, 'filter' => 'posts_with_replies']);
		$this->assertSame(200, $status, json_encode($authorFeed));
		$feedTexts = array_map(static fn (array $item): string => (string)($item['post']['record']['text'] ?? ''), $authorFeed['feed'] ?? []);
		$this->assertEqualsCanonicalizing($profileWords, array_values(array_intersect($profileWords, $feedTexts)), 'the signed-in app reads multiple posts through its PDS');

		// the AppView answers through this server, as alice
		[$status, $timeline] = $this->network->await(function () use ($app): ?array {
			$answer = $app->query('app.bsky.feed.getTimeline', ['limit' => 5]);

			return $answer[0] === 200 ? $answer : null;
		});
		$this->assertSame(200, $status, 'the proxied timeline: ' . json_encode($timeline));
		$this->assertArrayHasKey('feed', $timeline);

		// a picture is stored as any upload, and served under the CID the
		// answer names, which is the stored copy's
		[$status, $uploaded] = $app->upload(self::picture(), 'image/png');
		$this->assertSame(200, $status, json_encode($uploaded));
		$cid = (string)$uploaded['blob']['ref']['$link'];
		$served = $app->blob($identity->did, $cid);
		$this->assertNotSame('', $served, 'getBlob serves it');
		$this->assertSame($cid, Cid::forRaw($served)->toString(), 'under the CID of its bytes');

		// a post written by the app is a Social post, and reaches the AppView
		$words = 'Posted from a Bluesky app ' . bin2hex(random_bytes(4));
		[$status, $created] = $app->procedure('com.atproto.repo.createRecord', [
			'repo' => $identity->did,
			'collection' => 'app.bsky.feed.post',
			'record' => [
				'$type' => 'app.bsky.feed.post', 'text' => $words, 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
				'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [['alt' => 'A red square', 'image' => $uploaded['blob']]]],
			],
		]);
		$this->assertSame(200, $status, json_encode($created));
		$this->assertStringStartsWith('at://' . $identity->did . '/app.bsky.feed.post/', $created['uri']);
		$mine = array_values(array_filter($this->alice->statusesOf($this->alice->accountId()), static fn (array $s): bool => str_contains((string)($s['content'] ?? ''), $words)));
		$this->assertCount(1, $mine, 'the post is here, a Social post');
		$onAppView = $this->network->await(function () use ($identity, $created): ?array {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (($item['post']['uri'] ?? '') === $created['uri']) {
					return $item['post'];
				}
			}

			return null;
		});
		$this->assertNotNull($onAppView, 'and on the AppView');
		$this->assertCount(1, $mine[0]['media_attachments'] ?? [], 'with its picture here');
		$this->assertSame('A red square', $mine[0]['media_attachments'][0]['description'] ?? null);
		$this->assertSame('app.bsky.embed.images#view', $onAppView['embed']['$type'] ?? null, 'and there');

		// a like of a dev user's post, not read here before
		$this->network->createUser('appsbob' . bin2hex(random_bytes(3)));
		$bobs = $this->network->postText('Like me ' . bin2hex(random_bytes(3)));
		$liked = $this->network->await(function () use ($app, $identity, $bobs): ?array {
			[$status, $answer] = $app->procedure('com.atproto.repo.createRecord', [
				'repo' => $identity->did,
				'collection' => 'app.bsky.feed.like',
				'record' => ['$type' => 'app.bsky.feed.like', 'subject' => $bobs, 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
			]);

			return $status === 200 ? $answer : null;
		});
		$this->assertNotNull($liked, 'the like was made once the AppView knew the post');
		$this->assertNotNull($this->network->await(fn () => in_array($identity->did, $this->network->likers($bobs['uri']), true) ? true : null), 'and counted there');

		// a block is refused while the person does not publish theirs
		[$status, $refused] = $app->procedure('com.atproto.repo.createRecord', ['repo' => $identity->did, 'collection' => 'app.bsky.graph.block', 'record' => ['$type' => 'app.bsky.graph.block', 'subject' => $this->network->userDid(), 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')]]);
		$this->assertSame(400, $status);
		$this->assertStringContainsString('unless you publish them', (string)($refused['message'] ?? ''));

		// the app's profile editor: a new picture becomes the account's avatar,
		// asked of the server (this process keeps the account's settings cached
		// from before the app's request)
		$before = (string)($this->alice->get('/api/v1/accounts/verify_credentials')['avatar'] ?? '');
		[$status, $saved] = $app->procedure('com.atproto.repo.putRecord', [
			'repo' => $identity->did, 'collection' => 'app.bsky.actor.profile', 'rkey' => 'self',
			'record' => ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Alice from an app', 'avatar' => $uploaded['blob']],
		]);
		$this->assertSame(200, $status, json_encode($saved));
		$this->assertNotSame($before, (string)($this->alice->get('/api/v1/accounts/verify_credentials')['avatar'] ?? ''), 'the picture is the account\'s avatar');
		$profile = Server::get(RepositoryService::class)->getRecord($identity->did, RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY);
		$this->assertArrayHasKey('avatar', $profile === null ? [] : (array)DagCbor::decode($profile->bytes), 'the profile record names the avatar: ' . $this->whyNoAvatar($identity));
		$this->assertNotNull($this->network->await(fn () => ($this->network->profile($identity->did)['avatar'] ?? '') !== '' ? true : null), 'and the AppView shows it');

		// deleting the post's record deletes the post here
		[$status] = $app->procedure('com.atproto.repo.deleteRecord', ['repo' => $identity->did, 'collection' => 'app.bsky.feed.post', 'rkey' => substr($created['uri'], (int)strrpos($created['uri'], '/') + 1)]);
		$this->assertSame(200, $status);
		$this->assertNull($this->alice->status((string)$mine[0]['id']), 'gone here');

		// signing out ends the session
		[$status] = $app->procedure('com.atproto.server.deleteSession', [], $app->refreshJwt);
		$this->assertSame(200, $status);
		[$status] = $app->query('com.atproto.server.getSession');
		$this->assertGreaterThanOrEqual(400, $status, 'the access token went with the session');
	}

	/** The official Bluesky password client signs in and reads populated feeds through this PDS. */
	public function testOfficialBlueskyPasswordClientReadsProfileAndTimelineContent(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$password = Server::get(AppPasswordService::class)->create($this->alice->userId, 'official-client')['password'];
		$profileDid = $this->network->createUser('officialfeed' . bin2hex(random_bytes(3)));
		$profileText = 'Official client profile content ' . bin2hex(random_bytes(3));
		$this->network->postText($profileText);
		$this->assertNotNull($this->network->await(function () use ($profileDid, $profileText): ?bool {
			$profile = $this->network->profile($profileDid);
			$feedTexts = array_map(static fn (array $item): string => (string)($item['post']['record']['text'] ?? ''), $this->network->authorFeed($profileDid));

			return isset($profile['did']) && in_array($profileText, $feedTexts, true) ? true : null;
		}), 'the public profile and its post are indexed before the official client reads them');

		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
		[$status, $session] = $app->signIn($identity->handle, $password);
		$this->assertSame(200, $status, json_encode($session));
		[$status, $follow] = $app->procedure('com.atproto.repo.createRecord', [
			'repo' => $identity->did,
			'collection' => 'app.bsky.graph.follow',
			'record' => ['$type' => 'app.bsky.graph.follow', 'subject' => $profileDid, 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
		]);
		$this->assertSame(200, $status, json_encode($follow));
		$this->assertNotNull($this->network->await(fn () => in_array($identity->did, $this->network->followers($profileDid), true) ? true : null), 'the AppView indexes the signed-in account\'s follow');

		$process = proc_open(
			['node', 'password-client.mjs'],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			__DIR__ . '/atproto',
			[
				'PATH' => (string)getenv('PATH'),
				'HOME' => (string)getenv('HOME'),
				'PDS_URL' => getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test',
				'PDS_IDENTIFIER' => $identity->handle,
				'PDS_PASSWORD' => $password,
				'PROFILE_DID' => $profileDid,
				'NODE_EXTRA_CA_CERTS' => getenv('NODE_EXTRA_CA_CERTS') ?: '/tmp/tls/ca.crt',
			]
		);
		if (!is_resource($process)) {
			throw new RuntimeException('the official Bluesky password client did not start');
		}

		$output = (string)stream_get_contents($pipes[1]);
		$error = (string)stream_get_contents($pipes[2]);
		$status = proc_close($process);
		$this->assertSame(0, $status, $error . $output);

		$result = json_decode($output, true);
		$this->assertIsArray($result);
		$this->assertSame($identity->did, $result['did']);
		$this->assertSame(200, $result['who']['status'], json_encode($result['who']));
		$this->assertSame($identity->handle, $result['who']['body']['handle']);
		$this->assertSame($identity->did, $result['refreshed']['did']);
		$this->assertSame($identity->handle, $result['refreshed']['handle']);
		$this->assertSame(200, $result['profile']['status'], json_encode($result['profile']));
		$this->assertSame($profileDid, $result['profile']['body']['did'] ?? null);
		$this->assertSame(200, $result['authorFeed']['status'], json_encode($result['authorFeed']));
		$profileTexts = array_map(static fn (array $item): string => (string)($item['post']['record']['text'] ?? ''), $result['authorFeed']['body']['feed'] ?? []);
		$this->assertContains($profileText, $profileTexts, 'the official Bluesky client reads the known post through the Social PDS');
		$this->assertSame(200, $result['timeline']['status'], json_encode($result['timeline']));
		$timelineTexts = array_map(static fn (array $item): string => (string)($item['post']['record']['text'] ?? ''), $result['timeline']['body']['feed'] ?? []);
		$this->assertContains($profileText, $timelineTexts, 'the official Bluesky client sees a followed author\'s post in its timeline');
		$this->assertGreaterThanOrEqual(400, $result['logout']['accessStatus'], 'logout revokes the official client access token');
		$this->assertGreaterThanOrEqual(400, $result['logout']['refreshStatus'], 'logout revokes the official client refresh token');
	}

	public function testPdsDoesNotRevealWhetherAnIdentifierHasAnAccount(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');

		[$knownStatus, $knownError] = $app->signIn($identity->handle, 'not-an-app-password');
		[$unknownStatus, $unknownError] = $app->signIn('did:plc:aaaaaaaaaaaaaaaaaaaaaaaa', 'not-an-app-password');

		$this->assertSame(401, $knownStatus);
		$this->assertSame($knownStatus, $unknownStatus);
		$this->assertSame($knownError['error'] ?? null, $unknownError['error'] ?? null);
		$this->assertSame($knownError['message'] ?? null, $unknownError['message'] ?? null);
	}

	/** A small PNG, made here so the test carries no binary file. */
	private static function picture(): string {
		$image = imagecreatetruecolor(64, 48);
		imagefill($image, 0, 0, (int)imagecolorallocate($image, 200, 30, 40));
		ob_start();
		imagepng($image);

		return (string)ob_get_clean();
	}

	/** What the avatar's way to the profile record looks like from here, for a failure message. */
	private function whyNoAvatar(Identity $identity): string {
		$actor = Server::get(CacheActorService::class)->getFromId($this->alice->actor->getId(), false);
		$avatar = Server::get(IAvatarManager::class)->getAvatar($this->alice->userId);
		try {
			$blob = Server::get(PictureService::class)->avatarBlob($identity, $actor, PictureService::MAX_BYTES);
			$blob = $blob === null ? 'null' : $blob['blob']->cid->toString();
		} catch (\Throwable $e) {
			$blob = get_class($e) . ': ' . $e->getMessage();
		}

		return json_encode([
			'local' => $actor->isLocal(),
			'userId' => $actor->getUserId(),
			'mayPublish' => Server::get(AccountService::class)->mayPublish($actor, IAccountManager::PROPERTY_AVATAR),
			'custom' => $avatar->isCustomAvatar(),
			'blob' => $blob,
		]) ?: '';
	}
}
