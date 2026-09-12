<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\Client\AttachmentMeta;
use OCA\Social\Model\Client\AttachmentMetaDim;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a PeerTube `Video` says, in the terms the rest of this app speaks.
 *
 * A `Video` is modelled as a `Note` carrying its wire type in `subtype` (see
 * `AP::NOTE_LIKE_TYPES`) -- that is what makes it storable, queryable and
 * readable by a Mastodon client. What it was missing is everything that makes
 * it a *video*: the file to play, the thumbnail to show before it plays, how
 * long it runs, and which of the two actors in `attributedTo` published it.
 * None of those live where an ordinary `Note` keeps them, so they are read out
 * here.
 *
 * Nothing in this class fetches anything. It reads the object that already
 * arrived in the inbox and says what is in it; the caller decides what to do
 * with that.
 */
class PeerTubeService {
	use TArrayTools;

	/** The wire type this class is about. */
	public const TYPE = 'Video';

	public function __construct(
		private DocumentInterface $documentInterface,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The one link this app will play, in the order it prefers them.
	 *
	 * `video/mp4` is the only thing every browser can play from a bare
	 * `<video src>`, so a PeerTube in "web videos" mode is played directly.
	 * An instance transcoding to HLS only publishes `application/x-mpegURL`
	 * instead, which Safari plays natively and nothing else does; it is taken
	 * as a last resort rather than dropped, because the alternative for those
	 * videos is no player at all.
	 */
	private const PLAYABLE = ['video/mp4', 'video/webm', 'video/ogg', 'application/x-mpegURL'];

	/** Sources above this are ignored: nobody is streaming 4K through a proxy. */
	private const MAX_HEIGHT = 1080;

	/**
	 * The file to play, as a `Document` that is deliberately never mirrored.
	 *
	 * A `Document` rather than a bare url because that is what the rest of the
	 * app -- the attachment list, the media route, the client entity -- is
	 * built out of, and because the row it becomes is what the streaming route
	 * checks a request against: a proxy that would fetch any url it is handed
	 * is an open redirect with a server behind it.
	 *
	 * `localCopy` is the streamed sentinel rather than empty, which is what
	 * keeps the caching cron's hands off it -- `getNotCachedDocuments()` only
	 * looks at rows whose `local_copy` is empty, and a two-hour talk is not
	 * something to copy onto somebody's Nextcloud. `resizedCopy` is left for
	 * the thumbnail, which *is* small enough to mirror and is filled in by
	 * `VideoAttachmentService`.
	 *
	 * @param array $data the wire `Video`
	 *
	 * @return Document|null null when it publishes nothing this app can play
	 */
	public function source(array $data, ?ACore $parent = null): ?Document {
		$best = $this->bestSource($this->links($data));
		if ($best === null) {
			return null;
		}

		$document = new Document($parent);
		$document->setId($best['href'])
			->setUrl($best['href']);
		$document->setMediaType($best['mediaType']);
		$document->setMimeType($best['mediaType']);
		$document->setLocalCopy(Document::COPY_STREAMED);
		$document->setLocalCopySize($best['width'], $best['height']);
		$document->setDescription($this->title($data));
		$document->setPublic(true);

		return $document;
	}

	/**
	 * The still to show before anything is played: PeerTube's `icon`, which is
	 * one `Image` on older instances and a list of them on newer ones. The
	 * largest is taken -- they are all a few dozen kilobytes, and the grid
	 * draws them at whatever size it likes.
	 *
	 * @param array $data the wire `Video`
	 *
	 * @return array{url: string, mediaType: string}|null null when it published none
	 */
	public function thumbnail(array $data): ?array {
		$best = null;
		$bestArea = -1;

		foreach ($this->asList($data['icon'] ?? null) as $icon) {
			$url = $this->urlOf($icon);
			if ($url === '' || !$this->isHttp($url)) {
				continue;
			}

			// PeerTube states it; an instance that does not is taken at its
			// word that `icon` is a picture, which is all the field can be
			$mediaType = is_array($icon) ? (string)($icon['mediaType'] ?? 'image/jpeg') : 'image/jpeg';
			if (!str_starts_with($mediaType, 'image/')) {
				continue;
			}

			$area = is_array($icon) ? (int)($icon['width'] ?? 0) * (int)($icon['height'] ?? 0) : 0;
			if ($area > $bestArea) {
				$bestArea = $area;
				$best = ['url' => $url, 'mediaType' => $mediaType];
			}
		}

		return $best;
	}

	/**
	 * The page a reader is sent to when they want the video itself: the
	 * `text/html` link, which is the short `/w/xxx` form rather than the object
	 * id. '' when the object carries no such link.
	 */
	public function watchUrl(array $data): string {
		foreach ($this->links($data) as $link) {
			if (($link['mediaType'] ?? '') === 'text/html' && $this->isHttp((string)$link['href'])) {
				return (string)$link['href'];
			}
		}

		return '';
	}

	/**
	 * How long it runs, in whole seconds.
	 *
	 * ActivityStreams says `duration` is an xsd:duration, and PeerTube always
	 * writes the simple `PT113S` form of one. Only the time half is read: a
	 * video measured in months is not a video.
	 *
	 * @return int 0 when it says nothing usable
	 */
	public function duration(array $data): int {
		$duration = trim((string)($data['duration'] ?? ''));
		if ($duration === '') {
			return 0;
		}

		if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $duration, $matches) !== 1) {
			return 0;
		}

