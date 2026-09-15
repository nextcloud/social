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

		$this->service = new PostImportService(
			$this->importedPostsRequest,
			$this->streamRequest,
			$streamService,
			$this->documentService,
			$this->cacheDocumentService,
			$linkify,
			$this->createMock(AccountService::class),
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
