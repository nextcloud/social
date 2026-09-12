<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Exceptions\ActivityCantBeVerifiedException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\LinkedDataSignature;
use PHPUnit\Framework\TestCase;

class ACoreTest extends TestCase {
	private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

	public function testConstructorAttachesToParentAndRootIsTheTopOfTheChain(): void {
		$root = new ACore();
		$middle = new ACore($root);
		$leaf = new ACore($middle);

		$this->assertTrue($root->isRoot());
		$this->assertFalse($leaf->isRoot());
		$this->assertSame($middle, $leaf->getParent());
		$this->assertSame($root, $leaf->getRoot());

		$chain = [];
		$leaf->getRoot($chain);
		$this->assertSame([$leaf, $middle, $root], $chain);
	}

	public function testRequestTokenIsReadFromTheRoot(): void {
		$root = new ACore();
		$root->setRequestToken('tok-123');
		$child = new ACore($root);
		$child->setRequestToken('ignored');

		$this->assertSame('tok-123', $child->getRequestToken());
	}

	public function testSetObjectAdoptsTheObjectAndItsIdWins(): void {
		$activity = new Like();
		$activity->setObjectId('https://a.example/old');
		$note = new ACore();
		$note->setId('https://a.example/note/1');

		$activity->setObject($note);

		$this->assertTrue($activity->hasObject());
		$this->assertSame($activity, $note->getParent());
		$this->assertSame('https://a.example/note/1', $activity->getObjectId());
	}

	public function testRecipientsMergeToAndCcAndCanDropThePublicCollection(): void {
		$item = new ACore();
		$item->setToArray([ACore::CONTEXT_PUBLIC, 'https://a.example/users/alice']);
		$item->setCcArray(['https://a.example/users/alice/followers']);

		$all = $item->getRecipients();
		$this->assertContains(ACore::CONTEXT_PUBLIC, $all);
		$this->assertContains('https://a.example/users/alice', $all);
		$this->assertContains('https://a.example/users/alice/followers', $all);

		$filtered = $item->getRecipients(true);
		$this->assertNotContains(ACore::CONTEXT_PUBLIC, $filtered);
		$this->assertContains('https://a.example/users/alice', $filtered);
		$this->assertContains('https://a.example/users/alice/followers', $filtered);
	}

	public function publicAudienceProvider(): array {
		$followers = 'https://a.example/users/alice/followers';

		return [
			'public in to' => [[ACore::CONTEXT_PUBLIC], [$followers], true],
			'public in cc (unlisted)' => [[$followers], [ACore::CONTEXT_PUBLIC], true],
			'followers only' => [[$followers], [], false],
			'direct message' => [['https://b.example/users/bob'], [], false],
		];
	}

	/**
	 * @dataProvider publicAudienceProvider
	 */
	public function testIsPublicLooksForThePublicCollectionInToOrCc(array $to, array $cc, bool $expected): void {
		$item = new ACore();
		$item->setToArray($to);
		$item->setCcArray($cc);

		$this->assertSame($expected, $item->isPublic());
	}

	public function testGenerateUniqueIdNeedsTheCloudUrl(): void {
		$this->expectException(UrlCloudException::class);

		(new ACore())->generateUniqueId('/documents');
	}

	public function testGenerateUniqueIdBuildsAnAbsoluteIdUnderTheBase(): void {
		$item = new ACore();
		$item->setUrlCloud('https://cloud.example.org');

		$item->generateUniqueId('documents/g/');

		$this->assertMatchesRegularExpression(
			'#^https://cloud\.example\.org/documents/g/' . self::UUID_PATTERN . '$#',
			$item->getId()
		);
	}

	public function testGenerateUniqueIdWithoutRootSkipsTheCloudUrl(): void {
		$item = new ACore();

		$item->generateUniqueId('/x', false);

		$this->assertMatchesRegularExpression('#^/x/' . self::UUID_PATTERN . '$#', $item->getId());
	}

	public function testCheckOriginAcceptsIdsFromTheRootOrigin(): void {
		$root = new ACore();
		$root->setOrigin('mastodon.social', 1, 0);
		$child = new Like($root);

		$child->checkOrigin('https://mastodon.social/users/alice/statuses/1');
		$this->assertSame('mastodon.social', $child->getRoot()->getOrigin());
	}

	public function invalidOriginProvider(): array {
		return [
			'other host' => ['https://evil.example/users/alice'],
			'no host' => ['not-a-url'],
			'empty' => [''],
		];
	}