		return (int)($matches[1] ?? 0) * 3600
			+ (int)($matches[2] ?? 0) * 60
			+ (int)round((float)($matches[3] ?? 0));
	}

	/**
	 * Who published it.
	 *
	 * PeerTube sends `attributedTo` as a list of two actors -- the channel, a
	 * `Group`, and the account behind it, a `Person` -- where every other
	 * server sends one id as a string. `Stream::import()` asks for a string and
	 * so read neither, leaving every federated video attributed to nobody.
	 *
	 * The channel wins, because the channel is what the `Create` is signed by,
	 * what a reader follows, and what the video is listed under on PeerTube
	 * itself.
	 *
	 * @return string '' when the list holds no actor with an id
	 */
	public function attributedTo(array $data): string {
		$person = '';

		foreach ($this->asList($data['attributedTo'] ?? null) as $actor) {
			if (is_string($actor)) {
				return $actor;
			}

			$id = (string)($actor['id'] ?? '');
			if ($id === '' || !$this->isHttp($id)) {
				continue;
			}

			if (($actor['type'] ?? '') === 'Group') {
				return $id;
			}

			if ($person === '') {
				$person = $id;
			}
		}

		return $person;
	}

	/**
	 * The video as a post: its title, linked to the page it plays on, and the
	 * description under it.
	 *
	 * The title is what a video is *called*, and a `Video` keeps it in `name`
	 * where a `Note` has no title at all -- so a timeline that only read
	 * `content` showed the description of a video whose name it never
	 * mentioned. It is not copied into the note's `name` instead, because that
	 * field means one thing here, the option a poll vote chose (see
	 * `PollService::handleIncomingVote`), and giving it a second meaning is how
	 * a post would end up counted as a vote.
	 *
	 * The description is passed through as html, as every other object's
	 * `content` is -- *unless* the object says it is not, which PeerTube does:
	 * it declares `text/markdown` in the object's own `mediaType` and sends the
	 * description raw. That is then escaped and its blank lines become
	 * paragraphs, which is as much of markdown as a timeline needs. Believing
	 * the declaration in both directions is the point: escaping html would show
	 * somebody's tags to them, and rendering markdown as html would hand a
	 * remote server a way to put markup in a post that never went through a
	 * sanitiser of its own.
	 */
	public function content(array $data): string {
		$content = '';

		if ($this->title($data) !== '') {
			$content = '<p>' . $this->linkedTitle($data) . '</p>';
		}

		$description = trim((string)($data['content'] ?? ''));
		if ($description === '') {
			return $content;
		}

		if (!in_array((string)($data['mediaType'] ?? ''), ['text/markdown', 'text/plain'], true)) {
			return $content . $description;
		}

		foreach (preg_split('/\R{2,}/', $description) as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph === '') {
				continue;
			}

			$content .= '<p>' . nl2br($this->escape($paragraph), false) . '</p>';
		}

		return $content;
	}

	/** The title, as a link to the watch page when there is one. */
	private function linkedTitle(array $data): string {
		$title = $this->escape($this->title($data));
		$watch = $this->watchUrl($data);

		if ($watch === '') {
			return $title;
		}

		return '<a href="' . $this->escape($watch) . '">' . $title . '</a>';
	}

	private function title(array $data): string {
		return trim((string)($data['name'] ?? ''));
	}

	/**
	 * The playable link with the most pixels this app is willing to proxy, and
	 * `application/x-mpegURL` only when there is no file at all -- a playlist
	 * that Chrome and Firefox cannot open must never be preferred over an mp4
	 * that they can.
	 *
	 * @param array<int, array{href: string, mediaType: string, width: int, height: int}> $links
	 */
	private function bestSource(array $links): ?array {
		$best = null;
		$fallback = null;

		foreach ($links as $link) {
			if (!in_array($link['mediaType'], self::PLAYABLE, true)) {
				continue;
			}

			if ($link['mediaType'] === 'application/x-mpegURL') {
				$fallback ??= $link;
				continue;
			}

			if ($link['height'] > self::MAX_HEIGHT) {
				continue;
			}

			if ($best === null || $link['height'] > $best['height']) {
				$best = $link;
			}
		}

		return $best ?? $fallback;
	}

	/**
	 * `url` normalised to a list of links.
	 *
	 * It is a string on every other kind of object, one `Link` on some, and on
	 * a PeerTube `Video` a list that mixes the watch page, one file per
	 * resolution, the HLS playlist, a torrent and a magnet URI. Only http(s)
	 * survives -- `magnet:` is not something to hand a `<video>`.
	 *
	 * @return array<int, array{href: string, mediaType: string, width: int, height: int}>
	 */
	private function links(array $data): array {
		$links = [];

		foreach ($this->asList($data['url'] ?? null) as $link) {
			if (is_string($link)) {
				$link = ['href' => $link, 'mediaType' => 'text/html'];
			}

			$href = (string)($link['href'] ?? $link['url'] ?? '');
			if ($href === '' || !$this->isHttp($href)) {
				continue;
			}

			// `rel: ["metadata", …]` marks a description *of* the file rather
			// than the file: it is json, and playing it plays nothing
			$rel = $link['rel'] ?? [];
			if (in_array('metadata', is_array($rel) ? $rel : [$rel], true)) {
				continue;
			}

			$links[] = [
				'href' => $href,
				'mediaType' => (string)($link['mediaType'] ?? ''),
				'width' => (int)($link['width'] ?? 0),
				'height' => (int)($link['height'] ?? 0),
			];
		}

		return $links;
	}

	/**
	 * A field that may be one object, a list of them, or absent, as a list.
	 *
	 * @return array<int, mixed>
	 */
	private function asList(mixed $value): array {
		if ($value === null || $value === '') {
			return [];
		}

		if (is_string($value)) {
			return [$value];
		}

		if (!is_array($value)) {
			return [];
		}

		// a single object is an associative array; a list is not
		return array_is_list($value) ? $value : [$value];
	}

	/** The `url` of an `Image`, which is itself sometimes a `Link`. */
	private function urlOf(mixed $icon): string {
		if (is_string($icon)) {
			return $icon;
		}

		if (!is_array($icon)) {
			return '';
		}

		$url = $icon['url'] ?? '';
		if (is_string($url)) {
			return $url;
		}

		foreach ($this->asList($url) as $entry) {
			if (is_string($entry)) {
				return $entry;
			}

			$href = (string)($entry['href'] ?? $entry['url'] ?? '');
			if ($href !== '') {
				return $href;
			}
		}

		return '';
	}

	private function isHttp(string $url): bool {
		return str_starts_with($url, 'https://') || str_starts_with($url, 'http://');
	}

	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * The video as this app's one media attachment for it: a player pointed at
	 * the streaming route, a still that *is* mirrored here, and how long it
	 * runs.
	 *
	 * Two cache rows, not one. The video's says where the bytes are and is
	 * never filled; the thumbnail's is an ordinary image row, fetched and
	 * resized like any other, which is what lets `/media/{uuid}` answer it
	 * with `image/jpeg` instead of the video's own media type. Hanging the
	 * still off the video row's `resized_copy` instead would have put one uuid
	 * on two rows, and `getByCopy()` would answer with whichever the database
	 * felt like.
	 *
	 * @param array $data the wire `Video`
	 * @param ACore $parent the post it belongs to
	 *
	 * @return MediaAttachment[] empty when it publishes nothing playable
	 */
	public function attachments(array $data, ACore $parent): array {
		$source = $this->source($data, $parent);
		if ($source === null) {
			return [];
		}

		try {
			$this->documentInterface->save($source);
		} catch (Throwable $e) {
			$this->logger->warning('could not record a federated video', ['exception' => $e]);

			return [];
		}

		$media = $source->convertToMediaAttachment($this->urlGenerator);
		$this->describe($media, $data);

		$preview = $this->preview($data, $parent);
		if ($preview !== '') {
			$media->setPreviewUrl($preview);
		}

		return [$media];
	}

	/**
	 * What the player knows before it has loaded a frame: the size PeerTube
	 * published for the file it chose, and the running time.
	 */
	private function describe(MediaAttachment $media, array $data): void {
		$meta = $media->getMeta() ?? new AttachmentMeta();
		$duration = $this->duration($data);

		if ($duration > 0) {
			$meta->setDuration((float)$duration);

			$original = $meta->getOriginal() ?? new AttachmentMetaDim();
			$original->setDuration((float)$duration);
			$meta->setOriginal($original);
		}

		$media->setMeta($meta);
	}

	/**
	 * The still, cached here, as a url on this instance -- '' when the video
	 * published none or it could not be fetched.
	 *
	 * A thumbnail that will not download is not worth losing the video over,
	 * so a failure here is swallowed; the row it leaves behind has an empty
	 * `local_copy`, which is exactly what the caching cron comes back for.
	 */
	private function preview(array $data, ACore $parent): string {
		$thumbnail = $this->thumbnail($data);
		if ($thumbnail === null) {
			return '';
		}

		$still = new Document($parent);
		$still->setId($thumbnail['url'])
			->setUrl($thumbnail['url']);
		$still->setMediaType($thumbnail['mediaType']);
		$still->setMimeType($thumbnail['mediaType']);
		$still->setPublic(true);

		try {
			$this->documentInterface->save($still);
		} catch (Throwable $e) {
			$this->logger->debug('could not cache a video thumbnail', ['exception' => $e]);

			return '';
		}

		if ($still->getLocalCopy() === '') {
			return '';
		}

		return $still->convertToMediaAttachment($this->urlGenerator)->getPreviewUrl();
	}
}
