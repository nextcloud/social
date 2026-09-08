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
use OCA\Social\Model\Relationship;
use OCA\Social\Model\Report;
use OCA\Social\Tests\Model\TActivityPubMocks;
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
	}

	protected function tearDown(): void {
		AP::$activityPub = null;
		\OC::$server->reset();
	}

	public function testTheStatusEntityKeysAreStable(): void {
		$note = new Note();
		$note->setId('https://cloud.example.org/apps/social/@alice/1');
		$note->setNid(7);
		$note->setPublishedTime(1714564800);

		$status = $note->exportAsLocal();

		// 'account' joins the set when an actor is attached; 'nid' is app-specific
		$expected = [
			'bookmarked', 'content', 'created_at', 'emojis', 'favourited', 'favourites_count', 'id',
			'in_reply_to_account_id', 'in_reply_to_id', 'language', 'local',
			'media_attachments', 'mentions', 'muted', 'nid', 'noindex', 'reblog',
			'reblogged', 'reblogs_count', 'replies_count', 'sensitive', 'spoiler_text',
			'uri', 'url', 'visibility',
		];
		sort($expected);
		$actual = array_keys($status);
		sort($actual);

		$this->assertSame($expected, $actual);
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
			'group', 'header', 'header_static', 'id', 'last_status_at', 'locked',
			'nid', 'note', 'source', 'statuses_count', 'url', 'username',
		];
		$actual = array_keys($account);
		sort($expected);
		sort($actual);

		$this->assertSame($expected, $actual);
	}

	public function testTheRelationshipEntityKeysAreStable(): void {
		$relationship = new Relationship();

		$expected = [
			'blocked_by', 'blocking', 'domain_blocking', 'endorsed', 'followed_by',
			'following', 'id', 'muting', 'muting_notifications',
			'notifying', 'requested', 'showing_reblogs',
		];
		$actual = array_keys($relationship->jsonSerialize());
		sort($expected);
		sort($actual);

		$this->assertSame($expected, $actual);
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
