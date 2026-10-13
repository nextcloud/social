<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AvatarService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A new profile picture, set the way the profile editor sets one, reaches the
 * followers as an Update{Person} whose icon carries an address nobody has
 * seen — also when another process moved the picture's version since this
 * one first read the account's settings. Nextcloud draws the initials again
 * after a name change, which is such a move; a picture numbered from the
 * stale copy got the address the followers already had, and was never
 * fetched again.
 */
class AvatarChangeFederationTest extends TestCase {
	private const REMOTE = 'https://remote.example/avatarfed';

	private string $userId = '';
	private string $remoteId = '';

	protected function tearDown(): void {
		if ($this->userId !== '') {
			Server::get(IUserManager::class)->get($this->userId)?->delete();
			Server::get(ActorsRequest::class)->delete($this->userId);
		}
		if ($this->remoteId !== '') {
			Server::get(CacheActorsRequest::class)->deleteCacheById($this->remoteId);
		}
		parent::tearDown();
	}

	private function follower(string $localActorId): void {
		$this->remoteId = self::REMOTE . '/users/fan' . bin2hex(random_bytes(3));
		$fan = new Person();
		$fan->setId($this->remoteId)->setPreferredUsername('fan');
		$fan->setAccount('fan@remote.example')->setInbox($this->remoteId . '/inbox')
			->setFollowers($this->remoteId . '/followers')->setLocal(false);
		Server::get(CacheActorsRequest::class)->save($fan);

		$follow = new Follow();
		$follow->setId($this->remoteId . '/follows/' . md5($localActorId));
		$follow->setActorId($this->remoteId);
		$follow->setObjectId($localActorId);
		$follow->setAccepted(true);
		Server::get(FollowsRequest::class)->save($follow);
	}

	/** The picture's version as the database holds it, which every process shares. */
	private function storedVersion(): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('configvalue')->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($this->userId)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('avatar')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('version')));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<array> the Update{Person} activities queued for the actor, oldest first */
	private function queuedUpdates(string $actorId): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('activity')->from('social_req_queue')
			->where($qb->expr()->eq('author_prim', $qb->createNamedParameter(md5($actorId))))
			->orderBy('id', 'ASC');
		$cursor = $qb->executeQuery();
		$updates = [];
		while (($row = $cursor->fetch()) !== false) {
			$activity = json_decode((string)$row['activity'], true);
			if (($activity['type'] ?? '') === 'Update' && ($activity['object']['type'] ?? '') === 'Person') {
				$updates[] = $activity;
			}
		}
		$cursor->closeCursor();

		return $updates;
	}

	/** The `v` of an icon address, or -1 where it has none. */
	private static function versionOf(string $iconUrl): int {
		parse_str((string)parse_url($iconUrl, PHP_URL_QUERY), $query);

		return isset($query['v']) ? (int)$query['v'] : -1;
	}

	public function testANewPictureGetsAnAddressTheFollowersHaveNotSeen(): void {
		$this->userId = 'pictured' . bin2hex(random_bytes(4));
		Server::get(IUserManager::class)->createUser($this->userId, bin2hex(random_bytes(16)) . 'Aa1!');
		$accounts = Server::get(AccountService::class);
		$actor = $accounts->getActorFromUserId($this->userId, true);
		$this->follower($actor->getId());
		$before = count($this->queuedUpdates($actor->getId()));

		// a name change from another process: Nextcloud draws the initials
		// again, which moves the picture's version where this process cannot see it
		$command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg(__DIR__ . '/../Helper/rename-elsewhere.php')
			. ' ' . escapeshellarg($this->userId) . ' ' . escapeshellarg('Renamed ' . bin2hex(random_bytes(2))) . ' 2>&1';
		exec($command, $output, $exit);
		$this->assertSame(0, $exit, 'the rename in another process: ' . implode("\n", $output));
		$moved = $this->storedVersion();
		$this->assertGreaterThan(0, $moved, 'the other process moved the version');

		$picture = (string)tempnam(sys_get_temp_dir(), 'avatar-fed-');
		$image = imagecreatetruecolor(64, 64);
		imagefill($image, 0, 0, (int)imagecolorallocate($image, 200, 30, 30));
		imagepng($image, $picture);
		try {
			$accounts->changingProfile($this->userId, function () use ($picture): void {
				Server::get(AvatarService::class)->setFromFile($this->userId, $picture);
			});
		} finally {
			@unlink($picture);
		}

		$updates = $this->queuedUpdates($actor->getId());
		$this->assertGreaterThan($before, count($updates), 'an Update{Person} for the new picture');
		$icon = (string)(end($updates)['object']['icon']['url'] ?? '');
		$this->assertGreaterThan($moved, self::versionOf($icon), 'the new picture has a new address: ' . $icon);
		$this->assertSame($this->storedVersion(), self::versionOf($icon), 'the address the Update names is the one served');
		$served = Server::get(CacheActorsRequest::class)->getFromLocalAccount($actor->getPreferredUsername());
		$this->assertSame($served->getIcon()?->getUrl() ?? $served->getIconId(), $icon, 'the Update carries the icon as it is served');
	}
}
