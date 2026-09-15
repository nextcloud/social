<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\LinkifyService;
use OCA\Social\Service\PostImportService;
use OCA\Social\Service\StreamService;
use OCP\ITempManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

class PostImportServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';

	private ImportedPostsRequest|MockObject $importedPostsRequest;
	private StreamRequest|MockObject $streamRequest;
	private DocumentService|MockObject $documentService;
	private CacheDocumentService|MockObject $cacheDocumentService;
	private AccountService|MockObject $accountService;
	private PostImportService $service;

	/** @var Note[] what the run wrote */
	private array $written = [];
	/** @var array<string, string> what it remembered */
	private array $remembered = [];
	/** @var string[] the temporary files it made */
	private array $temps = [];

	protected function setUp(): void {
		parent::setUp();

		$this->importedPostsRequest = $this->createMock(ImportedPostsRequest::class);
		$this->importedPostsRequest->method('knownAmong')->willReturn([]);
		$this->importedPostsRequest->method('remember')
			->willReturnCallback(function (string $actor, string $source, string $stream): void {
				$this->remembered[$source] = $stream;
			});

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamRequest->method('save')
			->willReturnCallback(function (Stream $stream): void {
				$this->written[] = $stream;
			});
		$this->streamRequest->method('getStream')
			->willReturnCallback(function (string $prim): Stream {
				foreach ($this->written as $stream) {
					if (md5($stream->getId()) === $prim) {
						return $stream;
					}
				}

				throw new \OCA\Social\Exceptions\StreamNotFoundException();
			});

		// the real one mints an id and sets the addressing; what matters here
		// is that the importer asks for it and then overrides the date
		$streamService = $this->createMock(StreamService::class);
		$streamService->method('assignItem')
			->willReturnCallback(static function (Stream $stream): void {
				$stream->setId(self::ALICE . '/' . bin2hex(random_bytes(6)));
				$stream->setLocal(true);
				$stream->setPublished(date('c'));
			});
		$streamService->method('addHashtags')
			->willReturnCallback(static function (Note $note, array $tags): void {
				$note->setHashtags($tags);
			});

		$this->documentService = $this->createMock(DocumentService::class);
		$this->documentService->method('storeLocalAttachment')
			->willReturnCallback(static function (Person $actor, string $path, string $parent, string $description): Document {
				$document = new Document();
				$document->setId('https://cloud.example/documents/' . md5($path . $description));
				$document->setDescription($description);
				$document->setMediaType('image/jpeg');

				return $document;
			});

		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);

		$linkify = $this->createMock(LinkifyService::class);
		$linkify->method('toHtml')->willReturnCallback(static fn (string $text): string => '<p>' . $text . '</p>');

		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = tempnam(sys_get_temp_dir(), 'import');
			$this->temps[] = $path;

			return $path;
		});

		// an Instagram post carries no audience, so the importer asks the
		// account what its own posts get
		$this->accountService = $this->createMock(AccountService::class);

		$this->service = new PostImportService(
			$this->importedPostsRequest,
			$this->streamRequest,
			$streamService,
			$this->documentService,
			$this->cacheDocumentService,
			$linkify,
			$this->accountService,
			$tempManager,
			$this->createMock(IURLGenerator::class),
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		foreach ($this->temps as $temp) {
			@unlink($temp);
		}
		parent::tearDown();
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE)
			->setPreferredUsername('alice')
			->setAccount('alice@cloud.example');
		$actor->setFollowers(self::ALICE . '/followers');

		return $actor;
	}

	/** @param array<int, mixed> $items */
	private function outbox(array $items): string {
		$path = tempnam(sys_get_temp_dir(), 'outbox') . '.json';
		file_put_contents($path, json_encode([
			'type' => 'OrderedCollection',
			'totalItems' => count($items),
			'orderedItems' => $items,
		]));
		$this->temps[] = $path;

		return $path;
	}

	/** @param array<string, mixed> $values */
	private function note(string $id, array $values = []): array {
		return array_merge([
			'id' => $id,
			'type' => 'Note',
			'published' => '2024-03-17T09:00:00Z',
			'content' => '<p>Hello <b>world</b></p>',
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [self::ALICE . '/followers'],
		], $values);
	}

	public function testItWritesEachPostAsALocalPostDatedWhenItWasWritten(): void {
		$path = $this->outbox([
			$this->note('https://old.example/users/alice/statuses/1'),
			$this->note('https://old.example/users/alice/statuses/2', [
				'published' => '2025-01-02T10:30:00Z',
				'content' => '<p>Second</p>',
			]),
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(2, $tally['imported']);
		$this->assertCount(2, $this->written);
		// oldest first, so that a reply is written after what it answers
		$this->assertSame(strtotime('2024-03-17T09:00:00Z'), $this->written[0]->getPublishedTime());
		$this->assertSame(strtotime('2025-01-02T10:30:00Z'), $this->written[1]->getPublishedTime());
		// and as this account's own posts, with ids of this server's minting
		$this->assertTrue($this->written[0]->isLocal());
		$this->assertStringStartsWith(self::ALICE, $this->written[0]->getId());
		$this->assertSame(self::ALICE, $this->written[0]->getAttributedTo());
	}

	/** The whole point of not federating: nothing is queued, ever. */
	public function testNotOneDeliveryIsQueued(): void {
		$path = $this->outbox([$this->note('https://old.example/1')]);

		$this->service->import($this->alice(), $path);

		// the importer writes through the row, not through the delivery path;
		// `save()` is the only write it makes
		$this->assertCount(1, $this->written);
		$this->assertSame(['https://old.example/1'], array_keys($this->remembered));
	}

	public function testTheMarkupBecomesTheTextAPostIsStoredAs(): void {
		$path = $this->outbox([$this->note('https://old.example/1', [
			'content' => '<p>First line<br />second line</p><p>New paragraph &amp; an entity</p>',
		])]);

		$this->service->import($this->alice(), $path);

		$this->assertSame(
			"<p>First line\nsecond line\n\nNew paragraph & an entity</p>",
			$this->written[0]->getContent()
		);
	}

	public function testABoostAndADirectMessageAreNotPostsToBringOver(): void {
		$path = $this->outbox([
			['type' => 'Announce', 'id' => 'https://old.example/1', 'object' => 'https://elsewhere/2'],
			$this->note('https://old.example/3', ['to' => ['https://old.example/users/bob'], 'cc' => []]),
			$this->note('https://old.example/4'),
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame(2, $tally['skipped']);
		$this->assertSame(['https://old.example/4'], array_keys($this->remembered));
	}

	public function testTheAudienceIsReadOffTheAddressing(): void {
		$path = $this->outbox([
			$this->note('https://old.example/1'),
			$this->note('https://old.example/2', [
				'to' => [self::ALICE . '/followers'],
				'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
			]),
			$this->note('https://old.example/3', ['to' => [self::ALICE . '/followers'], 'cc' => []]),
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame(
			[Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED, Stream::TYPE_FOLLOWERS],
			array_map(static fn (Stream $post): string => $post->getVisibility(), $this->written)
		);
	}

	/** A Create wrapping a Note is an outbox; a bare Note is this app's own export. */
	public function testItReadsAnOutboxOfActivitiesAsWellAsOneOfObjects(): void {
		$path = $this->outbox([
			['type' => 'Create', 'id' => 'https://old.example/1/activity', 'object' => $this->note('https://old.example/1')],
			$this->note('https://old.example/2'),
		]);

		$this->assertSame(2, $this->service->import($this->alice(), $path)['imported']);
	}

	public function testAReplyKeepsItsParentWhereTheExportHoldsBoth(): void {
		$path = $this->outbox([
			$this->note('https://old.example/2', [
				'published' => '2024-03-18T09:00:00Z',
				'inReplyTo' => 'https://old.example/1',
				'content' => '<p>and another thing</p>',
			]),
			$this->note('https://old.example/1'),
			$this->note('https://old.example/3', [
				'published' => '2024-03-19T09:00:00Z',
				// a reply to a post that is not in the archive: a fragment of
				// somebody else's thread, kept as a post of its own
				'inReplyTo' => 'https://elsewhere.example/9',
			]),
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame('', $this->written[0]->getInReplyTo());
		$this->assertSame($this->written[0]->getId(), $this->written[1]->getInReplyTo());
		$this->assertSame('', $this->written[2]->getInReplyTo());
	}

	public function testWhatWasBroughtOverAlreadyIsNotBroughtAgain(): void {
		$service = $this->service;
		$this->importedPostsRequest = $this->createMock(ImportedPostsRequest::class);

		$path = $this->outbox([$this->note('https://old.example/1'), $this->note('https://old.example/2')]);
		$service->import($this->alice(), $path);
		$this->assertCount(2, $this->written);

		// a second run, with the first run's rows now on record
		$again = $this->serviceKnowing(['https://old.example/1' => md5('local/1'), 'https://old.example/2' => md5('local/2')]);
		$tally = $again->import($this->alice(), $path);

		$this->assertSame(0, $tally['imported']);
		$this->assertSame(2, $tally['already']);
	}

	public function testAPictureInTheArchiveIsStoredThroughTheOrdinaryUploadPath(): void {
		$zipPath = tempnam(sys_get_temp_dir(), 'archive') . '.zip';
		$this->temps[] = $zipPath;
		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE);
		$zip->addFromString('social/outbox.json', json_encode(['orderedItems' => [
			$this->note('https://old.example/1', [
				'attachment' => [[
					'type' => 'Document',
					'mediaType' => 'image/jpeg',
					'url' => 'media_attachments/files/1/original.jpeg',
					'name' => 'a pier at low tide',
				]],
			]),
		]]));
		$zip->addFromString('social/media_attachments/files/1/original.jpeg', 'not really a jpeg');
		$zip->close();

		$this->documentService->expects($this->once())
			->method('storeLocalAttachment')
			->with(
				$this->anything(),
				$this->callback(static fn (string $path): bool => file_get_contents($path) === 'not really a jpeg'),
				$this->anything(),
				'a pier at low tide',
				true
			)
			->willReturn((new Document())->setId('https://cloud.example/documents/1'));

		$tally = $this->service->import($this->alice(), $zipPath);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame(1, $tally['media']);
		$this->assertCount(1, $this->written[0]->getAttachments());
	}

	/**
	 * Pixelfed's export names its pictures by address and ships none of them,
	 * so they are fetched — and only when the person asked for that.
	 */
	public function testAPictureNamedOnlyByItsAddressIsFetchedOnlyWhenAsked(): void {
		$path = tempnam(sys_get_temp_dir(), 'pixelfed') . '.json';
		$this->temps[] = $path;
		file_put_contents($path, json_encode([[
			'id' => '712000000000000031',
			'url' => 'https://pixelfed.social/p/alice/712000000000000031',
			'created_at' => '2025-06-01T12:00:00Z',
			'content' => '<p>a pier</p>',
			'visibility' => 'public',
			'media_attachments' => [['url' => 'https://pixelfed.social/storage/m/one.jpg', 'description' => 'a pier']],
		]]));

		$this->cacheDocumentService->expects($this->once())
			->method('retrieveContent')
			->with('https://pixelfed.social/storage/m/one.jpg')
			->willReturn('the bytes');

		$tally = $this->service->import($this->alice(), $path, true);
		$this->assertSame(1, $tally['media']);

		// and with the fetch declined, the post is still written — without it
		$quiet = $this->serviceKnowing([]);
		$this->written = [];
		$tally = $quiet->import($this->alice(), $path, false);
		$this->assertSame(1, $tally['imported']);
		$this->assertSame(0, $tally['media']);
		$this->assertSame([], $this->written[0]->getAttachments());
	}

	public function testAFileThatIsNotAnExportSaysSo(): void {
		$path = tempnam(sys_get_temp_dir(), 'junk');
		$this->temps[] = $path;
		file_put_contents($path, 'not json at all');

		$this->expectException(InvalidResourceException::class);
		$this->service->import($this->alice(), $path);
	}

	public function testTheRunStopsAtItsCeilingAndSaysSo(): void {
		$items = [];
		for ($index = 1; $index <= 5; $index++) {
			$items[] = $this->note('https://old.example/' . $index);
		}
		$path = $this->outbox($items);

		$tally = $this->service->import($this->alice(), $path, true, 3);

		$this->assertSame(3, $tally['imported']);
		$this->assertTrue($tally['capped']);
	}

	// Instagram

	/**
	 * Instagram's own layout: the JSON under `your_instagram_activity/content/`
	 * and the pictures under `media/`, named from the archive root.
	 *
	 * @param array<string, string> $files path => contents
	 */
	private function archive(array $files): string {
		$path = tempnam(sys_get_temp_dir(), 'instagram') . '.zip';
		$this->temps[] = $path;
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		foreach ($files as $name => $contents) {
			$zip->addFromString($name, $contents);
		}
		$zip->close();

		return $path;
	}

	/**
	 * One post of an Instagram archive.
	 *
	 * @param array<int, array<string, mixed>> $media
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function instagramPost(array $media, array $values = []): array {
		return array_merge(['media' => $media, 'creation_timestamp' => 1719662400], $values);
	}

	public function testAnInstagramArchiveIsReadAndItsPicturesComeWithIt(): void {
		$path = $this->archive([
			'your_instagram_activity/content/posts_1.json' => json_encode([
				$this->instagramPost(
					[['uri' => 'media/posts/202406/pier.jpg', 'creation_timestamp' => 1719662400]],
					['title' => 'a pier at low tide']
				),
			]),
			'media/posts/202406/pier.jpg' => 'not really a jpeg',
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame(1, $tally['media']);
		$this->assertSame('<p>a pier at low tide</p>', $this->written[0]->getContent());
		$this->assertSame(1719662400, $this->written[0]->getPublishedTime());
	}

	/**
	 * The export splits at a size nobody can predict, so an archive of ten
	 * years has a dozen of these and every one of them holds posts.
	 */
	public function testEveryNumberedPostsFileIsRead(): void {
		$path = $this->archive([
			'your_instagram_activity/content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => 'first']),
			]),
			'your_instagram_activity/content/posts_2.json' => json_encode([
				$this->instagramPost([['uri' => 'media/b.jpg']], ['title' => 'second']),
			]),
			'media/a.jpg' => 'a',
			'media/b.jpg' => 'b',
		]);

		$this->assertSame(2, $this->service->import($this->alice(), $path)['imported']);
	}

	/** An archive taken before Instagram moved the folder still imports. */
	public function testTheOlderLayoutIsReadToo(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/old.jpg']], ['title' => 'from before']),
			]),
			'media/old.jpg' => 'a',
		]);

		$this->assertSame(1, $this->service->import($this->alice(), $path)['imported']);
	}

	public function testReelsAreReadFromTheKeyTheyAreWrappedIn(): void {
		$path = $this->archive([
			'your_instagram_activity/content/reels.json' => json_encode([
				'ig_reels_media' => [
					$this->instagramPost([['uri' => 'media/reel.mp4']], ['title' => 'a reel']),
				],
			]),
			'media/reel.mp4' => 'not really a video',
		]);

		$this->assertSame(1, $this->service->import($this->alice(), $path)['imported']);
	}

	/**
	 * A day of somebody's life that was meant to end, what they took down on
	 * purpose, and what they deleted. An importer that republished any of the
	 * three would be worse than one that imported nothing.
	 */
	public function testStoriesArchivedAndDeletedPostsAreLeftAlone(): void {
		$path = $this->archive([
			'your_instagram_activity/content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/kept.jpg']], ['title' => 'kept']),
			]),
			'your_instagram_activity/content/stories.json' => json_encode([
				'ig_stories' => [$this->instagramPost([['uri' => 'media/story.jpg']], ['title' => 'a story'])],
			]),
			'your_instagram_activity/content/archived_posts.json' => json_encode([
				'ig_archived_post_media' => [$this->instagramPost([['uri' => 'media/hidden.jpg']])],
			]),
			'your_instagram_activity/content/recently_deleted_content.json' => json_encode([
				'ig_recently_deleted_media' => [$this->instagramPost([['uri' => 'media/gone.jpg']])],
			]),
			'media/kept.jpg' => 'a',
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame('<p>kept</p>', $this->written[0]->getContent());
	}

	/**
	 * A post of several pictures carries its caption at the top; a post of one
	 * carries it on the picture. Both are read, and the top one wins.
	 */
	public function testTheCaptionIsFoundInBothPlacesItIsWritten(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([
					['uri' => 'media/one.jpg', 'title' => 'the caption of a single picture'],
				]),
				$this->instagramPost(
					[['uri' => 'media/two.jpg', 'title' => 'not this one'], ['uri' => 'media/three.jpg']],
					['title' => 'the caption of a carousel']
				),
			]),
			'media/one.jpg' => 'a', 'media/two.jpg' => 'b', 'media/three.jpg' => 'c',
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame('<p>the caption of a single picture</p>', $this->written[0]->getContent());
		$this->assertSame('<p>the caption of a carousel</p>', $this->written[1]->getContent());
	}

	/**
	 * Every caption in the archive is mojibake: the exporter writes UTF-8 and
	 * escapes each byte as if it were a character. Read back, `né` arrives as
	 * `nÃ©` and an emoji as four accented letters.
	 */
	public function testInstagramsMangledEncodingIsUndone(): void {
		$mangled = json_decode('"CafÃ© ð"');
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => $mangled]),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame('<p>Café 😊</p>', $this->written[0]->getContent());
	}

	/** A caption somebody genuinely wrote with an accent in it is left alone. */
	public function testTextThatIsAlreadyRightIsNotConvertedTwice(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => 'Trüffel']),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->service->import($this->alice(), $path);

		$this->assertSame('<p>Trüffel</p>', $this->written[0]->getContent());
	}

	/**
	 * On Instagram a hashtag is a word in the caption and nothing else, so
	 * unless they are read out of it an imported post is findable by none of
	 * the tags its author chose.
	 */
	public function testHashtagsAreReadOutOfTheCaption(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost(
					[['uri' => 'media/a.jpg']],
					['title' => "low tide at the pier\n#seaside #Devon #2024 hello@example.com"]
				),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->service->import($this->alice(), $path);

		// not the bare number, and not the address
		$this->assertSame(['seaside', 'Devon'], $this->written[0]->getHashtags());
	}

	/**
	 * An Instagram post says nothing about who could see it, so the importing
	 * account's own default is used — what their next post would get.
	 */
	public function testThePostsTakeTheAccountsOwnDefaultVisibility(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => 'quiet']),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->accountService->method('getDefaultPrivacy')->willReturn(Stream::TYPE_FOLLOWERS);

		$this->service->import($this->alice(), $path);

		$this->assertSame(Stream::TYPE_FOLLOWERS, $this->written[0]->getVisibility());
	}

	/** The same archive twice writes the posts once. */
	public function testASecondRunOfTheSameInstagramArchiveWritesNothing(): void {
		$path = $this->archive([
			'content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/a.jpg']], ['title' => 'once']),
			]),
			'media/a.jpg' => 'a',
		]);

		$this->service->import($this->alice(), $path);
		$source = array_key_first($this->remembered);
		$this->assertStringStartsWith('instagram:', $source);

		$again = $this->serviceKnowing([$source => md5('x')]);
		$tally = $again->import($this->alice(), $path);

		$this->assertSame(0, $tally['imported']);
		$this->assertSame(1, $tally['already']);
	}

	/**
	 * The archive as a browser saves it: everything under one folder named for
	 * the account, so nothing is where the export said it would be.
	 */
	public function testPicturesAreFoundWhenTheArchiveIsPackedUnderAFolder(): void {
		$path = $this->archive([
			'instagram-alice-2026-09-15/content/posts_1.json' => json_encode([
				$this->instagramPost([['uri' => 'media/posts/202406/pier.jpg']], ['title' => 'a pier']),
			]),
			'instagram-alice-2026-09-15/media/posts/202406/pier.jpg' => 'not really a jpeg',
		]);

		$tally = $this->service->import($this->alice(), $path);

		$this->assertSame(1, $tally['imported']);
		$this->assertSame(1, $tally['media']);
	}

	/**
	 * The HTML download is the one people pick, because it is the one they can
	 * open. Being told to ask for JSON is the difference between a two-minute
	 * fix and giving up.
	 */
	public function testTheHtmlDownloadSaysWhatToAskInstagramFor(): void {
		$path = $this->archive([
			'your_instagram_activity/content/posts_1.html' => '<html><body>a post</body></html>',
		]);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/JSON/');
		$this->service->import($this->alice(), $path);
	}

	/** @param array<string, string> $known */
	private function serviceKnowing(array $known): PostImportService {
		$request = $this->createMock(ImportedPostsRequest::class);
		$request->method('knownAmong')->willReturn($known);
		$request->method('remember')
			->willReturnCallback(function (string $actor, string $source, string $stream): void {
				$this->remembered[$source] = $stream;
			});

		$reflection = new \ReflectionClass($this->service);
		$clone = $reflection->newInstanceWithoutConstructor();
		foreach ($reflection->getProperties() as $property) {
			$property->setValue($clone, $property->getValue($this->service));
		}
		$reflection->getProperty('importedPostsRequest')->setValue($clone, $request);

		return $clone;
	}
}
