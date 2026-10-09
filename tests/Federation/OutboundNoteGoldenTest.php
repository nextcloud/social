<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\EmojiRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\CustomEmoji;
use OCA\Social\Model\Post;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\LinkifyService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * Every kind of post this instance publishes, pinned whole as the `Create`
 * (or `Update`, `Delete`) the delivery queue is handed — the bytes that are
 * POSTed to each inbox. See GoldenFile; `UPDATE_GOLDEN=1` regenerates them.
 *
 * The post is written the way the client API writes one: a `Post` handed to
 * the real PostService, whose real StreamService addresses it, tags it and
 * links it, and whose real ActivityService wraps it, signs it and queues it.
 * Only the tables and the actor lookups are doubles.
 */
class OutboundNoteGoldenTest extends TestCase {
	use TActivityPubMocks;
	use TFederationWorld;

	private const BOB_POST = 'https://remote.example/users/bob/statuses/110000000000000001';

	private Person $alice;
	private StreamRequest $streamRequest;
	/** @var array<string, Stream> what PostService wrote, by id */
	private array $stored = [];
	private ?ActivityService $activities = null;

	protected function setUp(): void {
		$this->installActivityPub(self::$cloud);
		\OC::$server->register(IURLGenerator::class, $this->urls());
		$this->alice = $this->localActor();

		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->streamRequest->method('getStreamById')->willReturnCallback(
			fn (string $id): Stream => $this->stored[$id] ?? throw new StreamNotFoundException($id)
		);
		$this->streamRequest->method('getStreamByNid')->willReturnCallback(
			fn (): Stream => clone end($this->stored)
		);
		// what PostService saves through the Note interface is what an edit,
		// a quote or a delete reads back
		$this->apInterface(\OCA\Social\Interfaces\Object\NoteInterface::class)
			->method('save')->willReturnCallback(function (Stream $stream): void {
				$this->store($stream);
			});
		$this->streamRequest->method('update')->willReturnCallback(
			function (Stream $stream): void {
				$this->store($stream);
			}
		);
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	/** Keeps a copy the way a read from the table hands one back: with nothing exported on it yet. */
	private function store(Stream $stream): void {
		$copy = clone $stream;
		$copy->setEntries([]);
		$this->stored[$stream->getId()] = $copy;
	}

	private function activities(): ActivityService {
		$actors = $this->createStub(ActorsRequest::class);
		$actors->method('getFromId')->willReturnCallback(fn (): Person => $this->alice);

		return $this->activities ??= $this->activityService([ActorsRequest::class => $actors]);
	}

	private function streamService(): StreamService {
		$emojiRequest = $this->createStub(EmojiRequest::class);
		$emojiRequest->method('getAll')->willReturn([
			'blobcat' => new CustomEmoji('blobcat', 'blobcat.png', 'image/png'),
		]);

		$cacheActors = $this->createStub(CacheActorService::class);
		$cacheActors->method('getFromAccount')->willReturnCallback(
			fn (string $account): Person => ($account === 'bob@remote.example') ? $this->bob() : $this->alice
		);
		$cacheActors->method('getFromId')->willReturnCallback(
			fn (string $id): Person => ($id === self::$bob) ? $this->bob() : $this->alice
		);

		return $this->realService(StreamService::class, [
			IURLGenerator::class => $this->urls(),
			StreamRequest::class => $this->streamRequest,
			ActivityService::class => $this->activities(),
			CacheActorService::class => $cacheActors,
			ConfigService::class => $this->config(),
			EmojiService::class => $this->realService(EmojiService::class, [
				EmojiRequest::class => $emojiRequest,
				IURLGenerator::class => $this->urls(),
				'logger' => new NullLogger(),
			]),
			'logger' => new NullLogger(),
		]);
	}

	private function postService(): PostService {
		$l10n = $this->createStub(IFactory::class);
		$l10n->method('getUserLanguage')->willReturn('en');

		return $this->realService(PostService::class, [
			StreamService::class => $this->streamService(),
			ActivityService::class => $this->activities(),
			IFactory::class => $l10n,
			LinkifyService::class => new LinkifyService(),
			ConfigService::class => $this->config(),
			'logger' => new NullLogger(),
		]);
	}

	/** A public post of Bob's, as it is stored here once it arrived. */
	private function storeBobsPost(): Note {
		/** @var Note $note */
		$note = AP::instance()->getItemFromData([
			'id' => self::BOB_POST,
			'type' => 'Note',
			'attributedTo' => self::$bob,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [self::$bob . '/followers'],
			'content' => '<p>what are you growing this year?</p>',
			'published' => '2026-03-01T09:00:00Z',
		]);
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$this->store($note);

		return $note;
	}

	private function post(string $content, string $type = Stream::TYPE_PUBLIC): Post {
		$post = new Post($this->alice);
		$post->setContent($content);
		$post->setType($type);
		$post->setLanguage('en');

		return $post;
	}

	/** Writes the post and returns the activity that was queued for it. */
	private function publish(Post $post): array {
		$this->postService()->createPost($post);

		return $this->lastQueuedOf('Create');
	}

	/** The last queued activity of a type. */
	private function lastQueuedOf(string $type): array {
		$found = null;
		foreach ($this->queued as $queued) {
			$decoded = json_decode($queued, true);
			if (($decoded['type'] ?? '') === $type) {
				$found = $decoded;
			}
		}
		$this->assertIsArray($found, 'no ' . $type . ' was queued');

		return $found;
	}

	/** @return iterable<string, array{string, string}> */
	public static function visibilities(): iterable {
		yield 'public' => [Stream::TYPE_PUBLIC, 'create-note-public'];
		yield 'unlisted' => [Stream::TYPE_UNLISTED, 'create-note-unlisted'];
		yield 'followers only' => [Stream::TYPE_FOLLOWERS, 'create-note-followers'];
	}

	#[DataProvider('visibilities')]
	public function testAPostInEachVisibility(string $visibility, string $golden): void {
		GoldenFile::assertMatches($golden, $this->publish($this->post('Tomatoes are up!', $visibility)));
	}

	public function testADirectMessageIsAddressedToTheOneItNames(): void {
		$post = $this->post('@bob@remote.example are you coming tomorrow?', Stream::TYPE_DIRECT);
		$post->addTo('bob@remote.example');

		GoldenFile::assertMatches('create-note-direct', $this->publish($post));
	}

	public function testAContentWarningAndSensitiveMedia(): void {
		$post = $this->post('The slugs got everything.');
		$post->setSpoilerText('garden disaster');
		$post->setSensitive(true);

		GoldenFile::assertMatches('create-note-cw-sensitive', $this->publish($post));
	}

	public function testAReplyNamesItsParentAndItsAuthor(): void {
		$this->storeBobsPost();
		$post = $this->post('Beans, mostly.');
		$post->setReplyTo(self::BOB_POST);

		GoldenFile::assertMatches('create-note-reply', $this->publish($post));
	}

	public function testAMentionAHashtagAndACustomEmojiAreTagged(): void {
		$post = $this->post('@bob@remote.example look at this #Gardening :blobcat:');
		$post->addTo('bob@remote.example');
		$post->addHashtag('Gardening');

		GoldenFile::assertMatches('create-note-mention-hashtag-emoji', $this->publish($post));
	}

	public function testMediaCarriesItsBlurhashFocalPointAndSize(): void {
		$post = $this->post('Two from the allotment');
		$post->setMedias([
			(new MediaAttachment())->import([
				'id' => '11', 'type' => 'image', 'media_type' => 'image/jpeg',
				'url' => self::$social . 'media/first.jpg', 'description' => 'a row of seedlings',
				'blurhash' => 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
				'meta' => ['original' => ['width' => 1600, 'height' => 1200], 'focus' => ['x' => -0.5, 'y' => 0.25]],
			]),
			(new MediaAttachment())->import([
				'id' => '12', 'type' => 'image', 'media_type' => 'image/png',
				'url' => self::$social . 'media/second.png', 'description' => '',
				'meta' => ['original' => ['width' => 800, 'height' => 600]],
			]),
		]);

		GoldenFile::assertMatches('create-note-media', $this->publish($post));
	}

	public function testALanguageGoesOutAsContentAndSummaryMaps(): void {
		$post = $this->post('Die Tomaten sind da.');
		$post->setLanguage('de');
		$post->setSpoilerText('Garten');

		GoldenFile::assertMatches('create-note-contentmap', $this->publish($post));
	}

	public function testAnEditGoesOutAsAnUpdateOfTheWholeNote(): void {
		$this->publish($this->post('Tomatos are up!'));

		$this->postService()->editPost('1', $this->alice, 'Tomatoes are up!');

		GoldenFile::assertMatches('update-note-edited', $this->lastQueuedOf('Update'));
	}

	public function testADeletedPostGoesOutAsADeleteOfATombstone(): void {
		$this->publish($this->post('Never mind.'));
		$note = end($this->stored);
		$this->assertInstanceOf(Note::class, $note);

		$this->streamService()->deleteLocalItem($note, Note::TYPE);

		GoldenFile::assertMatches('delete-note-tombstone', $this->lastQueuedOf('Delete'));
	}

	/** @return iterable<string, array{bool, string}> */
	public static function polls(): iterable {
		yield 'single choice' => [false, 'create-question-oneof'];
		yield 'multiple choice' => [true, 'create-question-anyof'];
	}

	#[DataProvider('polls')]
	public function testAPollIsAQuestion(bool $multiple, string $golden): void {
		$post = $this->post('What should I plant next?');
		$post->setPoll(['options' => ['Beans', 'Peas', 'Squash'], 'multiple' => $multiple, 'expires_in' => 86400]);

		GoldenFile::assertMatches($golden, $this->publish($post));
	}

	/**
	 * A poll's counts move as votes arrive, and every move goes to the
	 * followers as an Update of the Question — the same document a closed
	 * poll is re-exported as, `closed` and all.
	 */
	public function testAClosedPollIsAQuestionThatSaysWhenItClosed(): void {
		$post = $this->post('Which went better?');
		$post->setPoll(['options' => ['Beans', 'Peas'], 'multiple' => false, 'expires_in' => 300]);
		$this->publish($post);

		/** @var Question $poll */
		$poll = end($this->stored);
		$this->assertInstanceOf(Question::class, $poll);
		$wire = GoldenFile::wire($poll);
		$wire['closed'] = '2026-04-01T10:00:00Z';
		$wire['endTime'] = '2026-04-01T10:00:00Z';
		$wire['oneOf'][0]['replies']['totalItems'] = 3;
		$wire['votersCount'] = 3;

		$closed = new Question();
		$closed->importFromDatabase([
			'id' => $poll->getId(), 'type' => 'Question', 'local' => '1',
			'attributed_to' => $poll->getAttributedTo(), 'content' => $poll->getContent(),
			'published' => '2026-03-31T10:00:00Z', 'to' => $poll->getTo(),
			'cc' => json_encode($poll->getCcArray()), 'visibility' => Stream::TYPE_PUBLIC,
			'language' => 'en', 'source' => json_encode($wire),
		]);

		$this->activities()->updateActivity($this->alice, $closed);

		GoldenFile::assertMatches('update-question-closed', $this->lastQueuedOf('Update'));
	}

	/** A vote on somebody else's poll is a bare Note naming the option, sent to the poll's author. */
	public function testAVoteOnARemotePollIsANoteNamingTheOption(): void {
		$poll = AP::instance()->getItemFromData([
			'id' => self::BOB_POST, 'type' => 'Question', 'attributedTo' => self::$bob,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'content' => '<p>beans or peas?</p>', 'published' => '2026-03-01T09:00:00Z',
			'endTime' => '2099-01-01T00:00:00Z',
			'oneOf' => [
				['type' => 'Note', 'name' => 'Beans', 'replies' => ['type' => 'Collection', 'totalItems' => 0]],
				['type' => 'Note', 'name' => 'Peas', 'replies' => ['type' => 'Collection', 'totalItems' => 0]],
			],
		]);
		$this->streamRequest->method('getStreamByNid')->willReturn($poll);

		$cacheActors = $this->createStub(CacheActorService::class);
		$cacheActors->method('getFromId')->willReturn($this->bob());
		$service = $this->realService(PollService::class, [
			StreamRequest::class => $this->streamRequest,
			CacheActorService::class => $cacheActors,
			ActivityService::class => $this->activities(),
			\OCA\Social\Service\SignatureService::class => $this->signatureService(),
			ConfigService::class => $this->config(),
			'logger' => new NullLogger(),
		]);
		$this->store($poll);

		$service->vote($this->alice, '1', [1]);

		GoldenFile::assertMatches('create-note-poll-vote', $this->lastQueuedOf('Create'));
	}

	/**
	 * A quote of a remote post carries FEP-044f's `quote` and the two aliases
	 * older readers look for, and asks the quoted author's server for an
	 * approval with a `QuoteRequest`.
	 */
	public function testAQuoteOfARemotePostAndTheRequestToApproveIt(): void {
		$this->storeBobsPost();
		$post = $this->post('This, exactly.');
		$post->setQuotedId(self::BOB_POST);

		GoldenFile::assertMatches('create-note-quote', $this->publish($post));
		GoldenFile::assertMatches('quote-request', $this->lastQueuedOf('QuoteRequest'));
	}

	/** A quote of a local post is approved here and then, and says by what. */
	public function testAQuoteOfALocalPostCarriesItsAuthorization(): void {
		$this->publish($this->post('Original thought.'));
		$quoted = end($this->stored);
		$this->assertInstanceOf(Note::class, $quoted);
		$this->queued = [];

		$post = $this->post('Quoting myself.');
		$post->setQuotedId($quoted->getId());

		GoldenFile::assertMatches('create-note-quote-local', $this->publish($post));
	}

	/**
	 * A post that is a single video goes out as a `Video` once an instance
	 * publishes video objects, filed under the author's channel.
	 */
	public function testASingleVideoGoesOutAsAVideo(): void {
		$this->appValues[ConfigService::SOCIAL_PUBLISH_VIDEO] = '1';
		\OC::$server->register(ConfigService::class, $this->config());
		$channels = $this->createStub(ChannelService::class);
		$channels->method('attributionOf')->willReturn([
			['type' => 'Person', 'id' => $this->alice->getId()],
			['type' => 'Group', 'id' => self::$social . '@alice_videos'],
		]);
		\OC::$server->register(ChannelService::class, $channels);

		$post = $this->post('First harvest, in motion');
		$post->setMedias([
			(new MediaAttachment())->import([
				'id' => '21', 'type' => 'video', 'media_type' => 'video/mp4',
				'url' => self::$social . 'media/harvest.mp4', 'description' => 'picking beans',
				'preview_url' => self::$social . 'media/harvest.jpg',
				'meta' => ['original' => ['width' => 1280, 'height' => 720], 'duration' => 12],
			]),
		]);

		GoldenFile::assertMatches('create-video', $this->publish($post));
	}
}
