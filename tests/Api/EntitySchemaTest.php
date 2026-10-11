<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Api;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\CustomEmoji;
use OCA\Social\Service\TranslationService;
use OCA\Social\Tests\Api\Schema\SchemaValidator;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * The Mastodon entities the integration tests in tests/Integration/Api cannot
 * produce on a test server — a remote attachment of every kind, a
 * translation, a remote account that has moved — built by the same model code
 * the routes use and held against the same schemas.
 */
class EntitySchemaTest extends TestCase {
	use TActivityPubMocks;

	private SchemaValidator $schemas;

	protected function setUp(): void {
		$this->schemas = new SchemaValidator();
		$this->installActivityPub();

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args = []): string
				=> 'https://cloud.example.org/' . $route . '/' . implode('/', array_map('strval', $args))
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $url): string => 'https://cloud.example.org' . $url
		);
		$urlGenerator->method('imagePath')->willReturnCallback(
			static fn (string $app, string $image): string => '/apps/' . $app . '/img/' . $image
		);
		\OC::$server->register(IURLGenerator::class, $urlGenerator);
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	private function assertEntity(string $entity, mixed $payload): void {
		$errors = $this->schemas->validate(SchemaValidator::wire($payload), $entity);
		$this->assertSame([], $errors, $entity . " departs from its schema:\n" . implode("\n", $errors));
	}

	/**
	 * A remote attachment as it is cached from a peer's Document.
	 *
	 * @param array<string, mixed> $wire the Document as the peer sent it
	 */
	private function attachment(array $wire, string $localCopy = 'f2a1'): Document {
		$document = new Document();
		// a peer's Document carries no id, and is given one under this instance
		$document->setUrlCloud('https://cloud.example.org');
		$document->import(array_merge(['type' => 'Document'], $wire));
		$document->setNid(41);
		if ($localCopy !== '') {
			$document->setLocalCopy($localCopy)
				->setResizedCopy($localCopy . '_s')
				->setLocalCopySize(1200, 800);
			$document->setResizedCopySize(600, 400);
		}

		return $document;
	}

	/** @return array<string, array{array<string, mixed>, string, string}> */
	public static function remoteDocuments(): array {
		return [
			'a picture with a focal point' => [[
				'mediaType' => 'image/jpeg', 'url' => 'https://files.example/a.jpg', 'name' => 'a cat',
				'focalPoint' => [0.25, -0.5], 'width' => 1200, 'height' => 800,
			], 'f2a1', 'image'],
			'a video' => [['mediaType' => 'video/mp4', 'url' => 'https://files.example/v.mp4', 'width' => 1920, 'height' => 1080], 'f2a1', 'video'],
			'a sound' => [['mediaType' => 'audio/mpeg', 'url' => 'https://files.example/s.mp3'], 'f2a1', 'audio'],
			'a PDF, which no client draws' => [['mediaType' => 'application/pdf', 'url' => 'https://files.example/d.pdf', 'name' => 'minutes'], 'f2a1', 'unknown'],
			'a picture this server holds no copy of' => [['mediaType' => 'image/png', 'url' => 'https://files.example/b.png'], '', 'image'],
			'a document that names no media type' => [['url' => 'https://files.example/c'], 'f2a1', 'unknown'],
		];
	}

	/**
	 * Every kind of remote attachment is a MediaAttachment: a `type` from
	 * Mastodon's five, `url` null rather than a dead link where there is no
	 * copy here, `meta` an object or null.
	 */
	#[DataProvider('remoteDocuments')]
	public function testEveryKindOfAttachmentIsAMediaAttachment(array $wire, string $localCopy, string $type): void {
		$urlGenerator = \OC::$server->get(IURLGenerator::class);
		$media = $this->attachment($wire, $localCopy)->convertToMediaAttachment($urlGenerator);

		$this->assertEntity('MediaAttachment', $media);
		$this->assertSame($type, SchemaValidator::wire($media)->type);
	}

	public function testAFocalPointIsCarriedAsNumbers(): void {
		$urlGenerator = \OC::$server->get(IURLGenerator::class);
		$wire = SchemaValidator::wire($this->attachment(self::remoteDocuments()['a picture with a focal point'][0])
			->convertToMediaAttachment($urlGenerator));

		$this->assertSame(0.25, $wire->meta->focus->x);
		$this->assertSame(-0.5, $wire->meta->focus->y);
	}

	/**
	 * A translation through the route's own service, with a provider that
	 * answers: the status text, its poll and an attachment's description.
	 */
	public function testATranslationIsATranslation(): void {
		$tasks = $this->createStub(IManager::class);
		$tasks->method('getAvailableTaskTypeIds')->willReturn([TextToTextTranslate::ID]);
		$tasks->method('runTask')->willReturnCallback(static function (Task $task): Task {
			$task->setStatus(Task::STATUS_SUCCESSFUL);
			$task->setOutput(['output' => '[' . $task->getInput()['input'] . ']']);

			return $task;
		});

		$poll = new Question();
		$poll->setNid(77);
		$poll->setPollData(['Katzen', 'Hunde'], false, 3600);
		$poll->setContent('<p>Lieblingstier?</p>')->setLanguage('de');
		$poll->setAttachments([
			$this->attachment(['mediaType' => 'image/jpeg', 'url' => 'https://files.example/a.jpg', 'name' => 'eine Katze'])
				->convertToMediaAttachment(\OC::$server->get(IURLGenerator::class)),
		]);

		$translation = (new TranslationService($tasks, new NullLogger()))->translateStatus($poll, 'en', 'alice');

		$this->assertEntity('Translation', $translation);
	}

	/**
	 * A remote account with a verified link, and one that has moved: the
	 * `moved` account is a full Account of its own.
	 */
	public function testARemoteAccountThatMovedIsAnAccount(): void {
		$person = new Person();
		$person->setId('https://old.example/users/dana');
		$person->setNid(9);
		$person->setPreferredUsername('dana')
			->setAccount('dana@old.example');
		$person->setUrl('https://old.example/@dana');
		$person->setFields([['name' => 'Website', 'value' => '<a href="https://dana.example">dana.example</a>']]);
		$person->setDetailArray('fields_verified', ['<a href="https://dana.example">dana.example</a>' => '2026-10-01T08:00:00+00:00']);
		$person->setMovedTo('https://new.example/users/dana');
		$target = new Person();
		$target->setId('https://new.example/users/dana');
		$target->setNid(10);
		$target->setPreferredUsername('dana')
			->setAccount('dana@new.example');
		$target->setUrl('https://new.example/@dana');
		$person->setMovedToActor($target);
		$person->setExportFormat(Person::FORMAT_LOCAL);

		$this->assertEntity('Account', $person);
		$this->assertSame('10', SchemaValidator::wire($person)->moved->id);
	}

	/**
	 * Pinned as it is, and not as Mastodon would have it: an account that moved
	 * to one this server has not cached gets a stub `moved` with the id "0",
	 * which no route can open. Mastodon always knows the target. The stub is
	 * deliberate (Person::exportMovedAccount() keeps the banner drawable); its
	 * id is the part that falls short of the entity.
	 */
	public function testAMoveToAnUnknownAccountCarriesAStubWithNoId(): void {
		$person = new Person();
		$person->setId('https://old.example/users/dana');
		$person->setNid(9);
		$person->setPreferredUsername('dana')
			->setAccount('dana@old.example');
		$person->setMovedTo('https://new.example/users/dana');
		$person->setExportFormat(Person::FORMAT_LOCAL);

		$moved = SchemaValidator::wire($person)->moved;

		$this->assertSame('0', $moved->id);
		$this->assertSame(['$.id: not a valid id ("0")'], $this->schemas->validate($moved, 'Account'));
	}

	public function testACustomEmojiIsACustomEmoji(): void {
		$emoji = (new CustomEmoji('blobcat', 'blobcat.png', 'image/png', 'blobs'))
			->setUrl('https://cloud.example.org/apps/social/emoji/blobcat.png');

		$this->assertEntity('CustomEmoji', $emoji);
		$this->assertEntity('CustomEmoji', (new CustomEmoji('plain', 'plain.png', 'image/png'))
			->setUrl('https://cloud.example.org/apps/social/emoji/plain.png'));
	}
}
