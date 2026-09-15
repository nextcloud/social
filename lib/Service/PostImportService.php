<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\ITempManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Bringing an account's own posts over from the server it wrote them on.
 *
 * What moving to another Fediverse server has never carried is the posts. A
 * `Move` takes the followers; an export takes a file. This reads that file and
 * writes the posts here — as **new local posts of the importing account**,
 * dated when they were written, with their pictures.
 *
 * Three rules decide everything else, and each of them is load-bearing:
 *
 * **Nothing is federated.** Not one delivery is queued. Re-publishing somebody's
 * five years of posts would put five years of posts into the timeline of every
 * person who follows them, on every server, in one afternoon. The rows are
 * written and the queue is never touched — the same write-the-row,
 * federate-nothing discipline `SocialMigrator::importRelations()` uses for the
 * follow graph.
 *
 * **The ids are ours.** A post's id belongs to the server it was written on: an
 * id from somewhere else served from here would be a second server claiming
 * another's URI, and nothing would resolve it. So each post is minted locally
 * and the original id is remembered in `social_import_post` — which is what
 * makes a second run of the same archive a no-op, and what lets a reply find
 * the post it answers.
 *
 * **Only what the account wrote.** Boosts are somebody else's post and are
 * skipped. Direct messages are skipped too: their recipients are accounts on
 * the old server, and a direct message with nobody in it is a post nobody —
 * the author included — can find.
 *
 * Four shapes are read, because four are what the networks hand out:
 *
 *  - this app's own archive (`social/outbox.json` in the zip, with the files
 *    under `media_attachments/`),
 *  - Mastodon's and GoToSocial's (`outbox.json` at the root of the zip, same
 *    layout — theirs is the layout ours copied),
 *  - an `outbox.json` on its own, and
 *  - Pixelfed's `pixelfed-statuses.json`, which is its API's shape and carries
 *    **URLs rather than files**: those pictures are fetched from the old
 *    server, which therefore has to still be running.
 */
class PostImportService {
	/** How many posts one run writes at most. */
	public const MAX_POSTS = 2000;

	/** Where an archive keeps the posts, ours first. */
	private const OUTBOX_PATHS = ['social/outbox.json', 'outbox.json'];

	/** How large a file inside an archive may be before it is left alone. */
	private const MAX_FILE = 200 * 1024 * 1024;

	public function __construct(
		private ImportedPostsRequest $importedPostsRequest,
		private StreamRequest $streamRequest,
		private StreamService $streamService,
		private DocumentService $documentService,
		private CacheDocumentService $cacheDocumentService,
		private LinkifyService $linkifyService,
		private AccountService $accountService,
		private ITempManager $tempManager,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads an export and writes what is in it.
	 *
	 * @param bool $fetchMedia whether a picture named only by a URL may be
	 *                         fetched from the server it is still on
	 * @return array{imported: int, skipped: int, already: int, media: int, failed: int, total: int, capped: bool}
	 * @psalm-suppress InvalidReturnType the tally is built by reference through
	 *                 the writers, which psalm cannot follow back to its shape
	 * @throws InvalidResourceException when the file is not an export this can read
	 */
	public function import(Person $actor, string $path, bool $fetchMedia = true, int $limit = self::MAX_POSTS): array {
		$limit = max(1, min(self::MAX_POSTS, $limit));
		$zip = $this->openArchive($path);

		try {
			$items = $this->items($path, $zip);
			$tally = [
				'imported' => 0, 'skipped' => 0, 'already' => 0,
				'media' => 0, 'failed' => 0, 'total' => count($items), 'capped' => false,
			];

			$parsed = [];
			foreach ($items as $item) {
				$post = is_array($item) ? $this->parse($item) : null;
				if ($post === null) {
					$tally['skipped']++;
					continue;
				}
				$parsed[] = $post;
			}

			// oldest first, so a reply is written after the post it answers and
			// can be hung off it
			usort($parsed, static fn (array $a, array $b): int => $a['published'] <=> $b['published']);

			$known = $this->importedPostsRequest->knownAmong(
				$actor->getId(), array_column($parsed, 'source')
			);

			foreach ($parsed as $post) {
				if (isset($known[$post['source']])) {
					$tally['already']++;
					continue;
				}

				if ($tally['imported'] >= $limit) {
					$tally['capped'] = true;
					break;
				}

				try {
					$written = $this->write($actor, $post, $known, $zip, $fetchMedia, $tally);
				} catch (Throwable $e) {
					$this->logger->warning('could not import a post', [
						'actor' => $actor->getId(), 'source' => $post['source'], 'exception' => $e,
					]);
					$tally['failed']++;
					continue;
				}

				$known[$post['source']] = md5($written->getId());
				$this->importedPostsRequest->remember($actor->getId(), $post['source'], $written->getId());
				$tally['imported']++;
			}

			if ($tally['imported'] > 0) {
				$this->accountService->cacheLocalActorDetailCount($actor);
			}

			/** @psalm-suppress InvalidReturnStatement the shape is the one declared above */
			return $tally;
		} finally {
			$zip?->close();
		}
	}

	/**
	 * The archive, or null where the file is a bare JSON export.
	 */
	private function openArchive(string $path): ?ZipArchive {
		if (!is_file($path)) {
			throw new InvalidResourceException('there is no such file');
		}

		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			return null;
		}

		return $zip;
	}

