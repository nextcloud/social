<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Actor;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../TActivityPubMocks.php';

class PersonTest extends TestCase {
	use TActivityPubMocks;

	private const PEM = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0\n-----END PUBLIC KEY-----\n";

	private string $timezone;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;

	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');

		$this->installActivityPub();
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		\OC::$server->register(IURLGenerator::class, $this->urlGenerator);
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
		AP::$activityPub = null;
		\OC::$server->reset();
	}

	/** An actor document as served by Mastodon. */
	private function mastodonActor(): array {
		return [
			'@context' => [ACore::CONTEXT_ACTIVITYSTREAMS, ACore::CONTEXT_SECURITY],
			'id' => 'https://mastodon.social/users/alice',
			'type' => 'Person',
			'following' => 'https://mastodon.social/users/alice/following',
			'followers' => 'https://mastodon.social/users/alice/followers',
			'inbox' => 'https://mastodon.social/users/alice/inbox',
			'outbox' => 'https://mastodon.social/users/alice/outbox',
			'featured' => 'https://mastodon.social/users/alice/collections/featured',
			'preferredUsername' => 'alice',
			'name' => 'Alice <b>W.</b>',
			'summary' => '<p>Hello <script>alert(1)</script>world</p>',
			'url' => 'https://mastodon.social/@alice',
			'manuallyApprovesFollowers' => true,
			'discoverable' => true,
			'published' => '2019-01-01T00:00:00Z',
			'publicKey' => [
				'id' => 'https://mastodon.social/users/alice#main-key',
				'owner' => 'https://mastodon.social/users/alice',
				'publicKeyPem' => self::PEM,
			],
			'endpoints' => ['sharedInbox' => 'https://mastodon.social/inbox'],
			'icon' => ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://files.mastodon.social/accounts/avatars/001/original/avatar.png'],
			'image' => ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://files.mastodon.social/accounts/headers/001/original/header.jpg'],
		];
	}

	public function testConstructorSetsTheType(): void {
		$this->assertSame('Person', (new Person())->getType());
	}

	public function testImportReadsAlsoKnownAs(): void {
		$person = new Person();
		$actor = $this->mastodonActor();
		$actor['alsoKnownAs'] = ['https://old.example/users/alice', 42, ['nested']];

		$person->import($actor);

		$this->assertSame(['https://old.example/users/alice'], $person->getAlsoKnownAs(), 'non-strings are dropped');
	}

	public function testAlsoKnownAsSurvivesTheActorCache(): void {
		$person = new Person();

		$person->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice',
			'source' => '{"alsoKnownAs":["https://old.example/users/alice"]}',
		]);

		$this->assertSame(['https://old.example/users/alice'], $person->getAlsoKnownAs());
	}

	public function testAlsoKnownAsIsExportedOnlyWhenSet(): void {
		$person = new Person();
		$person->setUrlSocial('https://cloud.example.org/apps/social/');

		$this->assertArrayNotHasKey('alsoKnownAs', $person->exportAsActivityPub());

		$person->setAlsoKnownAs(['https://old.example/users/alice']);
		$this->assertSame(['https://old.example/users/alice'], $person->exportAsActivityPub()['alsoKnownAs']);
	}

	public function testImportReadsAMastodonActorDocument(): void {
		$person = new Person();

		$person->import($this->mastodonActor());

		$this->assertSame('https://mastodon.social/users/alice', $person->getId());
		$this->assertSame('https://mastodon.social/@alice', $person->getUrl());
		$this->assertSame('alice', $person->getPreferredUsername());
		$this->assertSame('Alice W.', $person->getName(), 'name is stripped of tags');
		$this->assertSame('<p>Hello world</p>', $person->getDescription(), 'summary is sanitized');
		$this->assertSame('https://mastodon.social/users/alice/inbox', $person->getInbox());
		$this->assertSame('https://mastodon.social/users/alice/outbox', $person->getOutbox());
		$this->assertSame('https://mastodon.social/users/alice/followers', $person->getFollowers());
		$this->assertSame('https://mastodon.social/users/alice/following', $person->getFollowing());
		$this->assertSame('https://mastodon.social/users/alice/collections/featured', $person->getFeatured());
		$this->assertSame('https://mastodon.social/inbox', $person->getSharedInbox());
		$this->assertSame(self::PEM, $person->getPublicKey());
		$this->assertSame('2019-01-01T00:00:00Z', $person->getPublished());
		$this->assertTrue($person->isLocked(), 'manuallyApprovesFollowers marks the account locked');

		$this->assertTrue($person->hasIcon());
		$this->assertInstanceOf(Image::class, $person->getIcon());
		$this->assertSame($person, $person->getIcon()->getParent());
		$this->assertSame('image/png', $person->getIcon()->getMediaType());
		$this->assertSame('https://files.mastodon.social/accounts/avatars/001/original/avatar.png', $person->getAvatar());
		$this->assertSame('https://files.mastodon.social/accounts/headers/001/original/header.jpg', $person->getHeader());
	}

	public function testImportWithoutIconOrImageLeavesAvatarAndHeaderEmpty(): void {
		$person = new Person();
		$actor = $this->mastodonActor();
		unset($actor['icon'], $actor['image']);

		$person->import($actor);

		$this->assertFalse($person->hasIcon());
		$this->assertSame('', $person->getAvatar());
		$this->assertSame('', $person->getHeader());
	}

	public function testHeaderFallsBackToTheAvatar(): void {
		$person = new Person();
		$person->setAvatar('https://a.example/avatar.png');

		$this->assertSame('https://a.example/avatar.png', $person->getHeader());

		$person->setHeader('https://a.example/header.jpg');
		$this->assertSame('https://a.example/header.jpg', $person->getHeader());
	}

	public function testNameAndDisplayNameFallBackToThePreferredUsername(): void {
		$person = new Person();
		$person->setPreferredUsername('alice');

		$this->assertSame('alice', $person->getName());
		$this->assertSame('alice', $person->getDisplayName());

		$person->setName('Alice');
		$person->setDisplayName('Alice W.');
		$this->assertSame('Alice', $person->getName());
		$this->assertSame('Alice W.', $person->getDisplayName());
	}

	public function testSetAccountStripsTheLeadingAt(): void {
		$person = new Person();

		$person->setAccount('@alice@mastodon.social');
		$this->assertSame('alice@mastodon.social', $person->getAccount());

		$person->setAccount('bob@mastodon.social');
		$this->assertSame('bob@mastodon.social', $person->getAccount());
	}

	private function localActor(): Person {
		$person = new Person();
		$person->setId('https://cloud.example.org/apps/social/@alice')
			->setPreferredUsername('alice')
			->setName('Alice')
			->setInbox('https://cloud.example.org/apps/social/@alice/inbox')
			->setOutbox('https://cloud.example.org/apps/social/@alice/outbox')
			->setFollowers('https://cloud.example.org/apps/social/@alice/followers')
			->setFollowing('https://cloud.example.org/apps/social/@alice/following')
			->setSharedInbox('https://cloud.example.org/apps/social/inbox')
			->setPublicKey(self::PEM)
			->setUrlSocial('https://cloud.example.org/apps/social/');

		return $person;
	}

	public function testExportAsActivityPubProducesTheKeyAndEndpointBlocks(): void {
		$export = $this->localActor()->exportAsActivityPub();

		$this->assertSame([ACore::CONTEXT_ACTIVITYSTREAMS, ACore::CONTEXT_SECURITY], $export['@context']);
		$this->assertSame('Person', $export['type']);
		$this->assertSame('alice', $export['preferredUsername']);
		$this->assertSame('Alice', $export['name']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice/inbox', $export['inbox']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice/outbox', $export['outbox']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice/followers', $export['followers']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice/following', $export['following']);
		$this->assertSame(['sharedInbox' => 'https://cloud.example.org/apps/social/inbox'], $export['endpoints']);
		$this->assertSame([
			'id' => 'https://cloud.example.org/apps/social/@alice#main-key',
			'owner' => 'https://cloud.example.org/apps/social/@alice',
			'publicKeyPem' => self::PEM,
		], $export['publicKey']);
		$this->assertSame([
			'https://cloud.example.org/apps/social/@alice',
			'https://cloud.example.org/apps/social/users/alice',
		], $export['aliases']);
		$this->assertArrayNotHasKey('icon', $export);
		$this->assertArrayNotHasKey('image', $export);
		$this->assertArrayNotHasKey('details', $export);
	}

	public function testExportAsActivityPubWithoutPublicKeyKeepsThePlainContext(): void {
		$person = $this->localActor();
		$person->setPublicKey('');

		$this->assertSame([ACore::CONTEXT_ACTIVITYSTREAMS], $person->exportAsActivityPub()['@context']);
	}

	public function testExportAsActivityPubDescribesIconAndHeaderImages(): void {
		$person = $this->localActor();
		$icon = new Image();
		$icon->setMediaType('image/png')
			->setUrl('https://cloud.example.org/avatar.png');
		$person->setIcon($icon);
		$person->setHeader('https://cloud.example.org/header.jpg');
		$person->setCompleteDetails(true);
		$person->setViewerLink(Person::LINK_LOCAL);
		$person->setDetailInt('followers', 3);

		$export = $person->exportAsActivityPub();

		$this->assertSame(['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://cloud.example.org/avatar.png'], $export['icon']);
		$this->assertSame(['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://cloud.example.org/header.jpg'], $export['image']);
		$this->assertSame(['followers' => 3], $export['details']);
		$this->assertSame('local', $export['viewerLink']);
	}

	public function testExportAsLocalProducesAMastodonAccount(): void {
		$person = new Person();
		$person->setNid(3)
			->setId('https://mastodon.social/users/alice')
			->setPreferredUsername('alice')
			->setAccount('alice@mastodon.social')
			->setName('Alice')
			->setLocked(true)
			->setBot(false)
			->setDiscoverable(true)
			->setDescription('<p>bio</p>')
			->setAvatar('https://files.mastodon.social/avatars/alice.png')
			->setHeader('https://files.mastodon.social/headers/alice.jpg')
			->setCreation(1546300800)
			->setPrivacy('unlisted')
			->setSensitive(true)
			->setLanguage('fr');
		$person->setDetailArray('count', ['followers' => 10, 'following' => 5, 'post' => 42]);

		$account = $person->exportAsLocal();

		$this->assertSame('3', $account['id']);
		$this->assertSame('alice', $account['username']);
		$this->assertSame('alice@mastodon.social', $account['acct']);
		$this->assertSame('Alice', $account['display_name']);
		$this->assertTrue($account['locked']);
		$this->assertFalse($account['bot']);
		$this->assertTrue($account['discoverable']);
		$this->assertFalse($account['group']);
		$this->assertSame('2019-01-01T00:00:00.000Z', $account['created_at']);
		$this->assertSame('<p>bio</p>', $account['note']);
		$this->assertSame('https://mastodon.social/users/alice', $account['url']);
		$this->assertSame('https://files.mastodon.social/avatars/alice.png', $account['avatar']);
		$this->assertSame('https://files.mastodon.social/avatars/alice.png', $account['avatar_static']);
		$this->assertSame('https://files.mastodon.social/headers/alice.jpg', $account['header']);
		$this->assertSame('https://files.mastodon.social/headers/alice.jpg', $account['header_static']);
		$this->assertSame(10, $account['followers_count']);
		$this->assertSame(5, $account['following_count']);
		$this->assertSame(42, $account['statuses_count']);
		$this->assertSame(['privacy' => 'unlisted', 'sensitive' => true, 'language' => 'fr', 'note' => '<p>bio</p>', 'fields' => [], 'follow_requests_count' => 0], $account['source']);
		$this->assertSame([], $account['emojis']);
		$this->assertSame([], $account['fields']);
	}

	public function testExportAsLocalUsesTheUsernameAsAcctForLocalAccounts(): void {
		$person = new Person();
		$person->setPreferredUsername('alice')
			->setAccount('alice@cloud.example.org')
			->setLocal(true);

		$this->assertSame('alice', $person->exportAsLocal()['acct']);
	}

	public function testExportAsLocalServesTheCachedIconThroughTheMediaRoute(): void {
		$this->urlGenerator->expects($this->once())->method('linkToRouteAbsolute')
			->with('social.Api.mediaOpen', ['uuid' => 'abc123'])
			->willReturn('https://cloud.example.org/apps/social/media/abc123');
		$person = new Person();
		$icon = new Document();
		$icon->setLocalCopy('abc123')
			->setUrl('https://files.mastodon.social/avatars/alice.png');
		$person->setIcon($icon);

		$account = $person->exportAsLocal();

		$this->assertSame('https://cloud.example.org/apps/social/media/abc123', $account['avatar']);
		$this->assertSame('https://cloud.example.org/apps/social/media/abc123', $account['avatar_static']);
	}

	public function testImportFromLocalReadsAMastodonAccount(): void {
		$person = new Person();

		$person->importFromLocal([
			'id' => '31',
			'username' => 'alice',
			'acct' => '@alice@mastodon.social',
			'display_name' => 'Alice W.',
			'locked' => 'true',
			'bot' => false,
			'discoverable' => true,
			'note' => '<p>bio</p>',
			'url' => 'https://mastodon.social/@alice',
			'avatar' => 'https://files.mastodon.social/avatars/alice.png',
			'header' => 'https://files.mastodon.social/headers/alice.jpg',
			'followers_count' => 10,
			'following_count' => 5,
			'statuses_count' => 42,
			'last_status_at' => '2024-05-01',
			'created_at' => '2019-01-01T00:00:00.000Z',
			'source' => ['privacy' => 'private', 'sensitive' => false, 'language' => 'de'],
		]);

		$this->assertSame(31, $person->getNid());
		$this->assertSame('https://mastodon.social/@alice', $person->getId());
		$this->assertSame('alice', $person->getPreferredUsername());
		$this->assertSame('alice@mastodon.social', $person->getAccount());
		$this->assertSame('Alice W.', $person->getDisplayName());
		$this->assertTrue($person->isLocked());
		$this->assertFalse($person->isBot());
		$this->assertTrue($person->isDiscoverable());
		$this->assertSame('<p>bio</p>', $person->getDescription());
		$this->assertSame('private', $person->getPrivacy());
		$this->assertFalse($person->isSensitive());
		$this->assertSame('de', $person->getLanguage());
		$this->assertSame(1546300800, $person->getCreation());
		$this->assertSame(
			['followers' => 10, 'following' => 5, 'post' => 42, 'last_post_creation' => '2024-05-01'],
			$person->getDetails('count')
		);
	}

	public function testImportWithoutManuallyApprovesFollowersLeavesTheAccountUnlocked(): void {
		$document = $this->mastodonActor();
		unset($document['manuallyApprovesFollowers']);

		$person = new Person();
		$person->import($document);

		$this->assertFalse($person->isLocked());
	}

	public function testLockedRoundTripsThroughTheActivityPubExport(): void {
		$person = new Person();
		$person->setId('https://social.example/@alice');
		$person->setLocked(true);

		$exported = $person->exportAsActivityPub();
		$this->assertTrue($exported['manuallyApprovesFollowers']);

		$copy = new Person();
		$copy->import(json_decode(json_encode($exported), true));
		$this->assertTrue($copy->isLocked());
	}

	public function testImportFromDatabaseReadsTheLockedColumn(): void {
		$person = new Person();
		$person->importFromDatabase(['id' => 'https://social.example/@alice', 'locked' => 1]);

		$this->assertTrue($person->isLocked());
	}

	public function testImportFromDatabaseReadsLockedFromTheCachedSource(): void {
		$person = new Person();
		$person->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice',
			'source' => '{"manuallyApprovesFollowers":true}',
		]);

		$this->assertTrue($person->isLocked(), 'cache actor rows carry the flag in their source document');
	}

	public function testImportFromDatabaseReadsTheActorRow(): void {
		$person = new Person();

		$person->importFromDatabase([
			'nid' => 3,
			'id' => 'https://mastodon.social/users/alice',
			'type' => 'Person',
			'preferred_username' => 'alice',
			'user_id' => '',
			'name' => 'Alice',
			'summary' => '<p onclick="x()">bio</p>',
			'account' => 'alice@mastodon.social',
			'public_key' => self::PEM,
			'private_key' => '',
			'inbox' => 'https://mastodon.social/users/alice/inbox',
			'outbox' => 'https://mastodon.social/users/alice/outbox',
			'followers' => 'https://mastodon.social/users/alice/followers',
			'following' => 'https://mastodon.social/users/alice/following',
			'shared_inbox' => 'https://mastodon.social/inbox',
			'featured' => '',
			'details' => '{"count":{"followers":10}}',
			'creation' => '2019-01-01 00:00:00',
			'deleted' => '',
			'source' => '{"image":{"url":"https://files.mastodon.social/headers/alice.jpg"}}',
		]);

		$this->assertSame(3, $person->getNid());
		$this->assertSame('alice', $person->getPreferredUsername());
		$this->assertSame('Alice', $person->getName());
		$this->assertSame('<p>bio</p>', $person->getDescription());
		$this->assertSame('alice@mastodon.social', $person->getAccount());
		$this->assertSame(self::PEM, $person->getPublicKey());
		$this->assertSame('https://mastodon.social/inbox', $person->getSharedInbox());
		$this->assertSame(10, $person->getDetails('count')['followers']);
		$this->assertSame(1546300800, $person->getCreation());
		$this->assertSame(0, $person->getDeleted());
		$this->assertSame('https://files.mastodon.social/headers/alice.jpg', $person->getHeader(), 'header comes from the stored source');
	}

	public function testImportFromDatabaseParsesTheStoredDeletionTime(): void {
		$person = new Person();

		$person->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice',
			'deleted' => '2019-01-01 00:00:00',
		]);

		$this->assertSame(1546300800, $person->getDeleted(), 'the stored time, not the current one');
	}
}