	/**
	 * @dataProvider invalidOriginProvider
	 */
	public function testCheckOriginRejectsForeignIds(string $id): void {
		$item = new ACore();
		$item->setOrigin('mastodon.social', 1, 0);

		$this->expectException(InvalidOriginException::class);

		$item->checkOrigin($id);
	}

	public function testVerifyAcceptsSameSchemeHostAndPort(): void {
		$item = new ACore();
		$item->setId('https://mastodon.social:8443/users/alice');

		$item->verify('https://mastodon.social:8443/inbox');
		$this->assertSame('https://mastodon.social:8443/users/alice', $item->getId());
	}

	public function mismatchingUrlProvider(): array {
		return [
			'host' => ['https://evil.example/inbox'],
			'scheme' => ['http://mastodon.social/inbox'],
			'port' => ['https://mastodon.social:8443/inbox'],
		];
	}

	/**
	 * @dataProvider mismatchingUrlProvider
	 */
	public function testVerifyRejectsMismatchingUrls(string $url): void {
		$item = new ACore();
		$item->setId('https://mastodon.social/users/alice');

		$this->expectException(ActivityCantBeVerifiedException::class);

		$item->verify($url);
	}

	public function validEntryProvider(): array {
		return [
			'id is kept' => [ACore::AS_ID, 'https://a.example/x', 'https://a.example/x'],
			'type is kept' => [ACore::AS_TYPE, 'Note', 'Note'],
			'url is kept' => [ACore::AS_URL, 'https://a.example/@x', 'https://a.example/@x'],
			'date is kept' => [ACore::AS_DATE, '2024-05-01T12:00:00Z', '2024-05-01T12:00:00Z'],
			'string decodes entities before stripping tags' => [ACore::AS_STRING, '&lt;b&gt;bold&lt;/b&gt; text', 'bold text'],
			'content is sanitized not stripped' => [ACore::AS_CONTENT, '<p onclick="x">hi <b>there</b></p>', '<p>hi <b>there</b></p>'],
			'username drops tags' => [ACore::AS_USERNAME, '<b>alice</b>', 'alice'],
			'account drops tags' => [ACore::AS_ACCOUNT, 'alice@<i>a.example</i>', 'alice@a.example'],
		];
	}

	/**
	 * @dataProvider validEntryProvider
	 */
	public function testValidateEntryStringNormalisesByKind(int $as, string $value, string $expected): void {
		$this->assertSame($expected, (new ACore())->validateEntryString($as, $value));
	}

	public function testValidateEntryStringRejectsUnparsableIds(): void {
		$this->expectException(InvalidResourceEntryException::class);

		(new ACore())->validateEntryString(ACore::AS_ID, 'http:///broken');
	}

	public function testValidateEntryStringCanReturnEmptyInsteadOfThrowing(): void {
		$this->assertSame('', (new ACore())->validateEntryString(ACore::AS_ID, 'http:///broken', false));
		$this->assertSame('', (new ACore())->validateEntryString(99, 'anything', false));
	}

	public function testValidateFallsBackToTheDefault(): void {
		$item = new ACore();

		$this->assertSame('fallback', $item->validate(ACore::AS_ID, 'id', ['id' => 'http:///broken'], 'fallback'));
		$this->assertSame('fallback', $item->validate(ACore::AS_ID, 'id', [], 'fallback'));
		$this->assertSame('https://a.example/1', $item->validate(ACore::AS_ID, 'id', ['id' => 'https://a.example/1'], 'fallback'));
	}

	public function testValidateArrayKeepsOnlyValidEntries(): void {
		$item = new ACore();

		$result = $item->validateArray(ACore::AS_ID, 'to', [
			'to' => ['https://a.example/1', 'http:///broken', 'https://a.example/2'],
		]);

		$this->assertSame(['https://a.example/1', 'https://a.example/2'], $result);
		$this->assertSame(['dflt'], $item->validateArray(ACore::AS_ID, 'to', [], ['dflt']));
	}

	public function testValidateArrayNormalisesTagsToTypeHrefAndName(): void {
		$result = (new ACore())->validateArray(ACore::AS_TAGS, 'tag', [
			'tag' => [
				['type' => 'Mention', 'href' => 'https://a.example/users/bob', 'name' => '@bob', 'extra' => 'dropped'],
				['type' => 'Hashtag', 'name' => '#cats'],
			],
		]);

		$this->assertSame([
			['type' => 'Mention', 'href' => 'https://a.example/users/bob', 'name' => '@bob'],
			['type' => 'Hashtag', 'href' => '', 'name' => '#cats'],
		], $result);
	}

