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
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\ConfigService;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A post that names the reader reaches their home timeline, whichever query
 * answers it.
 *
 * Mentions and replies do not arrive through a follow: the author may be a
 * stranger, and what puts the post on the reader's home is the recipient row
 * addressed to the reader themselves, matched through the **Loopback** row —
 * the self-follow every local actor is given. The fast page query builds its
 * set of recipient rows from a query of its own rather than from the join, and
 * when that query was narrowed to `type = 'Follow'` the Loopback fell out of
 * it: every mention from an account the reader does not follow disappeared
 * from home, while the notification still arrived and the post was still
 * readable at its own URL. Nothing in the unit suite could see it — the set is
 * decided in SQL — and the timeline test above it only seeds followed authors.
 *
 * So both paths are asserted here, against the same seeded rows: the fast one
 * (recipient rows carry their sort key) and the old join, which is what an
 * instance mid-backfill still runs.
 */
class HomeMentionTest extends TestCase {
	private const BASE = 'https://cloud.example.org/mention';
	private const VIEWER = self::BASE . '/users/viewer';
	private const STRANGER = 'https://remote.example/mention/users/stranger';

	private const SUFFIXES = ['mention', 'stranger-public'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;
	private ConfigService $configService;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->configService = Server::get(ConfigService::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		foreach ([self::VIEWER, self::STRANGER] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
	}

	private function cachedPerson(string $id, string $username, bool $local): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername($username);
		$person->setAccount($username . ($local ? '@cloud.example.org' : '@remote.example'))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	/** @param string[] $cc */
	private function note(string $suffix, string $attributedTo, string $to, array $cc = []): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo($attributedTo);
		$note->setTo($to);
		$note->setCcArray($cc);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	/**
	 * @return string[] the ids on the viewer's home timeline, read by the path
	 *                  the flag selects
	 */
	private function home(Person $viewer, bool $fastPath): array {
		$this->configService->setAppValue(
			ConfigService::SOCIAL_DEST_NID_FILLED, $fastPath ? '1' : '0'
		);
		// the request remembers the flag for the life of the instance, and the
		// container hands out one instance per request — so the memo is what
		// has to be cleared to ask the other path the same question
		(new ReflectionProperty(StreamRequest::class, 'recipientNidsFilled'))
			->setValue($this->streamRequest, null);

		$this->streamRequest->setViewer($viewer);
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::HOME)->setLimit(40);

		return array_map(
			static fn ($stream): string => $stream->getId(),
			$this->streamRequest->getTimeline($options)
		);
	}

	public function testAMentionFromAStrangerIsOnTheReadersHomeTimeline(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'mention-viewer', true);
		$this->cachedPerson(self::STRANGER, 'mention-stranger', false);
		// what AccountService gives every local actor when it is created
		$this->followsRequest->generateLoopbackAccount($viewer);

		// a public post that names the viewer, which is what a mention or a
		// reply from an account they do not follow looks like on the wire
		$mention = $this->note(
			'mention', self::STRANGER, ACore::CONTEXT_PUBLIC, [self::VIEWER]
		);
		// the same author's ordinary post, which names nobody in particular
		$unrelated = $this->note(
			'stranger-public', self::STRANGER, ACore::CONTEXT_PUBLIC,
			[self::STRANGER . '/followers']
		);

		foreach ([true, false] as $fastPath) {
			$home = $this->home($viewer, $fastPath);
			$this->assertContains(
				$mention->getId(), $home,
				'a post addressed to the reader is on their home timeline'
				. ($fastPath ? ' (fast path)' : ' (join path)')
			);
			$this->assertNotContains(
				$unrelated->getId(), $home,
				'a post by the same stranger that does not name them is not'
				. ($fastPath ? ' (fast path)' : ' (join path)')
			);
		}
	}
}