	/**
	 * The posts an export names, whichever of the four shapes it is in.
	 *
	 * @return array<int, mixed>
	 * @throws InvalidResourceException
	 */
	private function items(string $path, ?ZipArchive $zip): array {
		$contents = null;

		if ($zip !== null) {
			foreach (self::OUTBOX_PATHS as $candidate) {
				$found = $zip->getFromName($candidate);
				if ($found !== false) {
					$contents = $found;
					break;
				}
			}

			if ($contents === null) {
				throw new InvalidResourceException(
					'this archive has no outbox.json — it is not an export of an account\'s posts'
				);
			}
		} else {
			$contents = file_get_contents($path);
			if ($contents === false) {
				throw new InvalidResourceException('the file could not be read');
			}
		}

		$decoded = json_decode($contents, true);
		if (!is_array($decoded)) {
			throw new InvalidResourceException('this file is not the JSON an export is written in');
		}

		// an OrderedCollection, ours and Mastodon's; a bare array, Pixelfed's
		$items = $decoded['orderedItems'] ?? $decoded['items'] ?? $decoded['data'] ?? $decoded;
		if (!is_array($items)) {
			throw new InvalidResourceException('this export names no posts');
		}

		return array_values($items);
	}

	/**
	 * One item of an export, as much as this app can use of it.
	 *
	 * Null for anything that is not a post of the account's own to bring over:
	 * a boost, a direct message, a deletion, an empty item.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>|null
	 */
	private function parse(array $item): ?array {
		// an outbox is activities; ours writes the objects themselves
		$type = (string)($item['type'] ?? '');
		if ($type === 'Create' && is_array($item['object'] ?? null)) {
			$item = $item['object'];
			$type = (string)($item['type'] ?? '');
		}

		// a boost is somebody else's post, and the `reblog` of an API export
		// says the same thing
		if (in_array($type, ['Announce', 'Delete', 'Undo', 'Like'], true) || ($item['reblog'] ?? null) !== null) {
			return null;
		}

		$source = (string)($item['id'] ?? $item['uri'] ?? $item['url'] ?? '');
		if ($source === '') {
			return null;
		}

		$visibility = $this->visibility($item);
		if ($visibility === '' || $visibility === Stream::TYPE_DIRECT) {
			// a direct message's recipients are accounts on the old server: a
			// copy here would be addressed to nobody, and a post addressed to
			// nobody is one even its author cannot find
			return null;
		}

		$text = $this->text((string)($item['content'] ?? ''));
		$attachments = $this->attachments($item);
		if ($text === '' && $attachments === []) {
			return null;
		}

		$published = strtotime((string)($item['published'] ?? $item['created_at'] ?? ''));

		return [
			'source' => $source,
			'text' => $text,
			'published' => ($published === false || $published <= 0) ? time() : $published,
			'visibility' => $visibility,
			'sensitive' => (bool)($item['sensitive'] ?? false),
			'spoiler' => trim(strip_tags((string)($item['summary'] ?? $item['spoiler_text'] ?? ''))),
			'language' => $this->language($item),
			'replyTo' => (string)($item['inReplyTo'] ?? $item['in_reply_to_id'] ?? ''),
			'attachments' => $attachments,
			'hashtags' => $this->hashtags($item),
		];
	}

	/**
	 * What language a post was written in, as the export says.
	 *
	 * ActivityPub carries it as the key of `contentMap` and Mastodon's API as
	 * a `language`; a post that says neither is left to the account's own
	 * default, which is what `Stream::normalizeLanguage()` returning nothing
	 * means downstream.
	 *
	 * @param array<string, mixed> $item
	 */
	private function language(array $item): string {
		$language = (string)($item['language'] ?? '');
		if ($language !== '') {
			return $language;
		}

		$map = $item['contentMap'] ?? null;

		return (is_array($map) && $map !== []) ? (string)array_key_first($map) : '';
	}