	/**
	 * An Emoji tag is nothing without its icon: the shortcode in the content
	 * is text, and the icon is the only thing that says what to draw instead.
	 * Keeping only type/href/name left every emoji — ours and every peer's —
	 * unrenderable the moment the post went through here.
	 */
	public function testAnEmojiTagKeepsThePictureItPointsAt(): void {
		$result = (new ACore())->validateArray(ACore::AS_TAGS, 'tag', [
			'tag' => [[
				'type' => 'Emoji',
				'name' => ':blobcat:',
				'icon' => [
					'type' => 'Image',
					'mediaType' => 'image/png',
					'url' => 'https://a.example/emoji/blobcat',
					'extra' => 'dropped',
				],
			]],
		]);

		$this->assertSame([[
			'type' => 'Emoji',
			'href' => '',
			'name' => ':blobcat:',
			'icon' => [
				'type' => 'Image',
				'mediaType' => 'image/png',
				'url' => 'https://a.example/emoji/blobcat',
			],
		]], $result);
	}

	/**
	 * @dataProvider provideIconsThatNameNoPicture
	 */
	public function testAnIconWithNoUsableAddressIsNotKept(array $icon): void {
		$result = (new ACore())->validateArray(ACore::AS_TAGS, 'tag', [
			'tag' => [['type' => 'Emoji', 'name' => ':blobcat:', 'icon' => $icon]],
		]);

		// an icon with no address is a broken image on every instance the post
		// reaches; the shortcode staying as text is the better of the two
		$this->assertArrayNotHasKey('icon', $result[0]);
	}

	public function provideIconsThatNameNoPicture(): iterable {
		yield 'no url' => [['type' => 'Image', 'mediaType' => 'image/png']];
		yield 'an empty url' => [['url' => '']];
		yield 'not a url' => [['url' => 'http:///broken']];
		yield 'nothing at all' => [[]];
	}

	/** A tag that is not an emoji has no icon, and does not gain an empty one. */
	public function testAMentionIsUnchangedByTheIconHandling(): void {
		$result = (new ACore())->validateArray(ACore::AS_TAGS, 'tag', [
			'tag' => [['type' => 'Mention', 'href' => 'https://a.example/users/bob', 'name' => '@bob']],
		]);

		$this->assertSame(
			[['type' => 'Mention', 'href' => 'https://a.example/users/bob', 'name' => '@bob']], $result
		);
	}

	public function testValidateEntryArrayOnlyKnowsTags(): void {
		$this->expectException(InvalidResourceEntryException::class);

		(new ACore())->validateEntryArray(ACore::AS_ID, ['id' => 'x']);
	}

	public function testImportReadsTheCoreActivityStreamsFields(): void {
		$item = new ACore();

		$item->import([
			'id' => 'https://mastodon.social/users/alice#likes/1',
			'type' => 'Like',
			'url' => 'https://mastodon.social/@alice/1',
			'summary' => 'a summary',
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => ['https://mastodon.social/users/alice/followers'],
			'published' => '2024-05-01T12:00:00Z',
			'actor' => 'https://mastodon.social/users/alice',
			'object' => 'https://cloud.example.org/@bob/1',
			'tag' => [['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/x', 'name' => '#x']],
		]);

		$this->assertSame('https://mastodon.social/users/alice#likes/1', $item->getId());
		$this->assertSame('Like', $item->getType());
		$this->assertSame('https://mastodon.social/@alice/1', $item->getUrl());
		$this->assertSame('a summary', $item->getSummary());
		$this->assertSame(['https://www.w3.org/ns/activitystreams#Public'], $item->getToArray());
		$this->assertSame(['https://mastodon.social/users/alice/followers'], $item->getCcArray());
		$this->assertSame('2024-05-01T12:00:00Z', $item->getPublished());
		$this->assertSame('https://mastodon.social/users/alice', $item->getActorId());
		$this->assertSame('https://cloud.example.org/@bob/1', $item->getObjectId());
		$this->assertSame([['type' => 'Hashtag', 'href' => 'https://mastodon.social/tags/x', 'name' => '#x']], $item->getTags());
	}

