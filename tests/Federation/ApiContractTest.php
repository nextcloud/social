<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Relationship;
use OCA\Social\Model\Report;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * The Mastodon-API contract: the exact key sets of the entities third-party
 * clients parse. Removing or renaming a key here breaks Tusky/Elk/etc. silently —
 * a client shows blanks, not errors — so any change to these lists must be a
 * conscious API decision, made by editing the expectation in the same commit.
 * Adding keys is compatible and extends the list; removing one should give pause.
 */
class ApiContractTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args = []): string
				=> 'https://cloud.example.org/apps/social/' . ($args['path'] ?? '')
		);
		\OC::$server->register(IURLGenerator::class, $urlGenerator);
	}

	protected function tearDown(): void {
		AP::set(null);
		Stream::resetReplyParentCache();
		\OC::$server->reset();
	}

	/**
	 * The keys as they actually reach a client.
	 *
	 * `exportAsLocal()` is not the wire payload: what a DataResponse encodes is
	 * `jsonSerialize()`, and the subclasses add keys on top of it — Note used
	 * to bolt on its own `hashtags`, and Stream an `attachment` beside
	 * `media_attachments`. Asserting against the export alone pinned a contract
	 * nothing served.
	 */
	private function serialisedKeys(\JsonSerializable $entity): array {
		$keys = array_keys((array)json_decode((string)json_encode($entity), true));
		sort($keys);

		return $keys;
	}

	/** @return string[] */
	private function expectedStatusKeys(): array {
		// 'account' joins the set when an actor is attached; 'nid' is app-specific.
		// 'place' is Pixelfed's, and is null for almost every post: a place is
		// never inferred, only stated. It is in the client format and not in the
		// ActivityPub one, because places are local and are not federated.
		$expected = [
			'bookmarked', 'card', 'content', 'created_at', 'edited_at', 'emojis', 'favourited',
			'favourites_count', 'id', 'in_reply_to_account_id', 'in_reply_to_id', 'language',
			'local', 'media_attachments', 'mentions', 'muted', 'nid', 'noindex', 'pinned', 'place', 'poll', 'quote',
			'reblog', 'reblogged', 'reblogs_count', 'replies_count', 'sensitive', 'spoiler_text',
			'tags', 'uri', 'url', 'visibility',
		];
		sort($expected);

		return $expected;
	}

	private function aStatus(): Note {
		$note = new Note();
		$note->setId('https://cloud.example.org/apps/social/@alice/1');
		$note->setNid(7);
		$note->setPublishedTime(1714564800);
		$note->setExportFormat(ACore::FORMAT_LOCAL);

		return $note;
	}

	public function testTheStatusEntityKeysAreStable(): void {
		$actual = array_keys($this->aStatus()->exportAsLocal());
		sort($actual);

		$this->assertSame($this->expectedStatusKeys(), $actual);
	}

	/**
	 * The same set, taken from the JSON a client actually receives rather than
	 * from `exportAsLocal()`.
	 */
	public function testTheSerialisedStatusCarriesExactlyTheSameKeys(): void {
		$this->assertSame(
			$this->expectedStatusKeys(),
			$this->serialisedKeys($this->aStatus()),
			'jsonSerialize() and exportAsLocal() must not drift'
		);
	}

	/**
	 * Nothing may be dropped from the client format, and only the extras named
	 * here may be added to it.
	 *
	 * `attachment` was a second copy of `media_attachments` under the
	 * ActivityPub name, and is gone. `hashtags` is the app's own name for what
	 * Mastodon calls `tags` (now exported alongside it); it is still added by
	 * `Note::jsonSerialize()` and should come off that list, at which point it
	 * comes off this one.
	 */
	public function testTheClientFormatAddsNothingButTheKnownExtras(): void {
		$note = $this->aStatus();
		$note->setHashtags(['cats']);
		$note->setCompleteDetails(true);

		$keys = $this->serialisedKeys($note);

		$this->assertSame([], array_values(array_diff($this->expectedStatusKeys(), $keys)));
		// nothing beyond the Mastodon contract: `hashtags` was this app's own
		// bare-string shape and `tags` now carries the same data as Mastodon
		// spells it, so the client format carries no extras at all
		$this->assertSame(
			[],
			array_values(array_diff($keys, $this->expectedStatusKeys()))
		);
		$this->assertNotContains('attachment', $keys);
		$this->assertNotContains('hashtags', $keys);
	}

	/**
	 * The keys a status entity carries even when there is nothing to put in
	 * them. `Note::jsonSerialize()` used to run the whole payload through
	 * `cleanArray()`, which drops every empty string and empty list, so a post
	 * with no content warning arrived without `spoiler_text` and one with no
	 * attachments without `media_attachments`.
	 */
	public function testAnEmptyStatusStillCarriesEveryKey(): void {
		$serialised = (array)json_decode((string)json_encode($this->aStatus()), true);

		$this->assertArrayHasKey('spoiler_text', $serialised);
		$this->assertSame('', $serialised['spoiler_text']);
		$this->assertArrayHasKey('media_attachments', $serialised);
		$this->assertSame([], $serialised['media_attachments']);
		$this->assertArrayHasKey('mentions', $serialised);
		$this->assertArrayHasKey('tags', $serialised);
		$this->assertArrayHasKey('in_reply_to_id', $serialised);
		$this->assertArrayHasKey('content', $serialised);
	}

	public function testHashtagsAreExportedAsMastodonTags(): void {
		$note = $this->aStatus();
		$note->setHashtags(['#Cats', 'dogs', '']);

		$this->assertSame(
			[
				['name' => 'Cats', 'url' => 'https://cloud.example.org/apps/social/tags/Cats'],
				['name' => 'dogs', 'url' => 'https://cloud.example.org/apps/social/tags/dogs'],
			],
			$note->exportAsLocal()['tags']
		);
	}

	public function testAReplyToAnUnknownParentReportsNullRatherThanBreaking(): void {
		$note = $this->aStatus();
		$note->setInReplyTo('https://remote.example/statuses/999');

		$status = $note->exportAsLocal();

		$this->assertNull($status['in_reply_to_id']);
		$this->assertNull($status['in_reply_to_account_id']);
	}

	public function testMentionIdsAreStringsEvenWhenUnresolvable(): void {
		$note = $this->aStatus();
		// fillMentions() stores an integer 0 for a handle it could not resolve
		$note->setMentions([
			['id' => 0, 'username' => 'ghost', 'url' => 'https://remote.example/@ghost', 'acct' => 'ghost'],
			['id' => '4', 'username' => 'bob', 'url' => 'https://remote.example/@bob', 'acct' => 'bob'],
		]);

		$mentions = $note->exportAsLocal()['mentions'];

		$this->assertSame('0', $mentions[0]['id']);
		$this->assertSame('4', $mentions[1]['id']);
	}

	public function testTheAccountEntityKeysAreStable(): void {
		$person = new Person();
		$person->setId('https://cloud.example.org/apps/social/@alice');
		$person->setPreferredUsername('alice');
		$person->setUrlSocial('https://cloud.example.org/apps/social/');

		$account = $person->exportAsLocal();

		$expected = [
			'acct', 'avatar', 'avatar_static', 'bot', 'created_at', 'discoverable',
			'display_name', 'emojis', 'fields', 'followers_count', 'following_count',
			'group', 'header', 'header_static', 'id', 'indexable', 'last_status_at', 'locked',
			'nid', 'note', 'statuses_count', 'url', 'username',
		];
		$actual = array_keys($account);
		sort($expected);
		sort($actual);

		$this->assertSame($expected, $actual);

		// `moved` is the one optional key of the entity: present only once the
		// account has moved, and then a full account entity of its own
		$person->setMovedTo('https://new.example/users/alice');
		$moved = array_keys($person->exportAsLocal()['moved']);
		sort($moved);
		$this->assertSame($expected, $moved, 'moved carries the same keys as any account entity');
	}

	public function testTheRelationshipEntityKeysAreStable(): void {
		$relationship = new Relationship();

		$expected = [
			'blocked_by', 'blocking', 'domain_blocking', 'endorsed', 'followed_by',
			'following', 'id', 'languages', 'muting', 'muting_notifications',
			'note', 'notifying', 'requested', 'requested_by', 'showing_reblogs',
		];
		$actual = array_keys($relationship->jsonSerialize());
		sort($expected);
		sort($actual);

		$this->assertSame($expected, $actual);
		$this->assertIsString(
			$relationship->jsonSerialize()['id'], 'relationship ids are strings on the wire'
		);
	}

	public function testTheReportEntityKeysAreStable(): void {
		$report = new Report();

		$expected = [
			'action_taken', 'action_taken_at', 'category', 'comment', 'created_at',
			'forwarded', 'id', 'rule_ids', 'status_ids', 'target_account',
		];
		$actual = array_keys($report->jsonSerialize());
		sort($expected);
		sort($actual);

		$this->assertSame($expected, $actual);
		$this->assertIsString($report->jsonSerialize()['id'], 'report ids are strings on the wire');
	}

	public function testTheNotificationEntityKeysAreStable(): void {
		$note = new Note();
		$note->setNid(9);
		$note->setPublishedTime(1714564800);
		$note->setExportFormat(ACore::FORMAT_NOTIFICATION);

		$expected = ['created_at', 'id', 'status', 'type'];
		$actual = array_keys($note->exportAsNotification());
		sort($expected);
		sort($actual);

		$this->assertSame($expected, $actual);
	}

	public function testStatusIdsAreStringsOfTheNumericId(): void {
		// Mastodon ids are strings on the wire; clients (and our own frontend's
		// pagination) parse them as integers
		$note = new Note();
		$note->setNid(42);
		$note->setPublishedTime(1714564800);

		$this->assertSame('42', $note->exportAsLocal()['id']);
		$this->assertSame('42', $note->exportAsNotification()['id']);
	}
}
