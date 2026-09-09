<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\AP;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../TActivityPubMocks.php';

class StreamTest extends TestCase {
	use TActivityPubMocks;

	private string $timezone;

	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');

		$this->installActivityPub();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $args): string => 'https://cloud.example.org/' . $route . '/' . ($args['uuid'] ?? '')
		);
		\OC::$server->register(IURLGenerator::class, $urlGenerator);
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
		AP::$activityPub = null;
		\OC::$server->reset();
	}

	public function testImportReadsTheMastodonNoteFields(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/112000000000000001',
			'type' => 'Note',
			'summary' => 'CW: cats',
			'inReplyTo' => 'https://mastodon.social/users/bob/statuses/111999999999999999',
			'published' => '2024-05-01T12:00:00Z',
			'url' => 'https://mastodon.social/@alice/112000000000000001',
			'attributedTo' => 'https://mastodon.social/users/alice',
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => ['https://mastodon.social/users/alice/followers'],
			'sensitive' => true,
			'conversation' => 'tag:mastodon.social,2024-05-01:objectId=1:objectType=Conversation',
			'content' => '<p>Look at <b>this</b></p>',
			'attachment' => [],
			'likes' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/likes', 'type' => 'Collection', 'totalItems' => 5],
			'shares' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/shares', 'type' => 'Collection', 'totalItems' => 2],
			'replies' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/replies', 'type' => 'Collection', 'totalItems' => 1],
		]);

		$this->assertSame('Note', $stream->getType());
		$this->assertSame('CW: cats', $stream->getSummary());
		$this->assertSame('https://mastodon.social/users/bob/statuses/111999999999999999', $stream->getInReplyTo());
		$this->assertSame('https://mastodon.social/users/alice', $stream->getAttributedTo());
		$this->assertSame(1714564800, $stream->getPublishedTime());
		$this->assertTrue($stream->isSensitive());
		$this->assertSame('tag:mastodon.social,2024-05-01:objectId=1:objectType=Conversation', $stream->getConversation());
		$this->assertSame('<p>Look at <b>this</b></p>', $stream->getContent());
		$this->assertTrue($stream->isPublic());
		$this->assertSame([], $stream->getAttachments());
		$this->assertSame(5, $stream->getDetailInt('likes'));
		$this->assertSame(5, $stream->getDetailInt('remote_likes'));
		$this->assertSame(2, $stream->getDetailInt('boosts'));
		$this->assertSame(2, $stream->getDetailInt('remote_boosts'));
		$this->assertSame(1, $stream->getDetailInt('replies'));
	}

	public function testARemoteSummaryBecomesTheContentWarning(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/112000000000000001',
			'type' => 'Note',
			'summary' => 'CW: cats',
			'content' => '<p>cat</p>',
		]);

		$this->assertSame('CW: cats', $stream->getSpoilerText());
		$this->assertSame('CW: cats', $stream->exportAsLocal()['spoiler_text']);
	}

	public function testTheContentWarningIsStoredAsTheSummary(): void {
		$stream = new Stream();

		$stream->setSpoilerText('CW: dogs');

		$this->assertSame('CW: dogs', $stream->getSummary(), 'summary is the database column');
	}

	public function testCreatedAtIsUtcRegardlessOfTheServerTimezone(): void {
		$previous = date_default_timezone_get();
		date_default_timezone_set('America/New_York');

		try {
			$stream = new Stream();
			$stream->setPublishedTime(1714564800);

			$this->assertSame('2024-05-01T12:00:00.000Z', $stream->exportAsLocal()['created_at']);
		} finally {
			date_default_timezone_set($previous);
		}
	}

	public function testImportLeavesCountsAloneWhenTheCollectionsCarryNoTotals(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'likes' => ['id' => 'https://mastodon.social/users/alice/statuses/1/likes', 'type' => 'Collection'],
		]);

		$this->assertSame([], $stream->getDetailsAll());
	}

	public function testImportBuildsMediaAttachmentsThroughTheDocumentAndImageInterfaces(): void {
		$this->apInterface(DocumentInterface::class)->expects($this->once())->method('save')
			->with($this->isInstanceOf(Document::class));
		$this->apInterface(ImageInterface::class)->expects($this->once())->method('save')
			->with($this->isInstanceOf(Image::class));

		$stream = new Stream();
		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'attachment' => [
				['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://files.mastodon.social/media/cat.jpg', 'name' => 'A cat'],
				['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://files.mastodon.social/media/dog.png'],
				['type' => 'Link', 'href' => 'https://example.org/'],
				['type' => 'Document', 'mediaType' => 'video/mp4'],
			],
		]);

		$attachments = $stream->getAttachments();
		$this->assertCount(2, $attachments);
		$this->assertContainsOnlyInstancesOf(MediaAttachment::class, $attachments);
		$this->assertSame('image', $attachments[0]->getType());
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $attachments[0]->getRemoteUrl());
		$this->assertStringEndsWith('social.Api.mediaOpen/.jpeg', $attachments[0]->getUrl());
		$this->assertStringEndsWith('.jpeg', $attachments[0]->getPreviewUrl());
		$this->assertSame('https://files.mastodon.social/media/dog.png', $attachments[1]->getRemoteUrl());
		$this->assertStringEndsWith('.png', $attachments[1]->getUrl());
	}

	public function testConvertPublishedIgnoresUnparsableDates(): void {
		$stream = new Stream();
		$stream->setPublished('not a date');

		$stream->convertPublished();

		$this->assertSame(0, $stream->getPublishedTime());
	}

	public function testExportAsActivityPubAddsTheStreamFields(): void {
		$stream = new Note();
		$stream->setId('https://cloud.example.org/apps/social/@alice/1')
			->setContent('<p>hi</p>')
			->setInReplyTo('https://mastodon.social/users/bob/statuses/2')
			->setSensitive(true)
			->setConversation('https://cloud.example.org/conv/1')
			->setAttributedTo('@alice')
			->setUrlSocial('https://cloud.example.org/apps/social/');

		$export = $stream->exportAsActivityPub();

		$this->assertSame('<p>hi</p>', $export['content']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice', $export['attributedTo']);
		$this->assertSame('https://mastodon.social/users/bob/statuses/2', $export['inReplyTo']);
		$this->assertTrue($export['sensitive']);
		$this->assertSame('https://cloud.example.org/conv/1', $export['conversation']);
		$this->assertArrayNotHasKey('details', $export);
		$this->assertArrayNotHasKey('publishedTime', $export);
	}

	public function testExportAsActivityPubAddsInternalsOnlyWithCompleteDetails(): void {
		$stream = new Note();
		$stream->setPublishedTime(1714564800)
			->setCompleteDetails(true);
		$stream->setDetailInt('likes', 3);

		$export = $stream->exportAsActivityPub();

		$this->assertSame(['likes' => 3], $export['details']);
		$this->assertSame(1714564800, $export['publishedTime']);
		$this->assertArrayNotHasKey('action', $export, 'an unset action is dropped');
		$this->assertArrayNotHasKey('cache', $export, 'an unset cache is dropped');
		$this->assertSame('', $export['attributedTo'] ?? '', 'empty attributedTo stays out');
	}

	public function testExportAsLocalProducesAMastodonStatus(): void {
		$actor = new Person();
		$actor->setNid(3)
			->setPreferredUsername('alice')
			->setName('Alice')
			->setLocal(true);

		$action = new StreamAction();
		$action->updateValueBool(StreamAction::LIKED, true);
		$action->updateValueBool(StreamAction::BOOSTED, false);

		$stream = new Note();
		$stream->setId('https://cloud.example.org/apps/social/@alice/1')
			->setNid(7)
			->setContent('<p>hi</p>')
			->setSensitive(true)
			->setSpoilerText('cw')
			->setVisibility(Stream::TYPE_PUBLIC)
			->setLanguage('de')
			->setPublishedTime(1714564800)
			->setMentions([['id' => '3', 'username' => 'bob', 'url' => 'https://b.example/@bob', 'acct' => 'bob@b.example']])
			->setLocal(true)
			->setAction($action)
			->setActor($actor);
		$stream->setDetailInt('replies', 1);
		$stream->setDetailInt('boosts', 2);
		$stream->setDetailInt('likes', 5);

		$status = $stream->exportAsLocal();

		$this->assertSame('7', $status['id']);
		$this->assertTrue($status['local']);
		$this->assertSame('<p>hi</p>', $status['content']);
		$this->assertTrue($status['sensitive']);
		$this->assertSame('cw', $status['spoiler_text']);
		$this->assertSame('public', $status['visibility']);
		$this->assertSame('de', $status['language']);
		$this->assertNull($status['in_reply_to_id']);
		$this->assertSame('bob@b.example', $status['mentions'][0]['acct']);
		$this->assertSame(1, $status['replies_count']);
		$this->assertSame(2, $status['reblogs_count']);
		$this->assertSame(5, $status['favourites_count']);
		$this->assertTrue($status['favourited']);
		$this->assertFalse($status['reblogged']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice/1', $status['uri']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice/1', $status['url']);
		$this->assertNull($status['reblog']);
		$this->assertSame([], $status['media_attachments']);
		$this->assertSame('2024-05-01T12:00:00.000Z', $status['created_at']);
		$this->assertSame('3', $status['account']['id']);
		$this->assertSame('alice', $status['account']['username']);
		$this->assertSame('alice', $status['account']['acct']);
		$this->assertSame('Alice', $status['account']['display_name']);
	}

	public function testExportAsLocalWithoutActorHasNoAccount(): void {
		$status = (new Note())->exportAsLocal();

		$this->assertArrayNotHasKey('account', $status);
		$this->assertFalse($status['favourited']);
		$this->assertFalse($status['reblogged']);
	}

	/**
	 * The reverse of the export map, used by the `types`/`exclude_types`
	 * notification filter. Both directions come from one table, so this also
	 * guards against the two drifting apart.
	 */
	public function testSubTypesOfNotificationTypesSelectsTheMatchingSubTypes(): void {
		$this->assertSame(['Mention'], Stream::subTypesOfNotificationTypes(['mention']));
		$this->assertSame(
			['Like', 'Announce'],
			Stream::subTypesOfNotificationTypes(['reblog', 'favourite']),
			'the order is the map\'s, which is all an IN clause needs'
		);
		$this->assertSame(
			['Mention'],
			Stream::subTypesOfNotificationTypes(['mention', 'mention']),
			'a repeated type is asked for once'
		);
	}

	public function testAnUnknownNotificationTypeSelectsNothing(): void {
		$this->assertSame([], Stream::subTypesOfNotificationTypes(['status']));
		$this->assertSame(
			['Mention'],
			Stream::subTypesOfNotificationTypes(['mention', 'status']),
			'an unknown type alongside a known one does not widen the filter'
		);
		$this->assertSame([], Stream::subTypesOfNotificationTypes([]));
	}

	public function notificationTypeProvider(): array {
		return [
			'like' => ['Like', 'favourite'],
			'announce' => ['Announce', 'reblog'],
			'mention' => ['Mention', 'mention'],
			'follow' => ['Follow', 'follow'],
			'follow request' => ['FollowRequest', 'follow_request'],
			'other' => ['Create', ''],
		];
	}

	/**
	 * @dataProvider notificationTypeProvider
	 */
	public function testExportAsNotificationMapsTheSubTypeToMastodonTypes(string $subType, string $expected): void {
		$actor = new Person();
		$actor->setPreferredUsername('alice');
		$status = new Note();
		$stream = new Stream();
		$stream->setNid(9)
			->setSubType($subType)
			->setPublishedTime(1714564800)
			->setObject($status)
			->setActor($actor);

		$notification = $stream->exportAsNotification();

		$this->assertSame('9', $notification['id']);
		$this->assertSame($expected, $notification['type']);
		$this->assertSame('2024-05-01T12:00:00.000Z', $notification['created_at']);
		$this->assertSame($status, $notification['status']);
		$this->assertSame('alice', $notification['account']['username']);
	}

	public function testImportReadsCustomEmojiFromTheTagList(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://remote.example/notes/1',
			'type' => 'Note',
			'content' => 'hello :blobcat: world :blobcat: :broken:',
			'tag' => [
				['type' => 'Mention', 'href' => 'https://x.example/@a', 'name' => '@a'],
				['type' => 'Emoji', 'name' => ':blobcat:', 'icon' => ['type' => 'Image', 'url' => 'https://remote.example/emoji/blobcat.png']],
				['type' => 'Emoji', 'name' => ':broken:'],
				['type' => 'Emoji', 'name' => ':evil:', 'icon' => ['url' => 'javascript:alert(1)']],
			],
		]);

		$this->assertSame([[
			'shortcode' => 'blobcat',
			'url' => 'https://remote.example/emoji/blobcat.png',
			'static_url' => 'https://remote.example/emoji/blobcat.png',
			'visible_in_picker' => false,
		]], $stream->getEmojis(), 'icon-less and non-https emoji are dropped');
	}

	public function testCustomEmojiSurviveTheDatabaseRoundTripViaTheStoredSource(): void {
		$wire = [
			'id' => 'https://remote.example/notes/1',
			'type' => 'Note',
			'tag' => [
				['type' => 'Emoji', 'name' => ':party:', 'icon' => ['url' => 'https://remote.example/emoji/party.gif']],
			],
		];
		$stream = new Stream();
		$stream->importFromDatabase([
			'id' => 'https://remote.example/notes/1',
			'source' => json_encode($wire),
		]);

		$this->assertSame('party', $stream->getEmojis()[0]['shortcode']);
		$this->assertSame($stream->getEmojis(), $stream->exportAsLocal()['emojis']);
	}

	public function testJsonSerializeExposesTheAttachments(): void {
		$media = (new MediaAttachment())->setId('4');
		$stream = new Note();
		$stream->setAttachments([$media]);

		$this->assertSame([$media], $stream->jsonSerialize()['attachment']);
	}

	public function testImportFromLocalReadsAMastodonStatus(): void {
		$stream = new Stream();

		$stream->importFromLocal([
			'id' => '123',
			'url' => 'https://mastodon.social/@alice/123',
			'local' => false,
			'content' => '<p>hello</p>',
			'sensitive' => true,
			'spoiler_text' => 'cw',
			'visibility' => 'unlisted',
			'language' => 'de',
			'favourited' => true,
			'reblogged' => false,
			'created_at' => '2024-05-01T12:00:00.000Z',
			'media_attachments' => [
				[
					'id' => '55',
					'type' => 'image',
					'url' => 'https://files.mastodon.social/media/cat.jpg',
					'preview_url' => 'https://files.mastodon.social/media/small/cat.jpg',
					'remote_url' => null,
					'description' => 'A cat',
					'blurhash' => 'UBL_:rOp',
					'meta' => ['original' => ['width' => 1200, 'height' => 800, 'size' => '1200x800'], 'small' => ['width' => 600, 'height' => 400], 'focus' => ['x' => 0, 'y' => 0]],
				],
			],
			'mentions' => [['id' => '2', 'username' => 'bob', 'url' => 'https://b.example/@bob', 'acct' => 'bob@b.example']],
			'account' => [
				'id' => '31',
				'username' => 'alice',
				'acct' => 'alice@mastodon.social',
				'display_name' => 'Alice W.',
				'locked' => true,
				'bot' => false,
				'discoverable' => true,
				'note' => '<p>bio</p>',
				'url' => 'https://mastodon.social/@alice',
				'avatar' => 'https://files.mastodon.social/avatars/alice.png',
				'header' => 'https://files.mastodon.social/headers/alice.jpg',
				'followers_count' => 10,
				'following_count' => 5,
				'statuses_count' => 42,
				'created_at' => '2019-01-01T00:00:00.000Z',
				'source' => ['privacy' => 'unlisted', 'sensitive' => true, 'language' => 'fr'],
			],
		]);

		$this->assertSame(123, $stream->getNid());
		$this->assertSame('https://mastodon.social/@alice/123', $stream->getId());
		$this->assertSame('https://mastodon.social/@alice/123', $stream->getUrl());
		$this->assertFalse($stream->isLocal());
		$this->assertSame('<p>hello</p>', $stream->getContent());
		$this->assertTrue($stream->isSensitive());
		$this->assertSame('cw', $stream->getSpoilerText());
		$this->assertSame('unlisted', $stream->getVisibility());
		$this->assertSame('de', $stream->getLanguage());
		$this->assertSame(1714564800, $stream->getPublishedTime());
		$this->assertTrue($stream->getAction()->getValueBool(StreamAction::LIKED));
		$this->assertFalse($stream->getAction()->getValueBool(StreamAction::BOOSTED));

		$attachments = $stream->getAttachments();
		$this->assertCount(1, $attachments);
		$this->assertInstanceOf(MediaAttachment::class, $attachments[0]);
		$this->assertSame('55', $attachments[0]->getId());
		$this->assertSame('A cat', $attachments[0]->getDescription());
		$this->assertSame(1200, $attachments[0]->getMeta()->getOriginal()->getWidth());
		$this->assertSame('bob@b.example', $stream->getMentions()[0]['acct']);

		$actor = $stream->getActor();
		$this->assertSame(31, $actor->getNid());
		$this->assertSame('alice', $actor->getPreferredUsername());
		$this->assertSame('alice@mastodon.social', $actor->getAccount());
		$this->assertSame('Alice W.', $actor->getDisplayName());
		$this->assertTrue($actor->isLocked());
		$this->assertFalse($actor->isBot());
		$this->assertTrue($actor->isDiscoverable());
		$this->assertSame('<p>bio</p>', $actor->getDescription());
		$this->assertSame('https://files.mastodon.social/avatars/alice.png', $actor->getAvatar());
		$this->assertSame('https://files.mastodon.social/headers/alice.jpg', $actor->getHeader());
		$this->assertSame('unlisted', $actor->getPrivacy());
		$this->assertTrue($actor->isSensitive());
		$this->assertSame('fr', $actor->getLanguage());
		$this->assertSame(1546300800, $actor->getCreation());
		$this->assertSame(10, $actor->getDetails('count')['followers']);
		$this->assertSame(42, $actor->getDetails('count')['post']);
		$this->assertSame(ACore::FORMAT_LOCAL, $actor->getExportFormat());
	}

	public function testImportFromDatabaseFillsRemoteCountsFromTheSource(): void {
		$stream = new Stream();

		$stream->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'published_time' => '2024-05-01 12:00:00',
			'content' => '<p onclick="x()">hello</p>',
			'visibility' => 'unlisted',
			'details' => '{}',
			'filter_duplicate' => '1',
			'source' => '{"likes":{"totalItems":4},"shares":{"totalItems":2},"replies":{"totalItems":1}}',
			'cache' => '{"_items":["https://a.example/1"],"https://a.example/1":{"url":"https://a.example/1","content":"{}","status":200}}',
		]);

		$this->assertSame(1714564800, $stream->getPublishedTime());
		$this->assertSame('<p>hello</p>', $stream->getContent());
		$this->assertSame('unlisted', $stream->getVisibility());
		$this->assertTrue($stream->isFilterDuplicate());
		$this->assertSame(4, $stream->getDetailInt('remote_likes'));
		$this->assertSame(4, $stream->getDetailInt('likes'));
		$this->assertSame(2, $stream->getDetailInt('remote_boosts'));
		$this->assertSame(2, $stream->getDetailInt('boosts'));
		$this->assertSame(1, $stream->getDetailInt('replies'));
		$this->assertTrue($stream->hasCache());
		$this->assertTrue($stream->getCache()->hasItem('https://a.example/1'));
		$this->assertSame(200, $stream->getCache()->getItem('https://a.example/1')->getStatus());
	}

	public function testImportFromDatabaseKeepsLocalCountsWhenTheyExist(): void {
		$stream = new Stream();

		$stream->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'details' => '{"likes":9,"remote_boosts":1,"boosts":1,"mentions":[{"acct":"bob@b.example"}]}',
			'source' => '{"likes":{"totalItems":4},"shares":{"totalItems":7}}',
		]);

		$this->assertSame(9, $stream->getDetailInt('likes'), 'local likes are not overwritten by the remote total');
		$this->assertSame(4, $stream->getDetailInt('remote_likes'));
		$this->assertSame(1, $stream->getDetailInt('remote_boosts'), 'already known remote boosts stay');
		$this->assertSame([['acct' => 'bob@b.example']], $stream->getMentions());
	}

	public function testAddCacheItemCreatesTheCacheOnDemandAndIgnoresDuplicates(): void {
		$stream = new Stream();
		$this->assertFalse($stream->hasCache());

		$stream->addCacheItem('https://a.example/1');
		$stream->addCacheItem('https://a.example/1');
		$stream->addCacheItem('https://a.example/2');

		$this->assertTrue($stream->hasCache());
		$this->assertCount(2, $stream->getCache()->getItems());
	}

	public function testAContentWarningMakesAStatusSensitive(): void {
		$stream = new Stream();
		$this->assertFalse($stream->isSensitive());

		$stream->setSpoilerText('season finale');

		// clients that know nothing of `spoiler_text` still hide the body
		$this->assertTrue($stream->isSensitive());
		$this->assertTrue($stream->exportAsActivityPub()['sensitive']);
	}

	public function testAnEmptyContentWarningLeavesSensitiveAlone(): void {
		$stream = new Stream();
		$stream->setSpoilerText('');
		$this->assertFalse($stream->isSensitive());

		$stream->setSensitive(true);
		$this->assertTrue($stream->isSensitive());
	}
}
