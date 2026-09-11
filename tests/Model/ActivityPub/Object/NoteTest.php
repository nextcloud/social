<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\AP;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../TActivityPubMocks.php';

class NoteTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();
		\OC::$server->register(IURLGenerator::class, $this->createMock(IURLGenerator::class));
	}

	protected function tearDown(): void {
		AP::$activityPub = null;
		\OC::$server->reset();
	}

	/** A Note as Mastodon delivers it inside a Create. */
	private function mastodonNote(): array {
		return [
			'id' => 'https://mastodon.social/users/alice/statuses/112000000000000001',
			'type' => 'Note',
			'summary' => 'CW: cats',
			'inReplyTo' => 'https://mastodon.social/users/bob/statuses/111999999999999999',
			'published' => '2024-05-01T12:00:00Z',
			'url' => 'https://mastodon.social/@alice/112000000000000001',
			'attributedTo' => 'https://mastodon.social/users/alice',
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => ['https://mastodon.social/users/alice/followers', 'https://remote.example/users/bob'],
			'sensitive' => true,
			'atomUri' => 'https://mastodon.social/users/alice/statuses/112000000000000001',
			'conversation' => 'tag:mastodon.social,2024-05-01:objectId=1:objectType=Conversation',
			'content' => '<p><span class="h-card"><a href="https://remote.example/@bob" class="u-url mention">@<span>bob</span></a></span> look at this <a href="https://mastodon.social/tags/nextcloud" class="mention hashtag" rel="tag">#<span>nextcloud</span></a></p>',
			'contentMap' => ['en' => '<p>...</p>'],
			'attachment' => [
				[
					'type' => 'Document',
					'mediaType' => 'image/jpeg',
					'url' => 'https://files.mastodon.social/media_attachments/files/112/000/original/cat.jpg',
					'name' => 'A cat',
					'blurhash' => 'UBL_:rOpGG-oBUNG,qRj2so|=eE1w^n4S5NH',
					'width' => 1200,
					'height' => 800,
				],
			],
			'tag' => [
				['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example'],
				['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/nextcloud', 'name' => '#nextcloud'],
			],
			'replies' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/replies', 'type' => 'Collection'],
			'likes' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/likes', 'type' => 'Collection', 'totalItems' => 5],
			'shares' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/shares', 'type' => 'Collection', 'totalItems' => 2],
		];
	}

	private function nobodyIsKnown(): void {
		$this->apInterface(PersonInterface::class)->method('getItemById')
			->willThrowException(new ItemNotFoundException());
	}

	public function testConstructorSetsTheType(): void {
		$this->assertSame('Note', (new Note())->getType());
	}

	public function testImportReadsAMastodonNote(): void {
		$this->nobodyIsKnown();
		$note = new Note();

		$note->import($this->mastodonNote());

		$this->assertSame('https://mastodon.social/users/alice/statuses/112000000000000001', $note->getId());
		$this->assertSame('https://mastodon.social/@alice/112000000000000001', $note->getUrl());
		$this->assertSame('CW: cats', $note->getSummary());
		$this->assertTrue($note->isSensitive());
		$this->assertSame('https://mastodon.social/users/bob/statuses/111999999999999999', $note->getInReplyTo());
		$this->assertSame('https://mastodon.social/users/alice', $note->getAttributedTo());
		$this->assertSame('2024-05-01T12:00:00Z', $note->getPublished());
		$this->assertSame(1714564800, $note->getPublishedTime());
		$this->assertStringContainsString('look at this', $note->getContent());
		$this->assertSame('tag:mastodon.social,2024-05-01:objectId=1:objectType=Conversation', $note->getConversation());

		$this->assertTrue($note->isPublic());
		$this->assertSame([ACore::CONTEXT_PUBLIC], $note->getToArray());
		$this->assertSame(
			['https://mastodon.social/users/alice/followers', 'https://remote.example/users/bob'],
			$note->getCcArray()
		);
		$this->assertNotContains(ACore::CONTEXT_PUBLIC, $note->getRecipients(true));

		$this->assertSame([
			['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example'],
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/nextcloud', 'name' => '#nextcloud'],
		], $note->getTags());
		$this->assertSame(['nextcloud'], $note->getHashtags());

		$this->assertSame(5, $note->getDetailInt('likes'));
		$this->assertSame(2, $note->getDetailInt('boosts'));
		$this->assertSame(0, $note->getDetailInt('replies'), 'replies collection without totalItems');

		$attachments = $note->getAttachments();
		$this->assertCount(1, $attachments);
		$this->assertInstanceOf(MediaAttachment::class, $attachments[0]);
		$this->assertSame('image', $attachments[0]->getType());
		$this->assertSame(
			'https://files.mastodon.social/media_attachments/files/112/000/original/cat.jpg',
			$attachments[0]->getRemoteUrl()
		);
	}

	public function testFillMentionsKeepsTagDataForUnknownActors(): void {
		$this->nobodyIsKnown();
		$note = new Note();
		$note->setTags([
			['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example'],
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/nextcloud', 'name' => '#nextcloud'],
		]);

		$note->fillMentions();

		$this->assertSame([
			[
				'id' => 0,
				'username' => 'bob@remote.example',
				'url' => 'https://remote.example/users/bob',
				'acct' => 'bob@remote.example',
			],
		], $note->getDetails('mentions'));
	}

	public function testFillMentionsResolvesKnownActors(): void {
		$bob = new Person();
		$bob->setNid(77)
			->setPreferredUsername('bob')
			->setAccount('bob@remote.example');
		$this->apInterface(PersonInterface::class)->method('getItemById')
			->with('https://remote.example/users/bob')
			->willReturn($bob);

		$note = new Note();
		$note->setTags([['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example']]);

		$note->fillMentions();

		$this->assertSame([
			[
				'id' => '77',
				'username' => 'bob',
				'url' => 'https://remote.example/users/bob',
				'acct' => 'bob@remote.example',
			],
		], $note->getDetails('mentions'));
	}

	public function testFillHashtagsStripsTheHashAndIgnoresOtherTags(): void {
		$note = new Note();
		$note->setTags([
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/nextcloud', 'name' => '#Nextcloud'],
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/fediverse', 'name' => '#fediverse '],
			['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/plain', 'name' => 'plain'],
			['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob'],
		]);

		$note->fillHashtags();

		$this->assertSame(['Nextcloud', 'fediverse', 'plain'], $note->getHashtags());
	}

	public function testExportAsActivityPubRoundTripsTheImportedNote(): void {
		$this->nobodyIsKnown();
		$source = $this->mastodonNote();
		$note = new Note();
		$note->import($source);

		$export = $note->exportAsActivityPub();

		$this->assertSame(
			[ACore::CONTEXT_ACTIVITYSTREAMS, ACore::CONTEXT_EXTENSIONS], $export['@context']
		);
		foreach (['id', 'type', 'url', 'to', 'cc', 'published', 'summary', 'content', 'attributedTo', 'inReplyTo', 'sensitive', 'conversation', 'tag'] as $key) {
			$this->assertSame($source[$key], $export[$key], $key . ' survives the round trip');
		}
		$this->assertArrayNotHasKey('actor', $export);
		$this->assertArrayNotHasKey('object', $export);
	}

	public function testExportAsLocalOfAnImportedNote(): void {
		$this->nobodyIsKnown();
		$note = new Note();
		$note->import($this->mastodonNote());

		$status = $note->exportAsLocal();

		$this->assertSame('https://mastodon.social/users/alice/statuses/112000000000000001', $status['uri']);
		$this->assertSame('https://mastodon.social/users/alice/statuses/112000000000000001', $status['url']);
		$this->assertStringContainsString('look at this', $status['content']);
		$this->assertTrue($status['sensitive']);
		$this->assertSame(5, $status['favourites_count']);
		$this->assertSame(2, $status['reblogs_count']);
		$this->assertCount(1, $status['media_attachments']);
		$this->assertFalse($status['local']);
	}

	public function testImportFromDatabaseReadsHashtagsAndExposesThemOnlyWithCompleteDetails(): void {
		$note = new Note();

		$note->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'hashtags' => '["nextcloud","fediverse"]',
		]);

		$this->assertSame(['nextcloud', 'fediverse'], $note->getHashtags());
		$this->assertArrayNotHasKey('hashtags', $note->jsonSerialize());

		$note->setCompleteDetails(true);
		$this->assertSame(['nextcloud', 'fediverse'], $note->jsonSerialize()['hashtags']);
	}

	public function testTheLanguageComesFromTheContentMapAndGoesBackOut(): void {
		$this->nobodyIsKnown();
		$note = new Note();
		$note->import($this->mastodonNote());

		$this->assertSame('en', $note->getLanguage());
		$this->assertSame('en', $note->exportAsLocal()['language']);
		$this->assertSame(['en' => $note->getContent()], $note->exportAsActivityPub()['contentMap']);
	}
}
