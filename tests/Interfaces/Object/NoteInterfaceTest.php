<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PushService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/../ActivityPubTestCase.php';

class NoteInterfaceTest extends ActivityPubTestCase {
	private const NOTE = self::REMOTE_URL . '/notes/1';
	private const PARENT = self::LOCAL_URL . '/notes/parent';

	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var CacheActorsRequest&MockObject */
	private $cacheActorsRequest;
	/** @var PollService&MockObject */
	private $pollService;
	/** @var PushService&MockObject */
	private $pushService;
	private StreamQueueService|MockObject $streamQueueService;
	private LinkPreviewService|MockObject $linkPreviewService;
	private NoteInterface $handler;

	private Person $alice;
	private Person $bob;
	private Person $carol;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->pushService = $this->createMock(PushService::class);

		$this->pollService = $this->createMock(PollService::class);
		$this->streamQueueService = $this->createMock(StreamQueueService::class);
		$this->linkPreviewService = $this->createMock(LinkPreviewService::class);
		$this->handler = new NoteInterface(
			$this->streamRequest,
			$this->cacheActorsRequest,
			$this->pollService,
			$this->pushService,
			$this->streamQueueService,
			$this->linkPreviewService
		);

		$this->alice = $this->person(self::LOCAL_URL . '/users/alice', true);
		$this->bob = $this->person(self::REMOTE_URL . '/users/bob');
		$this->carol = $this->person('https://other.example/users/carol');
	}

	/** A note written by bob (remote). */
	private function incomingNote(): Note {
		return $this->note(self::NOTE, $this->bob->getId());
	}

	/** The activity from bob's server wrapping the note. */
	private function wrap(string $type, Note $note, string $origin = self::REMOTE_HOST): ACore {
		return $this->incoming($type, self::NOTE . '/activity', $this->bob->getId(), $note, $origin);
	}

	/** The note as it comes back from storage in local format: with its author attached. */
	private function storedCopy(): Note {
		$post = $this->note(self::NOTE, $this->bob->getId());
		$post->setActor($this->bob);

		return $post;
	}

	private function nothingStored(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
	}

	/** Nothing is stored under the note's id yet, but its stored copy is available once saved. */
	private function storedAfterSave(): void {
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id, bool $asViewer = false, int $format = ACore::FORMAT_ACTIVITYPUB): Stream {
				if ($id === self::NOTE && $format === ACore::FORMAT_LOCAL) {
					return $this->storedCopy();
				}

				throw new StreamNotFoundException();
			});
	}

	private function knownActors(Person ...$actors): void {
		$this->cacheActorsRequest->method('getFromId')->willReturnCallback(function (string $id) use ($actors): Person {
			foreach ($actors as $actor) {
				if ($actor->getId() === $id) {
					return $actor;
				}
			}

			throw new CacheActorDoesNotExistException();
		});
	}

	// visibility of incoming notes

	public function visibilityProvider(): array {
		$public = 'https://www.w3.org/ns/activitystreams#Public';
		$followers = self::REMOTE_URL . '/users/bob/followers';

		return [
			'as:Public in to is public' => [[$public], [], 'public'],
			'as:Public in cc is unlisted' => [[$followers], [$public], 'unlisted'],
			'followers collection only is followers' => [[$followers], [], 'followers'],
			'individual recipients only is direct' => [[self::LOCAL_URL . '/users/alice'], [], 'direct'],
		];
	}

	/**
	 * @dataProvider visibilityProvider
	 */
	public function testIncomingNoteVisibilityIsEstimatedFromItsAddressing(array $to, array $cc, string $expected): void {
		$this->nothingStored();
		$this->knownActors($this->bob);
		$note = $this->incomingNote();
		foreach ($to as $recipient) {
			$note->addToArray($recipient);
		}
		$note->setCcArray($cc);

		$this->handler->save($note);

		$this->assertSame($expected, $note->getVisibility());
	}

	public function testAnUnknownAuthorDegradesFollowersOnlyToDirect(): void {
		$this->nothingStored();
		$this->knownActors(); // bob's actor is not cached
		$note = $this->incomingNote();
		$note->addToArray(self::REMOTE_URL . '/users/bob/followers');

		$this->handler->save($note);

		$this->assertSame('direct', $note->getVisibility());
	}

	public function testAnAlreadySetVisibilityIsNotOverridden(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$note->setVisibility('unlisted');
		$note->addToArray('https://www.w3.org/ns/activitystreams#Public');

		$this->handler->save($note);

		$this->assertSame('unlisted', $note->getVisibility());
	}

	public function testAConsumedPollVoteIsNeverStored(): void {
		$this->nothingStored();
		$this->pollService->method('handleIncomingVote')->willReturn(true);
		$this->streamRequest->expects($this->never())->method('save');
		$this->pushService->expects($this->never())->method('onNewStream');

		$this->handler->save($this->incomingNote());
	}

	public function testCreateStoresTheNoteTaggedWithItsActivity(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$create = $this->wrap(Create::TYPE, $note);

		$this->streamRequest->expects($this->once())->method('save')->with($this->identicalTo($note));

		$this->handler->activity($create, $note);

		$this->assertSame($create->getId(), $note->getActivityId());
	}

	public function testCreateOfAStoredNoteIsPushedToConnectedClients(): void {
		$this->nothingStored();
		$note = $this->incomingNote();

		$this->pushService->expects($this->once())->method('onNewStream')->with(self::NOTE);

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testCreateOfAnAlreadyKnownNoteIsNotStoredTwice(): void {
		$note = $this->incomingNote();
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($this->storedCopy());

		$this->streamRequest->expects($this->never())->method('save');
		$this->pushService->expects($this->never())->method('onNewStream');

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testCreateOfANoteClaimingAnAuthorFromAnotherServerIsRefused(): void {
		$note = $this->note(self::NOTE, $this->carol->getId());

		$this->streamRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testSaveRefusesANoteWhoseAuthorIsOnAnotherServer(): void {
		// save() is the invariant every fetch-and-store path relies on (a cached
		// announced object, a synced outbox) where there is no request origin to
		// compare against: the note's id host and its attributedTo host must match.
		$note = $this->note(self::NOTE, $this->carol->getId());

		$this->streamRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->save($note);
	}

	public function testCreateNotComingFromTheNotesServerIsRefused(): void {
		$note = $this->incomingNote();

		$this->streamRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->wrap(Create::TYPE, $note, 'evil.example'), $note);
	}

	public function testCreateKeepsTheHashtagsAndMentionsOfTheNote(): void {
		// nobody mentioned is known locally, so mentions fall back to the tag data
		$this->personInterface->method('getItemById')->willThrowException(new ItemNotFoundException());
		$this->storedAfterSave();
		$this->knownActors();

		$create = $this->ap->getItemFromData([
			'type' => 'Create',
			'id' => self::NOTE . '/activity',
			'actor' => $this->bob->getId(),
			'object' => [
				'type' => 'Note',
				'id' => self::NOTE,
				'attributedTo' => $this->bob->getId(),
				'content' => '<p>Hello #Nextcloud and @alice</p>',
				'tag' => [
					['type' => 'Hashtag', 'href' => self::REMOTE_URL . '/tags/nextcloud', 'name' => '#Nextcloud'],
					['type' => 'Mention', 'href' => $this->alice->getId(), 'name' => '@alice@local.example'],
				],
			],
		]);
		$create->setOrigin(self::REMOTE_HOST, SignatureService::ORIGIN_HEADER, time());
		$note = $create->getObject();

		$saved = null;
		$this->capture($this->streamRequest, 'save', $saved);

		$this->handler->activity($create, $note);

		$this->assertInstanceOf(Note::class, $saved);
		$this->assertSame(['Nextcloud'], $saved->getHashtags());
		$mentions = $saved->getDetailsAll()['mentions'];
		$this->assertCount(1, $mentions);
		$this->assertSame($this->alice->getId(), $mentions[0]['url']);
		$this->assertSame('alice@local.example', $mentions[0]['acct']);
	}

	public function testReplyBumpsTheRepliesCounterOfItsParent(): void {
		$parent = $this->note(self::PARENT, $this->alice->getId(), true);
		$parent->setDetailInt('remote_replies', 1);
		$this->streamRequest->method('getStreamById')->willReturnCallback(function (string $id) use ($parent): Stream {
			if ($id === self::PARENT) {
				return $parent;
			}

			throw new StreamNotFoundException();
		});
		$this->streamRequest->method('countRepliesTo')->with(self::PARENT)->willReturn(2);
		$note = $this->incomingNote();
		$note->setInReplyTo(self::PARENT);

		$this->streamRequest->expects($this->once())->method('updateDetails')->with($this->identicalTo($parent));

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);

		$this->assertSame(3, $parent->getDetailInt('replies'));
	}

	public function testReplyToAnUnknownParentIsStillStored(): void {
		$this->nothingStored();
		$note = $this->incomingNote();
		$note->setInReplyTo(self::PARENT);

		$this->streamRequest->expects($this->once())->method('save')->with($this->identicalTo($note));
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testMentioningALocalActorNotifiesThem(): void {
		$this->storedAfterSave();
		$this->knownActors($this->alice);
		$note = $this->incomingNote();
		$note->addTag(['type' => 'Mention', 'href' => $this->alice->getId(), 'name' => '@alice']);

		$notification = null;
		$this->capture($this->notificationInterface, 'save', $notification);

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);

		$this->assertInstanceOf(SocialAppNotification::class, $notification);
		$this->assertSame(Mention::TYPE, $notification->getSubType());
		$this->assertSame($this->alice->getId(), $notification->getTo());
		$this->assertSame(self::NOTE, $notification->getObjectId());
		$this->assertSame(self::NOTE . '/notification+mention', $notification->getId());
		$this->assertSame(['bob@remote.example'], $notification->getDetails('account'));
		$this->assertTrue($notification->isLocal());
	}

	public function testMentioningARemoteActorDoesNotNotifyAnyone(): void {
		$this->storedAfterSave();
		$this->knownActors($this->carol);
		$note = $this->incomingNote();
		$note->addTag(['type' => 'Mention', 'href' => $this->carol->getId(), 'name' => '@carol']);

		$this->notificationInterface->expects($this->never())->method('save');

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testMentioningAnUnknownActorDoesNotNotifyAnyone(): void {
		$this->storedAfterSave();
		$this->knownActors();
		$note = $this->incomingNote();
		$note->addTag(['type' => 'Mention', 'href' => self::LOCAL_URL . '/users/nobody', 'name' => '@nobody']);

		$this->notificationInterface->expects($this->never())->method('save');
		$this->streamRequest->expects($this->once())->method('save');

		$this->handler->activity($this->wrap(Create::TYPE, $note), $note);
	}

	public function testDeleteRemovesTheNoteAndItsLinkPreview(): void {
		$note = $this->incomingNote();

		$this->streamRequest->expects($this->once())->method('deleteById')->with(self::NOTE, Note::TYPE);
		$this->linkPreviewService->expects($this->once())->method('deleteCard')->with(self::NOTE);

		$this->handler->activity($this->wrap(Delete::TYPE, $note), $note);
	}

	public function testAStoredNoteThatLinksSomewhereIsHandedToTheQueue(): void {
		$note = $this->incomingNote();
		$note->setContent('<p>see <a href="https://example.org/a">this</a></p>');
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->linkPreviewService->method('extractUrl')->willReturn('https://example.org/a');

		// reading the page here would hold up the inbox
		$this->linkPreviewService->expects($this->never())->method('generate');
		$this->streamQueueService->expects($this->once())->method('generateStreamQueue')
			->with($note->getRequestToken(), StreamQueue::TYPE_LINK_PREVIEW, self::NOTE);

		$this->handler->save($note);
	}

	public function testANoteWithoutALinkIsNotQueued(): void {
		$note = $this->incomingNote();
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->linkPreviewService->method('extractUrl')->willReturn('');

		$this->streamQueueService->expects($this->never())->method('generateStreamQueue');

		$this->handler->save($note);
	}

	public function testDeleteNotComingFromTheNotesServerIsRefused(): void {
		$note = $this->incomingNote();

		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->wrap(Delete::TYPE, $note, 'evil.example'), $note);
	}

	public function testUpdateRewritesTheStoredNote(): void {
		$note = $this->incomingNote();
		$note->setContent('<p>edited</p>');
		$update = $this->wrap(Update::TYPE, $note);

		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($note));
		$this->streamRequest->expects($this->never())->method('save');

		$this->handler->activity($update, $note);

		$this->assertSame($update->getId(), $note->getActivityId());
	}

	public function testUpdateOfANoteClaimingAnAuthorFromAnotherServerIsRefused(): void {
		$note = $this->note(self::NOTE, $this->carol->getId());

		$this->streamRequest->expects($this->never())->method('update');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->wrap(Update::TYPE, $note), $note);
	}

	public function testOtherActivitiesLeaveTheNoteAlone(): void {
		$note = $this->incomingNote();

		$this->streamRequest->expects($this->never())->method('save');
		$this->streamRequest->expects($this->never())->method('update');
		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->handler->activity($this->wrap(Accept::TYPE, $note), $note);
	}

	public function testGetItemByIdReturnsTheStoredNote(): void {
		$stored = $this->storedCopy();
		$this->streamRequest->method('getStreamById')->with(self::NOTE)->willReturn($stored);

		$this->assertSame($stored, $this->handler->getItemById(self::NOTE));
	}

	public function testGetItemByIdThrowsWhenTheNoteIsUnknown(): void {
		$this->nothingStored();

		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItemById(self::NOTE);
	}
}
