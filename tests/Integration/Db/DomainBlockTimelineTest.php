<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\DomainBlocksRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A viewer's own domain block, against the real database.
 *
 * The filter used to be a LEFT JOIN against the block table with four `LIKE`s
 * in its ON clause, evaluated for every candidate row of every timeline read —
 * an account that had blocked nothing paid for it too. It is now a handful of
 * constants read once and compared in the WHERE, which is a different query
 * with the same job, so what it hides is worth proving where the SQL runs:
 * the instance, what is under it, the boost that would otherwise carry it in,
 * and nothing that merely ends in the same letters.
 */
class DomainBlockTimelineTest extends TestCase {
	private const BASE = 'https://cloud.example.org/domainblock';
	private const VIEWER = self::BASE . '/users/viewer';
	private const BLOCKER = 'dbt-blocked.test';

	private const AUTHORS = [
		'blocked' => 'https://dbt-blocked.test/users/author',
		'sub' => 'https://sub.dbt-blocked.test/users/author',
		'lookalike' => 'https://notdbt-blocked.test/users/author',
		'fine' => 'https://dbt-fine.test/users/author',
	];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;
	private DomainBlocksRequest $domainBlocksRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->domainBlocksRequest = Server::get(DomainBlocksRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::BLOCKER, 'sub.dbt-blocked.test'] as $domain) {
			$this->domainBlocksRequest->delete(self::VIEWER, $domain);
		}
		CoreRequestBuilder::forgetBlockedDomains();

		foreach (array_merge(array_keys(self::AUTHORS), ['boost']) as $key) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $key, Note::TYPE);
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $key);
		}
		foreach (array_merge([self::VIEWER], array_values(self::AUTHORS)) as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
	}

	private function cachedPerson(string $id, string $username, bool $local): Person {
		$host = parse_url($id, PHP_URL_HOST);
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . '@' . $host)
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function note(string $key): Note {
		$author = self::AUTHORS[$key];
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $key);
		$note->setAttributedTo($author);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$author . '/followers']);
		$note->setContent('<p>' . $key . '</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	/** @return string[] the ids of ours that the timeline returns */
	private function timeline(Person $viewer, string $probe): array {
		$this->streamRequest->setViewer($viewer);

		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)->setProbe($probe)->setLimit(50);

		return array_values(array_filter(
			array_map(
				static fn ($stream): string => $stream->getId(),
				$this->streamRequest->getTimeline($options)
			),
			static fn (string $id): bool => str_starts_with($id, self::BASE . '/notes/')
		));
	}

	private function seed(): Person {
		$viewer = $this->cachedPerson(self::VIEWER, 'dbtviewer', true);
		foreach (self::AUTHORS as $key => $id) {
			$this->cachedPerson($id, 'dbt' . $key, false);
			$this->note($key);
		}

		return $viewer;
	}

	private function block(string $domain): void {
		$this->domainBlocksRequest->save(self::VIEWER, $domain);
		// the read filter carries the list as constants, and the memo behind
		// it is what the service clears when it writes one
		CoreRequestBuilder::forgetBlockedDomains();
	}

	public function testEverybodyIsOnThePublicTimelineUntilAnInstanceIsBlocked(): void {
		$viewer = $this->seed();

		$this->assertCount(4, $this->timeline($viewer, ProbeOptions::PUBLIC));
	}

	public function testABlockedInstanceLeavesTheTimeline(): void {
		$viewer = $this->seed();

		$this->block(self::BLOCKER);

		$timeline = $this->timeline($viewer, ProbeOptions::PUBLIC);
		sort($timeline);

		$this->assertSame(
			[self::BASE . '/notes/fine', self::BASE . '/notes/lookalike', self::BASE . '/notes/sub'],
			$timeline,
			'a blocked instance, or a name that merely looks like it, was on the timeline'
		);
	}

	/**
	 * A block on the read path matches the **exact host**: the patterns come
	 * from `domainPatterns()`, which is closed by the `/` that ends the host
	 * (see `DomainPatternsTest`), so a subdomain is a separate entry. This
	 * pins that, because the silenced-instance filter next door reads
	 * subdomains and the two are easy to confuse.
	 */
	public function testASubdomainIsNotCoveredByItsParentsBlock(): void {
		$viewer = $this->seed();

		$this->block(self::BLOCKER);

		$this->assertContains(
			self::BASE . '/notes/sub',
			$this->timeline($viewer, ProbeOptions::PUBLIC),
			'a block on the parent domain reached a subdomain, which it has never done'
		);
	}

	/** Blocking the subdomain itself is what covers it. */
	public function testBlockingTheSubdomainCoversIt(): void {
		$viewer = $this->seed();

		$this->block('sub.dbt-blocked.test');

		$this->assertNotContains(
			self::BASE . '/notes/sub',
			$this->timeline($viewer, ProbeOptions::PUBLIC),
			'the subdomain was blocked by name and still came through'
		);
	}

	/**
	 * A boost's own author is whoever boosted it, so without the second half
	 * of the filter a blocked instance reaches the viewer through anybody who
	 * boosts it.
	 */
	public function testABoostDoesNotCarryABlockedInstanceIn(): void {
		$viewer = $this->seed();
		$this->block(self::BLOCKER);

		$boost = new Note();
		$boost->setId(self::BASE . '/notes/boost');
		$boost->setType('Announce');
		$boost->setAttributedTo(self::AUTHORS['fine']);
		$boost->setObjectId(self::BASE . '/notes/blocked');
		$boost->setTo(ACore::CONTEXT_PUBLIC);
		$boost->setPublishedTime(time());
		$boost->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($boost);

		$this->assertNotContains(
			self::BASE . '/notes/boost',
			$this->timeline($viewer, ProbeOptions::PUBLIC),
			'a boost of a post from a blocked instance was on the timeline'
		);
	}

	/** Unblocking brings it back: nothing was deleted. */
	public function testUnblockingBringsTheInstanceBack(): void {
		$viewer = $this->seed();
		$this->block(self::BLOCKER);
		$this->assertCount(3, $this->timeline($viewer, ProbeOptions::PUBLIC));

		$this->domainBlocksRequest->delete(self::VIEWER, self::BLOCKER);
		CoreRequestBuilder::forgetBlockedDomains();

		$this->assertCount(4, $this->timeline($viewer, ProbeOptions::PUBLIC));
	}

	/** What the viewer blocked is theirs; another account reads everything. */
	public function testABlockIsThisViewersOwn(): void {
		$this->seed();
		$this->block(self::BLOCKER);

		$other = $this->cachedPerson(self::BASE . '/users/other', 'dbtother', true);
		$this->assertCount(4, $this->timeline($other, ProbeOptions::PUBLIC));
		$this->cacheActorsRequest->deleteCacheById($other->getId());
	}

	/** A blocked instance is out of the home timeline as well as the public one. */
	public function testABlockedInstanceIsOutOfTheHomeTimelineToo(): void {
		$viewer = $this->seed();

		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/1');
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId(self::AUTHORS['blocked']);
		$follow->setFollowId(self::AUTHORS['blocked'] . '/followers');
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);

		$this->assertContains(self::BASE . '/notes/blocked', $this->timeline($viewer, ProbeOptions::HOME));

		$this->block(self::BLOCKER);

		$this->assertNotContains(
			self::BASE . '/notes/blocked',
			$this->timeline($viewer, ProbeOptions::HOME),
			'a blocked instance was still in the home timeline of somebody who follows it'
		);
	}
}
