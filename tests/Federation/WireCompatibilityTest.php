<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Tests\Model\TActivityPubMocks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * Wire-format regression suite: real-world ActivityPub documents, verbatim as
 * Mastodon and Pleroma deliver them, driven through AP::getItemFromData(). Every
 * assertion pins a piece of on-the-wire compatibility — the type resolution, the
 * embedded-object chain, recipients, content warnings, tags and counts — so a
 * refactor of the import layer cannot silently stop understanding the Fediverse.
 */
class WireCompatibilityTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();
		\OC::$server->register(\OCP\IURLGenerator::class, $this->createMock(\OCP\IURLGenerator::class));
		// mentioned actors are not in the (mocked) cache: keep the wire values
		$this->apInterface(\OCA\Social\Interfaces\Actor\PersonInterface::class)
			->method('getItemById')
			->willThrowException(new \OCA\Social\Exceptions\ItemNotFoundException());
	}

	protected function tearDown(): void {
		AP::$activityPub = null;
		\OC::$server->reset();
	}

	private function fixture(string $name): array {
		$json = file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
		$this->assertIsString($json, $name . ' fixture exists');

		return json_decode($json, true, 128, JSON_THROW_ON_ERROR);
	}

	public function testAMastodonCreateNoteResolvesWithItsFullObjectChain(): void {
		$item = AP::$activityPub->getItemFromData($this->fixture('mastodon-create-note'));

		$this->assertInstanceOf(Create::class, $item);
		$this->assertSame('https://mastodon.social/users/alice', $item->getActorId());
		$this->assertTrue($item->hasObject());

		/** @var Note $note */
		$note = $item->getObject();
		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame('https://mastodon.social/users/alice/statuses/113000000000000001', $note->getId());
		$this->assertSame($item->getObjectId(), $note->getId(), 'the activity adopts its object id');
		$this->assertSame('https://mastodon.social/users/alice', $note->getAttributedTo());
		$this->assertSame('https://cloud.example.org/apps/social/@bob/97', $note->getInReplyTo());
		$this->assertTrue($note->isPublic(), 'Public in to');
		$this->assertTrue($note->isSensitive());
		$this->assertContains('https://cloud.example.org/apps/social/@bob', $note->getCcArray());
	}

	public function testAMastodonContentWarningReachesTheClientAsSpoilerText(): void {
		/** @var Create $item */
		$item = AP::$activityPub->getItemFromData($this->fixture('mastodon-create-note'));
		/** @var Note $note */
		$note = $item->getObject();

		$this->assertSame('CW: long post about cats', $note->getSpoilerText());
		$note->setExportFormat(ACore::FORMAT_LOCAL);
		$this->assertSame('CW: long post about cats', $note->exportAsLocal()['spoiler_text']);
	}

	public function testMastodonTagsAndCountsSurviveTheImport(): void {
		/** @var Create $item */
		$item = AP::$activityPub->getItemFromData($this->fixture('mastodon-create-note'));
		/** @var Note $note */
		$note = $item->getObject();

		$mentions = $note->getTags('Mention');
		$this->assertCount(1, $mentions);
		$this->assertSame('@bob@cloud.example.org', $mentions[0]['name']);

		$hashtags = $note->getTags('Hashtag');
		$this->assertCount(1, $hashtags);
		$this->assertSame('#cat', $hashtags[0]['name']);

		$this->assertSame(7, $note->getDetailInt('likes'));
		$this->assertSame(3, $note->getDetailInt('boosts'));
		$this->assertSame(2, $note->getDetailInt('replies'));
	}

	public function testAMastodonActorImportsTheFieldsFederationDependsOn(): void {
		/** @var Person $person */
		$person = AP::$activityPub->getItemFromData($this->fixture('mastodon-actor'));

		$this->assertInstanceOf(Person::class, $person);
		$this->assertSame('alice', $person->getPreferredUsername());
		$this->assertSame('https://mastodon.social/users/alice/inbox', $person->getInbox());
		$this->assertSame('https://mastodon.social/inbox', $person->getSharedInbox());
		$this->assertSame('https://mastodon.social/users/alice/followers', $person->getFollowers());
		$this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $person->getPublicKey());
		$this->assertSame(['https://old.example/users/alice'], $person->getAlsoKnownAs(), 'the Move guard depends on this');
	}

	public function testAMastodonAnnounceKeepsTheBoostedObjectId(): void {
		/** @var Announce $announce */
		$announce = AP::$activityPub->getItemFromData($this->fixture('mastodon-announce'));

		$this->assertInstanceOf(Announce::class, $announce);
		$this->assertSame('https://mastodon.social/users/alice', $announce->getActorId());
		$this->assertSame('https://remote.example/users/carol/statuses/42', $announce->getObjectId());
	}

	public function testAMastodonDeleteWithAnEmbeddedTombstoneNamesTheDeletedPost(): void {
		/** @var Delete $delete */
		$delete = AP::$activityPub->getItemFromData($this->fixture('mastodon-delete-tombstone'));

		$this->assertInstanceOf(Delete::class, $delete);
		// Tombstone has no interface of its own: the id fallback is what makes
		// remote deletions work at all (the #2029 regression)
		$this->assertSame(
			'https://mastodon.social/users/alice/statuses/113000000000000001',
			$delete->getObjectId()
		);
	}

	public function testAMastodonUnfollowResolvesToUndoOverFollow(): void {
		/** @var Undo $undo */
		$undo = AP::$activityPub->getItemFromData($this->fixture('mastodon-undo-follow'));

		$this->assertInstanceOf(Undo::class, $undo);
		$this->assertTrue($undo->hasObject());
		$follow = $undo->getObject();
		$this->assertInstanceOf(Follow::class, $follow);
		$this->assertSame('https://cloud.example.org/apps/social/@bob', $follow->getObjectId());
	}

	public function testAMastodonLikeCarriesActorAndLikedObject(): void {
		$like = AP::$activityPub->getItemFromData($this->fixture('mastodon-like'));

		$this->assertInstanceOf(\OCA\Social\Model\ActivityPub\Object\Like::class, $like);
		$this->assertSame('https://mastodon.social/users/alice', $like->getActorId());
		$this->assertSame('https://cloud.example.org/apps/social/@bob/97', $like->getObjectId());
	}

	public function testAMastodonProfileUpdateResolvesToUpdateOverPerson(): void {
		$update = AP::$activityPub->getItemFromData($this->fixture('mastodon-update-person'));

		$this->assertInstanceOf(\OCA\Social\Model\ActivityPub\Activity\Update::class, $update);
		$this->assertTrue($update->hasObject());
		/** @var Person $person */
		$person = $update->getObject();
		$this->assertInstanceOf(Person::class, $person);
		$this->assertSame('Alice (renamed)', $person->getName());
		$this->assertSame('<p>new bio</p>', $person->getDescription());
		$this->assertSame($update->getObjectId(), $person->getId());
	}

	public function testAMastodonNoteWithMediaImportsItsAttachment(): void {
		/** @var Note $note */
		$note = AP::$activityPub->getItemFromData($this->fixture('mastodon-note-with-media'));

		$this->assertInstanceOf(Note::class, $note);
		$attachments = $note->getAttachments();
		$this->assertCount(1, $attachments);
		$this->assertSame('image', $attachments[0]->getType());
		$this->assertSame('a cat sleeping on a laptop', $attachments[0]->getDescription(), 'the alt text survives');
		$this->assertSame('', $note->getSpoilerText(), 'a JSON null summary is no content warning');
	}

	public function testAPleromaFollowersOnlyNoteImportsAsNonPublic(): void {
		/** @var Create $item */
		$item = AP::$activityPub->getItemFromData($this->fixture('pleroma-create-note'));
		/** @var Note $note */
		$note = $item->getObject();

		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame('followers-only post from pleroma', $note->getContent());
		$this->assertFalse($note->isPublic(), 'followers-only stays non-public');
		$this->assertSame('', $note->getSpoilerText(), 'an empty Pleroma summary is no content warning');
		$this->assertSame('https://pleroma.example/users/dana', $note->getAttributedTo());
	}
}