	/**
	 * `to`/`cc` are lists, but a single recipient is regularly sent as a bare
	 * string; read through getArray() the string was json-decoded to nothing, the
	 * post had no recipients at all, and NoteInterface filed it as a direct message.
	 */
	public function testImportAcceptsABareStringAsAOneElementRecipientList(): void {
		$item = new ACore();

		$item->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'to' => ACore::CONTEXT_PUBLIC,
			'cc' => 'https://mastodon.social/users/alice/followers',
		]);

		$this->assertSame([ACore::CONTEXT_PUBLIC], $item->getToArray());
		$this->assertSame(['https://mastodon.social/users/alice/followers'], $item->getCcArray());
	}

	public function testImportStillDropsRecipientsThatAreNotIds(): void {
		$item = new ACore();

		$item->import(['to' => 42, 'cc' => [['type' => 'Collection']]]);

		$this->assertSame([], $item->getToArray());
		$this->assertSame([], $item->getCcArray());
	}

	public function testImportIgnoresAnEmbeddedObjectForTheObjectId(): void {
		$item = new ACore();

		$item->import(['type' => 'Create', 'object' => ['type' => 'Note', 'id' => 'https://a.example/n/1']]);

		$this->assertSame('', $item->getObjectId());
	}

	public function testImportFromDatabaseReadsARowIncludingJsonColumns(): void {
		$item = new ACore();

		$item->importFromDatabase([
			'nid' => '42',
			'id' => 'https://a.example/n/1',
			'type' => 'Note',
			'subtype' => 'Mention',
			'url' => 'https://a.example/@alice/1',
			'summary' => '<b>cw</b>',
			'to' => 'https://a.example/users/bob',
			'to_array' => '["https://www.w3.org/ns/activitystreams#Public"]',
			'cc' => '["https://a.example/users/alice/followers"]',
			'bcc' => '[]',
			'published' => '2024-05-01T12:00:00Z',
			'actor_id' => 'https://a.example/users/alice',
			'object_id' => 'https://a.example/n/0',
			'source' => '{"raw":true}',
			'local' => 1,
		]);

		$this->assertSame(42, $item->getNid());
		$this->assertSame('Mention', $item->getSubType());
		$this->assertSame('cw', $item->getSummary());
		$this->assertSame('https://a.example/users/bob', $item->getTo());
		$this->assertSame(['https://www.w3.org/ns/activitystreams#Public'], $item->getToArray());
		$this->assertSame(['https://a.example/users/alice/followers'], $item->getCcArray());
		$this->assertSame([], $item->getBccArray());
		$this->assertSame('https://a.example/users/alice', $item->getActorId());
		$this->assertSame('https://a.example/n/0', $item->getObjectId());
		$this->assertSame('{"raw":true}', $item->getSource());
		$this->assertTrue($item->isLocal());
	}

	public function testImportFromLocalReadsTheNumericId(): void {
		$item = new ACore();

		$item->importFromLocal(['id' => '17']);

		$this->assertSame(17, $item->getNid());
	}

	/**
	 * `generateUniqueId('#accept/follows')` produced
	 * `https://cloud.example.com/#accept/follows/<uuid>`: everything after the
	 * `#` is a fragment, so that is the URL of the Nextcloud landing page, which
	 * answers 200 with an HTML document. Hung off the actor instead, the
	 * fragment sits on a URL that resolves to the actor — Mastodon's own shape.
	 */
	public function testAnActivityIdIsAFragmentOnTheActorNotOnTheCloudRoot(): void {
		$item = new Like();
		$item->setUrlCloud('https://cloud.example.com');

		$item->generateUniqueIdFromActor('https://cloud.example.com/apps/social/@alice', 'accept/follows');

		$this->assertStringStartsWith(
			'https://cloud.example.com/apps/social/@alice#accept/follows/', $item->getId()
		);
		$this->assertSame(
			'/apps/social/@alice', parse_url($item->getId(), PHP_URL_PATH),
			'the URL a peer dereferences is the actor, not the instance front page'
		);
	}

	public function testAnActivityIdFallsBackToTheCloudRootWithoutAnActor(): void {
		$item = new Like();
		$item->setUrlCloud('https://cloud.example.com');

		$item->generateUniqueIdFromActor('', 'accept/follows');

		$this->assertStringStartsWith('https://cloud.example.com/accept/follows/', $item->getId());
	}

	public function testExportAsActivityPubAddsTheContextOnlyOnTheRoot(): void {
		$root = new Like();
		$root->setId('https://a.example/l/1');
		$child = new Tombstone($root);
		$child->setId('https://a.example/n/1');

		$this->assertSame(
			[ACore::CONTEXT_ACTIVITYSTREAMS, ACore::CONTEXT_EXTENSIONS],
			$root->exportAsActivityPub()['@context']
		);
		$this->assertArrayNotHasKey('@context', $child->exportAsActivityPub());
	}

	public function testExportAsActivityPubAddsTheSecurityContextWhenAsked(): void {
		$item = new Like();
		$item->setDisplayW3ContextSecurity(true);

		$this->assertSame(
			[ACore::CONTEXT_ACTIVITYSTREAMS, ACore::CONTEXT_SECURITY, ACore::CONTEXT_EXTENSIONS],
			$item->exportAsActivityPub()['@context']
		);
	}

	public function testExportAsActivityPubEmbedsTheSignatureAndSecurityContext(): void {
		$item = new Like();
		$signature = new LinkedDataSignature();
		$item->setSignature($signature);

		$export = $item->exportAsActivityPub();

		$this->assertTrue($item->hasSignature());
		$this->assertSame($signature, $export['signature']);
		$this->assertContains(ACore::CONTEXT_SECURITY, $export['@context']);
	}

	public function testExportAsActivityPubUsesEmbeddedActorAndObjectAndDropsEmptyEntries(): void {
		$item = new Like();
		$item->setId('https://a.example/l/1');
		$actor = new Person();
		$actor->setId('https://a.example/users/alice');
		$item->setActor($actor);
		$note = new Tombstone();
		$note->setId('https://a.example/n/1');
		$item->setObject($note);
		$item->setToArray([ACore::CONTEXT_PUBLIC]);

		$export = $item->exportAsActivityPub();

		$this->assertSame('https://a.example/l/1', $export['id']);
		$this->assertSame('Like', $export['type']);
		$this->assertSame('https://a.example/users/alice', $export['actor']);
		$this->assertSame($note, $export['object']);
		$this->assertSame([ACore::CONTEXT_PUBLIC], $export['to']);
		foreach (['url', 'cc', 'summary', 'published', 'tag', 'actor_info', 'source', 'local', 'icon'] as $absent) {
			$this->assertArrayNotHasKey($absent, $export, $absent . ' should be dropped when empty');
		}
	}

	public function testExportAsActivityPubAddsDetailsOnlyWhenComplete(): void {
		$item = new Like();
		$item->setId('https://a.example/l/1');
		$actor = new Person();
		$actor->setId('https://a.example/users/alice');
		$item->setActor($actor);
		$item->setSource('{"raw":1}');
		$item->setLocal(true);
		$item->setCompleteDetails(true);

		$export = $item->exportAsActivityPub();

		$this->assertSame($actor, $export['actor_info']);
		$this->assertSame('{"raw":1}', $export['source']);
		$this->assertTrue($export['local']);
	}

	public function testExportAsActivityPubFallsBackToActorIdAndObjectId(): void {
		$item = new Like();
		$item->setActorId('https://a.example/users/alice');
		$item->setObjectId('https://a.example/n/1');

		$export = $item->exportAsActivityPub();

		$this->assertSame('https://a.example/users/alice', $export['actor']);
		$this->assertSame('https://a.example/n/1', $export['object']);
	}

	public function testIconBecomesAChildEntry(): void {
		$item = new ACore();
		$icon = new Document();
		$icon->setUrl('https://a.example/avatar.png');

		$item->setIcon($icon);

		$this->assertTrue($item->hasIcon());
		$this->assertSame($item, $icon->getParent());
		$this->assertSame($icon, $item->exportAsActivityPub()['icon']);
	}

	public function testJsonSerializeFollowsTheExportFormat(): void {
		$item = new Like();
		$item->setId('https://a.example/l/1');

		$this->assertSame(ACore::FORMAT_ACTIVITYPUB, $item->getExportFormat());
		$this->assertSame('Like', $item->jsonSerialize()['type']);

		$item->setExportFormat(ACore::FORMAT_LOCAL);
		$this->assertSame(['id' => 'https://a.example/l/1', 'nid' => 0], $item->jsonSerialize());

		$item->setNid(12);
		$this->assertSame(['id' => '12', 'nid' => 12], $item->jsonSerialize());

		$item->setExportFormat(ACore::FORMAT_NOTIFICATION);
		$this->assertSame(['id' => '12'], $item->jsonSerialize());

		$item->setExportFormat(0);
		$this->assertSame(ACore::FORMAT_NOTIFICATION, $item->getExportFormat());
	}

	public function testEntryHelpersSkipEmptyValues(): void {
		$item = new ACore();

		$item->addEntry('empty', '')
			->addEntryInt('zero', 0)
			->addEntryArray('none', [])
			->addEntryBool('no', false)
			->addEntry('str', 'value')
			->addEntryInt('int', 3)
			->addEntryArray('arr', ['a'])
			->addEntryBool('yes', true);

		$this->assertSame(['str' => 'value', 'int' => 3, 'arr' => ['a'], 'yes' => true], $item->getEntries());
	}
}