	/**
	 * What audience an exported post went out to.
	 *
	 * An ActivityPub post says so by who it is addressed to; an API export
	 * says so in a word. Anything this app cannot place is treated as direct —
	 * the most restrictive reading — and therefore left alone, rather than
	 * guessed into the public timeline.
	 *
	 * @param array<string, mixed> $item
	 */
	private function visibility(array $item): string {
		$word = (string)($item['visibility'] ?? '');
		if ($word !== '') {
			return Stream::visibilityFromClient($word);
		}

		$to = $this->addresses($item['to'] ?? []);
		$cc = $this->addresses($item['cc'] ?? []);

		if (in_array(ACore::CONTEXT_PUBLIC, $to, true)) {
			return Stream::TYPE_PUBLIC;
		}
		if (in_array(ACore::CONTEXT_PUBLIC, $cc, true)) {
			return Stream::TYPE_UNLISTED;
		}
		// addressed to a followers collection — whoever's, since the only
		// account being imported is the one importing
		foreach (array_merge($to, $cc) as $address) {
			if (str_ends_with($address, '/followers')) {
				return Stream::TYPE_FOLLOWERS;
			}
		}

		return Stream::TYPE_DIRECT;
	}

	/**
	 * @param mixed $value
	 * @return string[]
	 */
	private function addresses(mixed $value): array {
		if (is_string($value)) {
			return [$value];
		}
		if (!is_array($value)) {
			return [];
		}

		return array_values(array_filter(array_map(
			static fn ($entry): string => is_string($entry) ? $entry : '',
			$value
		)));
	}

	/**
	 * A post's words, from the markup the other server wrote them in.
	 *
	 * The markup is not kept: this app renders its own from the text, and a
	 * post is stored as what was typed. Paragraphs and line breaks are the
	 * only formatting a post here has, so they are the only ones carried.
	 */
	private function text(string $html): string {
		if ($html === '') {
			return '';
		}

		$text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
		$text = preg_replace('#</p\s*>#i', "\n\n", $text) ?? $text;
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

		return trim($text);
	}

	/**
	 * The hashtags an export names, so they are indexed here as they were
	 * there — a tag the text spells is found by the linkifier anyway, and a
	 * tag only the metadata knows would otherwise be lost.
	 *
	 * @param array<string, mixed> $item
	 * @return string[]
	 */
	private function hashtags(array $item): array {
		$names = [];
		foreach (array_merge($this->listOf($item, 'tag'), $this->listOf($item, 'tags')) as $tag) {
			if (!is_array($tag)) {
				continue;
			}
			$type = strtolower((string)($tag['type'] ?? 'hashtag'));
			if ($type !== 'hashtag') {
				continue;
			}
			$name = ltrim(trim((string)($tag['name'] ?? '')), '#');
			if ($name !== '') {
				$names[strtolower($name)] = $name;
			}
		}

		return array_values($names);
	}

	/**
	 * The pictures and videos an exported post carries.
	 *
	 * @param array<string, mixed> $item
	 * @return array<int, array{url: string, name: string}>
	 */
	private function attachments(array $item): array {
		$attachments = [];
		foreach (array_merge($this->listOf($item, 'attachment'), $this->listOf($item, 'media_attachments')) as $attachment) {
			if (!is_array($attachment)) {
				continue;
			}

			$url = (string)($attachment['url'] ?? $attachment['remote_url'] ?? '');
			if ($url === '') {
				continue;
			}

			$attachments[] = [
				'url' => $url,
				'name' => (string)($attachment['name'] ?? $attachment['description'] ?? ''),
			];

			if (count($attachments) >= Stream::MAX_ATTACHMENTS) {
				break;
			}
		}

		return $attachments;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<int, mixed>
	 */
	private function listOf(array $item, string $key): array {
		$value = $item[$key] ?? [];

		return is_array($value) ? array_values($value) : [];
	}

	/**
	 * Writes one post, and nothing else.
	 *
	 * `StreamRequest::save()` rather than the delivery path: it writes the
	 * post, its recipients and its tags in one transaction and queues no
	 * request. That is the whole difference between importing a post and
	 * publishing one.
	 *
	 * @param array<string, mixed> $post
	 * @param array<string, string> $known original id => local id_prim, for a reply's parent
	 * @param array<string, mixed> $tally
	 */
	private function write(
		Person $actor,
		array $post,
		array $known,
		?ZipArchive $zip,
		bool $fetchMedia,
		array &$tally,
	): Note {
		$note = new Note();
		$this->streamService->assignItem($note, $actor, $post['visibility']);
		$note->setAttributedTo($actor->getId());
		$note->setSpoilerText($post['spoiler']);
		$note->setSensitive($post['sensitive'] || $post['spoiler'] !== '');
		$note->setVisibility($post['visibility']);
		$note->setLanguage(Stream::normalizeLanguage($post['language']));

		// when it was written, not when it was imported: an archive that
		// arrived as today's posts would be a timeline of somebody's whole
		// history, in one minute, in the wrong order
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $post['published']));
		$note->convertPublished();

