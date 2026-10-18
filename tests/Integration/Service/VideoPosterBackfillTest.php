<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\VideoPosterBackfill;
use OCA\Social\Tools\Nid;
use OCP\IBinaryFinder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The poster backfill end to end: a real video in app storage with no poster,
 * the real ffmpeg, the real database. The video gets its frame, the post that
 * carries it is rewritten to show it, and a video that cannot give one is not
 * tried again.
 */
class VideoPosterBackfillTest extends TestCase {
	private const BASE = 'https://cloud.example.org/posterfill';
	private const AUTHOR = self::BASE . '/users/author';

	private CacheDocumentsRequest $documents;
	private StreamRequest $streamRequest;
	private ConfigService $config;
	private string $cursorBefore = '';
	/** @var string[] */
	private array $tempFiles = [];

	protected function setUp(): void {
		parent::setUp();
		$this->documents = Server::get(CacheDocumentsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->config = Server::get(ConfigService::class);
		$this->cursorBefore = (string)$this->config->getAppValue(ConfigService::SOCIAL_POSTER_CURSOR);

		// tearDown() also runs after a skip, so the services are set first.
		if (Server::get(IBinaryFinder::class)->findBinaryPath('ffmpeg') === false) {
			$this->markTestSkipped('ffmpeg is not installed');
		}

		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		$this->config->setAppValue(ConfigService::SOCIAL_POSTER_CURSOR, $this->cursorBefore);
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (['video', 'broken'] as $name) {
			try {
				$stored = $this->documents->getById(self::BASE . '/documents/' . $name);
				foreach ([$stored->getLocalCopy(), $stored->getResizedCopy()] as $file) {
					if ($file !== '') {
						Server::get(CacheDocumentService::class)->removeFromCache($file);
					}
				}
			} catch (\Exception $e) {
			}
			$this->documents->deleteById(self::BASE . '/documents/' . $name);
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $name);
			$qb = Server::get(IDBConnection::class)->getQueryBuilder();
			$qb->delete(CoreRequestBuilder::TABLE_STREAM_MEDIA)
				->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter(md5(self::BASE . '/notes/' . $name))));
			$qb->executeStatement();
		}
	}

	/** @return string a two-second MP4 ffmpeg made, on disk */
	private function realVideo(): string {
		$ffmpeg = (string)Server::get(IBinaryFinder::class)->findBinaryPath('ffmpeg');
		$path = tempnam(sys_get_temp_dir(), 'social-posterfill-') . '.mp4';
		$this->tempFiles[] = $path;
		exec(escapeshellarg($ffmpeg) . ' -v error -nostdin -f lavfi -i testsrc=duration=2:size=160x90:rate=10 -pix_fmt yuv420p -y ' . escapeshellarg($path), $out, $status);
		if ($status !== 0 || !is_file($path) || filesize($path) === 0) {
			$this->markTestSkipped('this ffmpeg cannot write an MP4');
		}

		return $path;
	}

	/** A stored upload with no poster, and the local post that carries it. */
	private function storedVideo(string $name, string $bytesFrom): Document {
		$document = new Document();
		$document->setId(self::BASE . '/documents/' . $name);
		$document->setUrl(self::BASE . '/files/' . $name . '.mp4');
		$document->setAccount('itest-posterfill');
		$document->setMediaType('video/mp4');
		$document->setMimeType('video/mp4');
		$document->setLocalCopy(Server::get(CacheDocumentService::class)->storeFile($bytesFrom));
		$document->setResizedCopy('');
		$document->setPublic(true);
		$this->documents->save($document);
		$document = $this->documents->getById($document->getId());

		$attachment = new MediaAttachment();
		$attachment->setId((string)$document->getNid())
			->setType('video')
			->setMediaType('video/mp4')
			->setUrl(self::BASE . '/media/' . $name . '.mp4')
			->setPreviewUrl(self::BASE . '/media/' . $name . '.mp4');
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $name);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent('<p>' . $name . '</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$note->setLocal(true);
		$note->setAttachments([$attachment]);
		$this->streamRequest->save($note);

		return $document;
	}

	/** @return array<string, mixed> the stored copy of the post's one attachment */
	private function storedCopy(string $name): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('attachments')->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5(self::BASE . '/notes/' . $name))));
		$copies = json_decode((string)$qb->executeQuery()->fetchOne(), true);

		return is_array($copies[0] ?? null) ? $copies[0] : [];
	}

	public function testAStoredVideoWithoutAPosterGetsOneAndItsPostShowsIt(): void {
		$video = $this->storedVideo('video', $this->realVideo());
		$this->config->setAppValue(ConfigService::SOCIAL_POSTER_CURSOR, Nid::decrement((string)$video->getNid()));
		$before = $this->storedCopy('video');
		$this->assertSame($before['url'] ?? null, $before['preview_url'] ?? null, 'no poster yet: the preview is the video');

		$this->assertSame(1, Server::get(VideoPosterBackfill::class)->next(1));

		$stored = $this->documents->getById($video->getId());
		$this->assertNotSame('', $stored->getResizedCopy(), 'the frame is the resized copy');
		$this->assertSame(2, (int)round((float)$stored->getMeta()?->getDuration()));
		$copy = $this->storedCopy('video');
		$this->assertNotSame($copy['url'] ?? null, $copy['preview_url'] ?? null, 'the post now shows a picture');
		$this->assertStringEndsWith('.jpeg', (string)($copy['preview_url'] ?? ''));
		$this->assertSame((string)$video->getNid(), $this->config->getAppValue(ConfigService::SOCIAL_POSTER_CURSOR));
	}

	/** A file ffmpeg cannot read is passed over once, and the next run does not try it again. */
	public function testAVideoWithoutAFrameIsNotTriedAgain(): void {
		$garbage = tempnam(sys_get_temp_dir(), 'social-posterfill-');
		$this->tempFiles[] = $garbage;
		file_put_contents($garbage, str_repeat('not a video ', 100));
		$broken = $this->storedVideo('broken', $garbage);
		$this->config->setAppValue(ConfigService::SOCIAL_POSTER_CURSOR, Nid::decrement((string)$broken->getNid()));

		$this->assertSame(0, Server::get(VideoPosterBackfill::class)->next(1));

		$this->assertSame('', $this->documents->getById($broken->getId())->getResizedCopy());
		$this->assertSame((string)$broken->getNid(), $this->config->getAppValue(ConfigService::SOCIAL_POSTER_CURSOR));
	}
}
