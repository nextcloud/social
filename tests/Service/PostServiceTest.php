<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * PostService is exercised with a real StreamService (the Note it builds is
 * what matters) and a mocked ActivityService that records what it is handed.
 */
class PostServiceTest extends TestCase {
	private const SOCIAL_URL = 'https://social.example/';
	private const ACTOR_ID = 'https://social.example/@alice';
	private const ACTOR_FOLLOWERS = 'https://social.example/@alice/followers';
	private const GENERATED_ID = 'https://social.example/@alice/1234567890';
	private const BOB_ID = 'https://remote.example/users/bob';

	private StreamRequest|MockObject $streamRequest;
	private AccountService|MockObject $accountService;
	private ActivityService|MockObject $activityService;
	private CacheActorService|MockObject $cacheActorService;
	private PostService $service;

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);

		$configService = $this->createMock(ConfigService::class);
		$configService->method('generateId')->willReturn(self::GENERATED_ID);
		$configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);

		$streamService = new StreamService(
			$this->createMock(IURLGenerator::class),
			$this->streamRequest,
			$this->activityService,
			$this->cacheActorService,
			$configService,
			$this->createMock(CurlService::class),
			$this->createMock(LinkPreviewService::class),
			new NullLogger()
		);

		$this->service = new PostService(
			$streamService,
			$this->accountService,
			$this->activityService,
			new NullLogger()
		);
	}

	private function actor(): Person {
		$actor = new Person();
		$actor->setId(self::ACTOR_ID);
		$actor->setPreferredUsername('alice');
		$actor->setFollowers(self::ACTOR_FOLLOWERS);
		$actor->setLocal(true);

		return $actor;
	}

	private function bob(): Person {
		$bob = new Person();
		$bob->setId(self::BOB_ID);
		$bob->setPreferredUsername('bob');
		$bob->setAccount('bob@remote.example');
		$bob->setInbox(self::BOB_ID . '/inbox');
		$bob->setSharedInbox('https://remote.example/inbox');

		return $bob;
	}

	private function post(string $content, string $type = Stream::TYPE_PUBLIC): Post {
		$post = new Post($this->actor());
		$post->setContent($content);
		$post->setType($type);

		return $post;
	}

	/**
	 * Records the Note handed to ActivityService::createActivity() and hands
	 * back a Create wrapping it, the way the real service does.
	 */
	private function expectCreateActivity(?Note &$captured, string $token = 'token-1'): void {
		$this->activityService->expects($this->once())
			->method('createActivity')
			->willReturnCallback(function (Person $actor, ACore $item, ?ACore &$activity = null) use (&$captured, $token): string {
				$captured = $item;
				$activity = new Create();
				$activity->setId($item->getId() . '/activity');
				$activity->setObject($item);
				$activity->setActor($actor);

				return $token;
			});
	}

	// createPost()

	public function testCreatePostBuildsNoteAndWrapsItInCreateActivity(): void {
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example', true)->willReturn($this->bob());
		$this->accountService->expects($this->once())
			->method('cacheLocalActorDetailCount')
			->with($this->callback(fn (Person $p): bool => $p->getId() === self::ACTOR_ID));
		$this->expectCreateActivity($note);

		$token = '';
		$activity = $this->service->createPost(
			$this->post('Hello @bob@remote.example it\'s "great" #Nextcloud'), $token
		);

		$this->assertSame('token-1', $token);
		$this->assertInstanceOf(Create::class, $activity);
		$this->assertSame(self::GENERATED_ID . '/activity', $activity->getId());
		$this->assertSame($note, $activity->getObject());
		$this->assertSame(self::ACTOR_ID, $activity->getActor()->getId());

		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame(self::GENERATED_ID, $note->getId());
		$this->assertTrue($note->isLocal());
		$this->assertSame(self::ACTOR_ID, $note->getAttributedTo());
		$this->assertSame(Stream::TYPE_PUBLIC, $note->getVisibility());
		$this->assertSame('Hello @bob@remote.example it&#039;s &quot;great&quot; #Nextcloud', $note->getContent());
		$this->assertEqualsWithDelta(time(), (new DateTime($note->getPublished()))->getTimestamp(), 5);
		$this->assertSame(['Nextcloud'], $note->getHashtags());
		$this->assertSame(
			[
				['type' => 'Mention', 'href' => self::BOB_ID, 'name' => '@bob@remote.example'],
				['type' => 'Hashtag', 'href' => self::SOCIAL_URL . 'tag/nextcloud', 'name' => '#Nextcloud'],
			],
			$note->getTags()
		);
		$this->assertSame('', $note->getInReplyTo());
		$this->assertSame([], $note->getAttachments());
	}

	public function testCreatePostCarriesTheContentWarningAsTheSummary(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('who shot him');
		$post->setSpoilerText('season finale');
		$this->service->createPost($post);

		// AP calls it `summary`; the client API calls it `spoiler_text`
		$this->assertSame('season finale', $note->getSpoilerText());
		$this->assertSame('season finale', $note->getSummary());
	}

	public function testCreatePostEscapesTheContentWarning(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('body');
		$post->setSpoilerText('<script>alert("x")</script>');
		$this->service->createPost($post);

		$this->assertSame(
			'&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
			$note->getSpoilerText()
		);
	}

	public function testCreatePostWithoutAContentWarningLeavesTheSummaryEmpty(): void {
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('just words'));

		$this->assertSame('', $note->getSpoilerText());
	}

	/**
	 * @return array<string, array{string, string, string[], string[]}>
	 */
	public function visibilityProvider(): array {
		return [
			'public' => [Stream::TYPE_PUBLIC, ACore::CONTEXT_PUBLIC, [], [self::ACTOR_FOLLOWERS, self::BOB_ID]],
			'unlisted' => [Stream::TYPE_UNLISTED, self::ACTOR_FOLLOWERS, [], [ACore::CONTEXT_PUBLIC, self::BOB_ID]],
			'followers' => [Stream::TYPE_FOLLOWERS, self::ACTOR_FOLLOWERS, [], [self::BOB_ID]],
			'direct' => [Stream::TYPE_DIRECT, '', [self::BOB_ID], []],
		];
	}

	/**
	 * @dataProvider visibilityProvider
	 */
	public function testCreatePostAddressesMentionPerVisibility(
		string $type,
		string $expectedTo,
		array $expectedToArray,
		array $expectedCc,
	): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->bob());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('Hi @bob@remote.example', $type));

		$this->assertSame($type, $note->getVisibility());
		$this->assertSame($expectedTo, $note->getTo());
		$this->assertSame($expectedToArray, $note->getToArray());
		$this->assertSame($expectedCc, $note->getCcArray());
		$this->assertSame($type === Stream::TYPE_PUBLIC || $type === Stream::TYPE_UNLISTED, $note->isPublic());
		$this->assertCount(1, $note->getTags('Mention'));
	}

	public function testCreatePostDirectMessageFederatesOnlyToMentionedInboxes(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->bob());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('psst @bob@remote.example', Stream::TYPE_DIRECT));

		$paths = $note->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::BOB_ID . '/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_HIGH, $paths[0]->getPriority());
		$this->assertTrue($note->isFilterDuplicate());
	}

	public function testCreatePostPublicFederatesToFollowersAndMentionedInboxes(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->bob());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('hey @bob@remote.example'));

		$paths = $note->getInstancePaths();
		$this->assertCount(2, $paths);
		$this->assertSame(self::ACTOR_ID, $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_FOLLOWERS, $paths[0]->getType());
		$this->assertSame(self::BOB_ID . '/inbox', $paths[1]->getUri());
		$this->assertSame(InstancePath::PRIORITY_MEDIUM, $paths[1]->getPriority());
	}

	public function testCreatePostWithoutMentionsOrTagsNeedsNoLookup(): void {
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('just words'));

		$this->assertSame([], $note->getTags());
		$this->assertSame([], $note->getHashtags());
		$this->assertSame(ACore::CONTEXT_PUBLIC, $note->getTo());
		$this->assertSame([], $note->getToArray());
		$this->assertSame([self::ACTOR_FOLLOWERS], $note->getCcArray());
	}

	public function testCreatePostReplyWiresParentAndAddressesItsAuthor(): void {
		$parentId = 'https://remote.example/notes/parent';
		$parent = new Note();
		$parent->setId($parentId);
		$parent->setAttributedTo(self::BOB_ID);
		$this->streamRequest->method('getStreamById')->with($parentId)->willReturn($parent);
		$this->cacheActorService->method('getFromId')->with(self::BOB_ID)->willReturn($this->bob());
		$this->expectCreateActivity($note);

		$post = $this->post('I agree');
		$post->setReplyTo($parentId);
		$this->service->createPost($post);

		$this->assertSame($parentId, $note->getInReplyTo());
		$paths = $note->getInstancePaths();
		$this->assertCount(2, $paths);
		$this->assertSame('https://remote.example/inbox', $paths[1]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[1]->getType());
		$this->assertSame(InstancePath::PRIORITY_HIGH, $paths[1]->getPriority());
	}

	public function testCreatePostToUnknownReplyTargetFailsBeforeAnythingIsSent(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->activityService->expects($this->never())->method('createActivity');
		$this->accountService->expects($this->never())->method('cacheLocalActorDetailCount');

		$post = $this->post('I agree');
		$post->setReplyTo('https://remote.example/notes/missing');

		$this->expectException(StreamNotFoundException::class);
		$this->service->createPost($post);
	}

	public function testCreatePostToUnreachableReplyAuthorFails(): void {
		$parent = new Note();
		$parent->setId('https://remote.example/notes/parent');
		$parent->setAttributedTo(self::BOB_ID);
		$this->streamRequest->method('getStreamById')->willReturn($parent);
		$this->cacheActorService->method('getFromId')->willThrowException(new RequestNetworkException());
		$this->activityService->expects($this->never())->method('createActivity');

		$post = $this->post('I agree');
		$post->setReplyTo('https://remote.example/notes/parent');

		$this->expectException(RequestNetworkException::class);
		$this->service->createPost($post);
	}

	public function testCreatePostCarriesMediaAttachments(): void {
		$medias = [['id' => '1', 'type' => 'image', 'url' => 'https://social.example/media/1.png']];
		$this->expectCreateActivity($note);

		$post = $this->post('look');
		$post->setMedias($medias);
		$this->service->createPost($post);

		$this->assertSame($medias, $note->getAttachments());
	}

	public function testCreatePostMergesExplicitAndInlineRecipients(): void {
		$carol = new Person();
		$carol->setId('https://other.example/users/carol');
		$carol->setInbox('https://other.example/users/carol/inbox');
		$this->cacheActorService->method('getFromAccount')->willReturnMap([
			['carol@other.example', true, $carol],
			['bob@remote.example', true, $this->bob()],
		]);
		$this->expectCreateActivity($note);

		$post = $this->post('hi @bob@remote.example', Stream::TYPE_DIRECT);
		$post->addTo('carol@other.example');
		$this->service->createPost($post);

		$this->assertSame([$carol->getId(), self::BOB_ID], $note->getToArray());
	}

	// fixRecipientAndHashtags()

	/**
	 * @return array<string, array{string, string[], string[]}>
	 */
	public function inlineTokensProvider(): array {
		return [
			'mention and hashtag' => ['hi @bob@remote.example #Nextcloud', ['bob@remote.example'], ['Nextcloud']],
			'several of each, deduplicated' => [
				'@a@x.tld @b@y.tld @a@x.tld #one #two #one', ['a@x.tld', 'b@y.tld'], ['one', 'two'],
			],
			'email-like text is not a mention' => ['write to frank@example.org', [], []],
			'hash inside a word is not a tag' => ['issue#42 is fixed', [], []],
			'leading tokens' => ['#first @bob@remote.example', ['bob@remote.example'], ['first']],
			'nothing' => ['plain words', [], []],
		];
	}

	/**
	 * @dataProvider inlineTokensProvider
	 */
	public function testFixRecipientAndHashtagsExtractsInlineTokens(string $content, array $to, array $hashtags): void {
		$post = $this->post($content);

		$this->service->fixRecipientAndHashtags($post);

		$this->assertSame($to, $post->getTo());
		$this->assertSame($hashtags, $post->getHashtags());
	}

	public function testFixRecipientAndHashtagsKeepsExplicitRecipientsFirst(): void {
		$post = $this->post('hi @bob@remote.example #tag');
		$post->addTo('carol@other.example');
		$post->addHashtag('Existing');

		$this->service->fixRecipientAndHashtags($post);

		$this->assertSame(['carol@other.example', 'bob@remote.example'], $post->getTo());
		$this->assertSame(['Existing', 'tag'], $post->getHashtags());
	}

	public function testCreatePostEscapesHtmlAndTurnsNewlinesIntoBreaks(): void {
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post("line one\n<b>line two</b>"));

		$this->assertSame(
			"line one<br />\n&lt;b&gt;line two&lt;/b&gt;",
			$note->getContent(),
			'user text is escaped first, then newlines become <br />'
		);
	}

	public function testCreatePostWithAPollBuildsAQuestion(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('Cats or dogs?');
		$post->setPoll(['options' => ['Cats', 'Dogs', '  ', 'Birds', 'Fish', 'Too many'], 'expires_in' => 3600, 'multiple' => true]);
		$this->service->createPost($post);

		$this->assertInstanceOf(Question::class, $note);
		$this->assertTrue($note->isMultiple());
		$this->assertCount(4, $note->getOptions(), 'blank options dropped, capped at four');
		$this->assertSame('Cats', $note->getOptions()[0]['title']);
		$this->assertStringContainsString('anyOf', $note->getSource(), 'the poll snapshot lives in the source');
	}

	public function testCreatePostWithASingleOptionPollIsRefused(): void {
		$this->activityService->expects($this->never())->method('createActivity');

		$post = $this->post('broken');
		$post->setPoll(['options' => ['Only one'], 'expires_in' => 3600]);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->createPost($post);
	}

	// editPost()

	private function storedNote(string $attributedTo = self::ACTOR_ID): Note {
		$note = new Note();
		$note->setNid(7);
		$note->setId('https://social.example/@alice/7');
		$note->setAttributedTo($attributedTo);
		$note->setContent('old');
		$note->setSpoilerText('old cw');
		$note->setSensitive(false);
		$note->setPublished('2020-01-01T00:00:00+00:00');
		$note->setLocal(true);

		return $note;
	}

	public function testEditPostUpdatesOwnPostAndFederatesAnUpdate(): void {
		$stored = $this->storedNote();
		$reloaded = $this->storedNote();
		$reloaded->setContent('new');
		$this->streamRequest->expects($this->exactly(2))
			->method('getStreamByNid')
			->with(7)
			->willReturnOnConsecutiveCalls($stored, $reloaded);
		$this->streamRequest->expects($this->once())
			->method('update')
			->with($this->identicalTo($stored));
		$this->activityService->expects($this->once())
			->method('updateActivity')
			->with(
				$this->callback(fn (Person $p): bool => $p->getId() === self::ACTOR_ID),
				$this->identicalTo($reloaded)
			)
			->willReturn('token');

		$result = $this->service->editPost(7, $this->actor(), 'new', 'new cw', true);

		$this->assertSame($reloaded, $result);
		$this->assertSame('new', $stored->getContent());
		$this->assertSame('new cw', $stored->getSpoilerText());
		$this->assertTrue($stored->isSensitive());
		$this->assertEqualsWithDelta(time(), (new DateTime($stored->getPublished()))->getTimestamp(), 5);

		$paths = $reloaded->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::ACTOR_ID, $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_FOLLOWERS, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
	}

	public function testEditPostEscapesHtmlAndTurnsNewlinesIntoBreaks(): void {
		$stored = $this->storedNote();
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), "line one\n<script>x()</script>");

		$this->assertSame(
			"line one<br />\n&lt;script&gt;x()&lt;/script&gt;",
			$stored->getContent(),
			'edited text goes through the same escaping as new posts'
		);
	}

	public function testEditPostLeavesSpoilerAndSensitivityAloneWhenNotProvided(): void {
		$stored = $this->storedNote();
		$stored->setSensitive(true);
		$this->streamRequest->method('getStreamByNid')->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'new');

		$this->assertSame('new', $stored->getContent());
		$this->assertSame('old cw', $stored->getSpoilerText());
		$this->assertTrue($stored->isSensitive());
	}

	public function testEditPostRefusesSomeoneElsesPost(): void {
		$this->streamRequest->method('getStreamByNid')->willReturn($this->storedNote(self::BOB_ID));
		$this->streamRequest->expects($this->never())->method('update');
		$this->activityService->expects($this->never())->method('updateActivity');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Not authorized to edit this post');
		$this->service->editPost(7, $this->actor(), 'hijack');
	}

	public function testEditPostOfUnknownPostFails(): void {
		$this->streamRequest->method('getStreamByNid')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->never())->method('update');

		$this->expectException(StreamNotFoundException::class);
		$this->service->editPost(404, $this->actor(), 'new');
	}

	public function testEditPostKeepsLocalChangeWhenFederationFails(): void {
		$reloaded = $this->storedNote();
		$this->streamRequest->method('getStreamByNid')->willReturnOnConsecutiveCalls($this->storedNote(), $reloaded);
		$this->streamRequest->expects($this->once())->method('update');
		$this->activityService->method('updateActivity')->willThrowException(new \RuntimeException('remote down'));

		$this->assertSame($reloaded, $this->service->editPost(7, $this->actor(), 'new'));
	}
}
