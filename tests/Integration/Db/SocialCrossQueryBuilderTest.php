<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The joins every timeline is assembled from, run on their own against the
 * real database.
 *
 * `leftJoinObjectStatus()` is the one that carries a privacy rule: a boost
 * carries the audience of the booster, so the post it repeats is joined only
 * where the viewer may read that post. The rest attach the viewer's own
 * flags, the author's cached actor with its avatar, and a post's hashtags.
 */
class SocialCrossQueryBuilderTest extends TestCase {
	private const BASE = 'https://cloud.example.org/sxqb';
	private const VIEWER = self::BASE . '/users/viewer';
	private const OTHER = self::BASE . '/users/other';
	private const AUTHOR = 'https://remote.example/sxqb/users/author';
	private const BOOSTER = 'https://remote.example/sxqb/users/booster';

	/** the originals, and who each is addressed to */
	private const ORIGINALS = ['public', 'followers', 'to-viewer', 'to-other'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;
	private IDBConnection $connection;
	/** @var array<string, string> original => prim of the boost of it */
	private array $boosts = [];

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();

		$to = [
			'public' => [ACore::CONTEXT_PUBLIC],
			'followers' => [self::AUTHOR . '/followers'],
			'to-viewer' => [self::VIEWER],
			'to-other' => [self::OTHER],
		];
		foreach ($to as $suffix => $recipients) {
			$note = new Note();
			$note->setId($this->id($suffix));
			$note->setAttributedTo(self::AUTHOR);
			$note->setToArray($recipients);
			$note->setContent('<p>' . $suffix . '</p>');
			$note->setHashtags($suffix === 'public' ? ['zebra'] : []);
			$note->setPublishedTime(time());
			$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
			$this->streamRequest->save($note);

			// the booster's Announce, which everybody may read
			$boost = self::BOOSTER . '/boosts/' . $suffix;
			$this->boosts[$suffix] = md5($boost);
			RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, [
				'id' => $boost, 'id_prim' => md5($boost), 'type' => Announce::TYPE,
				'attributed_to' => self::BOOSTER, 'attributed_to_prim' => md5(self::BOOSTER),
				'object_id' => $this->id($suffix), 'object_id_prim' => md5($this->id($suffix)),
			]);
		}
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::ORIGINALS as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix));
			$this->streamRequest->deleteByPrims([md5(self::BOOSTER . '/boosts/' . $suffix)]);
		}
		foreach ([self::VIEWER, self::OTHER, self::AUTHOR, self::BOOSTER] as $id) {
			$this->followsRequest->deleteRelatedId($id);
			$this->cacheActorsRequest->deleteCacheById($id);
		}
		RowSeeder::delete(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, ['id_prim' => md5(self::AUTHOR . '/avatar')]);
		RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM_ACTIONS, ['actor_id_prim' => md5(self::VIEWER)]);
		RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM_ACTIONS, ['actor_id_prim' => md5(self::OTHER)]);
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername(basename($id));
		$person->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST))
			->setFollowers($id . '/followers')
			->setInbox($id . '/inbox');

		return $person;
	}

	private function follow(string $follower, bool $accepted): void {
		$follow = new Follow();
		$follow->setId($follower . '/follows/author');
		$follow->setActorId($follower);
		$follow->setObjectId(self::AUTHOR);
		$follow->setFollowId(self::AUTHOR . '/followers');
		$follow->setAccepted($accepted);
		$this->followsRequest->save($follow);
	}

	private function builder(?string $viewer): SocialQueryBuilder {
		$qb = new SocialQueryBuilder($this->connection->getQueryBuilder(), Server::get(IURLGenerator::class));
		if ($viewer !== null) {
			$qb->setViewer($this->person($viewer));
		}

		return $qb;
	}

	/**
	 * Which of the boosted originals the viewer is handed through the boost.
	 *
	 * @return string[]
	 */
	private function visibleThroughBoosts(?string $viewer): array {
		$qb = $this->builder($viewer);
		$qb->select('s.object_id')
			->from(CoreRequestBuilder::TABLE_STREAM, 's')
			->where($qb->expr()->in('s.id_prim', $qb->createNamedParameter(array_values($this->boosts), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->setDefaultSelectAlias('s');
		$qb->leftJoinObjectStatus();

		$visible = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$this->assertArrayHasKey('os_id', $row);
			if ((string)($row['os_id'] ?? '') !== '') {
				$this->assertSame((string)$row['object_id'], (string)$row['os_id']);
				$visible[] = basename((string)$row['os_id']);
			}
		}
		$result->closeCursor();
		sort($visible);

		return $visible;
	}

	public function testABoostHandsAStrangerOnlyThePublicPostItRepeats(): void {
		$this->assertSame(['public'], $this->visibleThroughBoosts(null), 'nobody signed in');
		$this->assertSame(['public'], $this->visibleThroughBoosts(self::BOOSTER), 'the booster follows nobody');
	}

	public function testABoostHandsTheViewerWhatIsAddressedToThemAndNothingAddressedToSomebodyElse(): void {
		$this->assertSame(['public', 'to-viewer'], $this->visibleThroughBoosts(self::VIEWER));
		$this->assertSame(['public', 'to-other'], $this->visibleThroughBoosts(self::OTHER));
	}

	public function testAFollowersOnlyPostIsHandedOnlyToAnAcceptedFollower(): void {
		$this->follow(self::OTHER, false);
		$this->assertSame(['public', 'to-other'], $this->visibleThroughBoosts(self::OTHER), 'a pending follow');

		$this->follow(self::VIEWER, true);
		$this->assertSame(['followers', 'public', 'to-viewer'], $this->visibleThroughBoosts(self::VIEWER));
	}

	public function testTheAuthorIsHandedAllTheirOwnPosts(): void {
		$this->assertSame(['followers', 'public', 'to-other', 'to-viewer'], $this->visibleThroughBoosts(self::AUTHOR));
	}

	/** The viewer's own flags, on a post and on the post a boost repeats, and nobody else's. */
	public function testTheViewersOwnFlagsAreJoinedOnThePostAndOnTheBoostOfIt(): void {
		$streamActions = Server::get(StreamActionService::class);
		$streamActions->setActionBool(self::VIEWER, $this->id('public'), StreamAction::LIKED, true);
		$streamActions->setActionBool(self::OTHER, $this->id('public'), StreamAction::BOOKMARKED, true);

		foreach ([md5($this->id('public')), $this->boosts['public']] as $prim) {
			$qb = $this->builder(self::VIEWER);
			$qb->select('s.id')->from(CoreRequestBuilder::TABLE_STREAM, 's')
				->where($qb->expr()->eq('s.id_prim', $qb->createNamedParameter($prim)));
			$qb->setDefaultSelectAlias('s');
			$qb->leftJoinStreamAction();
			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			$this->assertSame(1, (int)$row['streamaction_liked']);
			$this->assertSame(0, (int)$row['streamaction_bookmarked'], 'another account\'s bookmark');
			$this->assertSame(self::VIEWER, (string)$row['streamaction_actor_id']);
		}
	}

	/** The author's cached actor, and its icon where that is a cached picture. */
	public function testTheAuthorsCachedActorAndAvatarComeWithThePost(): void {
		$author = $this->person(self::AUTHOR);
		$author->setIconId(self::AUTHOR . '/avatar');
		$this->cacheActorsRequest->save($author);
		RowSeeder::insert(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, [
			'id' => self::AUTHOR . '/avatar', 'id_prim' => md5(self::AUTHOR . '/avatar'),
			'type' => Image::TYPE, 'local_copy' => 'full-copy', 'resized_copy' => 'thumbnail',
			'url' => 'https://remote.example/sxqb/avatar.png', 'media_type' => 'image/png', 'mime_type' => 'image/png',
			'error' => 0, 'caching' => null,
		]);

		$qb = $this->builder(null);
		$qb->select('s.id')->from(CoreRequestBuilder::TABLE_STREAM, 's')
			->where($qb->expr()->eq('s.id_prim', $qb->createNamedParameter(md5($this->id('public')))));
		$qb->setDefaultSelectAlias('s');
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		$actor = $qb->parseLeftJoinCacheActors($row, 'ca_');
		$this->assertSame(self::AUTHOR, $actor->getId());
		$this->assertTrue($actor->hasIcon());
		$this->assertSame(self::AUTHOR . '/avatar', $actor->getIcon()->getId());
		$this->assertSame('thumbnail', $actor->getIcon()->getResizedCopy());
		$this->assertSame('full-copy', $actor->getIcon()->getLocalCopy());
	}

	public function testAPostIsFoundThroughItsHashtagRows(): void {
		$qb = $this->builder(null);
		$qb->select('s.id')->from(CoreRequestBuilder::TABLE_STREAM, 's');
		$qb->setDefaultSelectAlias('s');
		$qb->linkToStreamTags('st', 's.id_prim');
		$qb->andWhere($qb->expr()->eq('st.hashtag', $qb->createNamedParameter('zebra')));
		$qb->andWhere($qb->expr()->eq('s.attributed_to_prim', $qb->createNamedParameter(md5(self::AUTHOR))));
		$result = $qb->executeQuery();
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (string)$id;
		}
		$result->closeCursor();

		$this->assertSame([$this->id('public')], $ids);
	}
}
