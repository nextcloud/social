<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\FediverseService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A silenced instance against the real database.
 *
 * Silencing is the tier between a block and nothing: the instance stays
 * reachable and its accounts stay readable by whoever follows them, and it
 * leaves the timelines a stranger sees. Both halves of that only mean anything
 * where the SQL runs, and the clause is a `LIKE` on the author's id rather
 * than a host column, so what it matches is worth asserting: the domain, what
 * is under it, and nothing that merely ends in the same letters.
 */
class SilencedInstanceTimelineTest extends TestCase {
	private const BASE = 'https://cloud.example.org/silenced';
	private const VIEWER = self::BASE . '/users/viewer';

	private const AUTHORS = [
		'noisy' => 'https://noisy.test/users/author',
		'sub' => 'https://sub.noisy.test/users/author',
		'lookalike' => 'https://notnoisy.test/users/author',
		'quiet' => 'https://quiet.test/users/author',
	];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;
	private FediverseService $fediverseService;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->fediverseService = Server::get(FediverseService::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->fediverseService->unsilenceAddress('noisy.test');
		foreach (array_keys(self::AUTHORS) as $key) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $key, Note::TYPE);
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

	/** A public post by one of the authors above. */
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

	/** @return string[] the ids of ours that the given timeline returns */
	private function timeline(Person $viewer, string $probe): array {
		$this->streamRequest->setViewer($viewer);

		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe($probe)
			->setLimit(50);

		return array_values(array_filter(
			array_map(
				static fn ($stream): string => $stream->getId(),
				$this->streamRequest->getTimeline($options)
			),
			static fn (string $id): bool => str_starts_with($id, self::BASE . '/notes/')
		));
	}

	private function seed(): Person {
		$viewer = $this->cachedPerson(self::VIEWER, 'silviewer', true);
		foreach (self::AUTHORS as $key => $id) {
			$this->cachedPerson($id, 'sil' . $key, false);
			$this->note($key);
		}

		return $viewer;
	}

	private function follow(string $author): void {
		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/1');
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId($author);
		$follow->setFollowId($author . '/followers');
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);
	}

	public function testThePublicTimelineCarriesEveryoneUntilSomebodyIsSilenced(): void {
		$viewer = $this->seed();

		$this->assertCount(4, $this->timeline($viewer, ProbeOptions::PUBLIC));
	}

	public function testASilencedInstanceLeavesThePublicTimeline(): void {
		$viewer = $this->seed();

		$this->fediverseService->silenceAddress('noisy.test');

		$this->assertSame(
			[self::BASE . '/notes/quiet', self::BASE . '/notes/lookalike'],
			$this->timeline($viewer, ProbeOptions::PUBLIC),
			'the silenced instance, or a name that merely looks like it, was still on the timeline'
		);
	}

	/**
	 * Silencing only the exact hostname lasts as long as it takes to point a
	 * wildcard record at the same server, so a silence reads subdomains the
	 * way a block does.
	 */
	public function testASilenceCoversWhatIsUnderTheDomain(): void {
		$viewer = $this->seed();

		$this->fediverseService->silenceAddress('noisy.test');

		$this->assertNotContains(self::BASE . '/notes/sub', $this->timeline($viewer, ProbeOptions::PUBLIC));
	}

	/**
	 * The whole difference between a silence and a block: what the viewer
	 * asked to see, they still see.
	 */
	public function testTheFollowersOfASilencedAccountStillSeeIt(): void {
		$viewer = $this->seed();
		$this->follow(self::AUTHORS['noisy']);

		$this->fediverseService->silenceAddress('noisy.test');

		$this->assertContains(self::BASE . '/notes/noisy', $this->timeline($viewer, ProbeOptions::HOME));
	}

	/** Nothing was deleted, so lifting the silence brings the posts back. */
	public function testLiftingASilenceRestoresThePublicTimeline(): void {
		$viewer = $this->seed();

		$this->fediverseService->silenceAddress('noisy.test');
		$this->fediverseService->unsilenceAddress('noisy.test');

		$this->assertCount(4, $this->timeline($viewer, ProbeOptions::PUBLIC));
	}
}
