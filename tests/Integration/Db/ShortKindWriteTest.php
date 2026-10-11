<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `social_stream.short_kind` is written wherever a post's attachment copies
 * are: when the post is stored, when it is edited, when its copies are
 * rebuilt, and when a remote video it carries is cached and measured. A write
 * that changed the attachments and not the column would leave `only_short`
 * answering for a video the post no longer carries.
 */
class ShortKindWriteTest extends TestCase {
	private const BASE = 'https://remote.example/shortkind/notes/';
	private const AUTHOR = 'https://remote.example/shortkind/users/author';
	private const DOC_NID = '7300000000000001';

	private StreamRequest $streamRequest;
	/** @var string[] */
	private array $notes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
	}

	protected function tearDown(): void {
		foreach ($this->notes as $id) {
			$this->streamRequest->deleteById($id, Note::TYPE);
		}
		parent::tearDown();
	}

	private function video(?int $seconds, int $width = 1080, int $height = 1920): MediaAttachment {
		$media = new MediaAttachment();
		$media->setId(self::DOC_NID);
		$media->setType('video');
		$media->setMediaType('video/mp4');
		$media->setUrl('https://remote.example/shortkind/media.mp4');
		if ($seconds !== null) {
			$meta = new AttachmentMeta();
			$meta->setDuration((float)$seconds);
			$meta->setOriginal(new AttachmentMetaDim([$width, $height]));
			$media->setMeta($meta);
		}

		return $media;
	}

	/** @param MediaAttachment[] $attachments */
	private function note(string $name, array $attachments): Note {
		$note = new Note();
		$note->setId(self::BASE . $name . '-' . bin2hex(random_bytes(3)));
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent('<p>' . $name . '</p>');
		$note->setAttachments($attachments);
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);
		$this->notes[] = $note->getId();

		return $note;
	}

	private function shortKind(string $id): mixed {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('short_kind')->from('social_stream')
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($id))));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value;
	}

	public function testAStoredPostIsWorkedOutOnTheWayIn(): void {
		$this->assertSame(Stream::SHORT_KIND_SHORT, $this->shortKind($this->note('short', [$this->video(30)])->getId()));
		$this->assertSame(Stream::SHORT_KIND_NONE, (string)$this->shortKind($this->note('long', [$this->video(600)])->getId()));
		$this->assertSame(
			Stream::SHORT_KIND_NONE, (string)$this->shortKind($this->note('remote', [$this->video(null)])->getId()),
			'a remote video that arrived without meta is not a short'
		);
	}

	public function testAnEditRewritesIt(): void {
		$note = $this->note('edited', [$this->video(30)]);

		$note->setAttachments([$this->video(30, 1920, 1080)]);
		$this->streamRequest->update($note);
		$this->assertSame(Stream::SHORT_KIND_NONE, (string)$this->shortKind($note->getId()), 'turned on its side');

		$note->setAttachments([$this->video(30)]);
		$this->streamRequest->update($note);
		$this->assertSame(Stream::SHORT_KIND_SHORT, $this->shortKind($note->getId()), 'and back');
	}

	public function testRebuiltCopiesRewriteIt(): void {
		$note = $this->note('rebuilt', [$this->video(null)]);

		$this->streamRequest->setStoredAttachmentCopies(
			$note->getId(), (string)json_encode([$this->video(30)->asLocal()], JSON_UNESCAPED_SLASHES)
		);

		$this->assertSame(Stream::SHORT_KIND_SHORT, $this->shortKind($note->getId()));
	}

	/**
	 * A remote video arrives with no duration; caching the file measures it
	 * and rewrites the post's copy, which is when it becomes a short.
	 */
	public function testARemoteVideoMeasuredOnCachingBecomesAShort(): void {
		$note = $this->note('measured', [$this->video(null)]);
		$this->assertSame(Stream::SHORT_KIND_NONE, (string)$this->shortKind($note->getId()));

		$meta = new AttachmentMeta();
		$meta->setDuration(45.0);
		$document = new Document();
		$document->setNid(self::DOC_NID);
		$document->setParentId($note->getId());
		$document->setMediaType('video/mp4');
		$document->setLocalCopySize(720, 1280);
		$document->setMeta($meta);

		$this->streamRequest->updateAttachments($document);

		$this->assertSame(Stream::SHORT_KIND_SHORT, $this->shortKind($note->getId()));
	}
}