		// a reply keeps its parent where the archive holds both: the thread is
		// the only structure in an export, and it is cheap to keep
		$parent = $known[$post['replyTo']] ?? '';
		if ($post['replyTo'] !== '' && $parent !== '') {
			$note->setInReplyTo($this->streamIdOf($parent));
		}

		$this->streamService->addHashtags($note, $post['hashtags']);
		$note->setContent($this->linkifyService->toHtml($post['text'], $note->getTags()));
		$note->setSource(json_encode($note, JSON_UNESCAPED_SLASHES));

		$attachments = $this->store($actor, $note, $post, $zip, $fetchMedia, $tally);
		if ($attachments !== []) {
			$note->setAttachments($attachments);
		}

		$this->streamRequest->save($note);

		return $note;
	}

	/**
	 * The post an imported id names, as this app's own id.
	 */
	private function streamIdOf(string $prim): string {
		try {
			return $this->streamRequest->getStream($prim)->getId();
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * The files of one post: out of the archive where it has them, off the old
	 * server where the export only wrote their addresses.
	 *
	 * Every file goes through the ordinary upload path, so an imported picture
	 * is stripped of its metadata, converted where a browser could not draw
	 * it, and held to the same size and type this instance accepts from its
	 * own people.
	 *
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $tally
	 * @return MediaAttachment[]
	 */
	private function store(
		Person $actor,
		Note $note,
		array $post,
		?ZipArchive $zip,
		bool $fetchMedia,
		array &$tally,
	): array {
		$public = in_array($post['visibility'], [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true);
		$stored = [];

		foreach ($post['attachments'] as $attachment) {
			$temp = $this->fetch($attachment['url'], $zip, $fetchMedia);
			if ($temp === null) {
				$tally['failed']++;
				continue;
			}

			try {
				$document = $this->documentService->storeLocalAttachment(
					$actor, $temp, $note->getId(), $attachment['name'], $public
				);
				$stored[] = $document->convertToMediaAttachment($this->urlGenerator, ACore::FORMAT_LOCAL);
				$tally['media']++;
			} catch (Throwable $e) {
				$this->logger->notice('could not store an imported attachment', [
					'url' => $attachment['url'], 'exception' => $e,
				]);
				$tally['failed']++;
			} finally {
				@unlink($temp);
			}
		}

		return $stored;
	}

	/**
	 * One file, as a temporary path, or null where it cannot be had.
	 *
	 * A relative address is a file inside the archive — which is how both this
	 * app's export and Mastodon's name theirs. An absolute one is on the
	 * server the account is leaving, and is only fetched if the person asked
	 * for that: it means telling the old server that the import is happening,
	 * and it only works while that server is still up.
	 */
	private function fetch(string $url, ?ZipArchive $zip, bool $fetchMedia): ?string {
		$isRemote = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');

		if (!$isRemote) {
			if ($zip === null) {
				return null;
			}

			return $this->fromArchive($zip, $url);
		}

		if (!$fetchMedia) {
			return null;
		}

		try {
			$content = $this->cacheDocumentService->retrieveContent($url);
		} catch (Throwable $e) {
			$this->logger->notice('could not fetch an imported attachment', [
				'url' => $url, 'exception' => $e->getMessage(),
			]);

			return null;
		}

		if ($content === '') {
			return null;
		}

		$temp = $this->tempManager->getTemporaryFile();
		if ($temp === false || file_put_contents($temp, $content) === false) {
			return null;
		}

		return $temp;
	}

	/**
	 * A file out of the archive, written where the upload path can read it.
	 *
	 * The entry is read by name and its size is checked first: a zip says how
	 * large a member is before it is expanded, and an archive that claims a
	 * small file and expands to a large one is the oldest trick there is.
	 */
	private function fromArchive(ZipArchive $zip, string $path): ?string {
		foreach ([$path, 'social/' . $path, ltrim($path, '/')] as $candidate) {
			$stat = $zip->statName($candidate);
			if ($stat === false) {
				continue;
			}

			if (($stat['size'] ?? 0) > self::MAX_FILE) {
				$this->logger->notice('an archived attachment is past the ceiling', ['path' => $candidate]);

				return null;
			}

			$content = $zip->getFromName($candidate);
			if ($content === false) {
				return null;
			}

			$temp = $this->tempManager->getTemporaryFile();
			if ($temp === false || file_put_contents($temp, $content) === false) {
				return null;
			}

			return $temp;
		}

		return null;
	}
}
