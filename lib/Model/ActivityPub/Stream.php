<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub;

use DateTime;
use DateTimeZone;
use Exception;
use JsonSerializable;
use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\StreamAction;
use OCA\Social\Model\StreamCard;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Model\Cache;
use OCA\Social\Tools\Model\CacheItem;
use OCA\Social\Traits\TDetails;
use OCP\IURLGenerator;
use OCP\Server;

/**
 * Class Stream
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Stream extends ACore implements IQueryRow, JsonSerializable {
	use TDetails;

	public const TYPE = 'Stream';

	public const TYPE_PUBLIC = 'public';
	public const TYPE_UNLISTED = 'unlisted';
	public const TYPE_FOLLOWERS = 'followers';
	public const TYPE_DIRECT = 'direct';
	public const TYPE_ANNOUNCE = 'announce';

	/**
	 * What is appended to a post's id to name the collection of its replies.
	 * The route that serves it has to agree with this, because this is the URL
	 * a peer dereferences.
	 */
	public const REPLIES_PATH = '/replies';

	/**
	 * The states Mastodon's Quote entity can be in. A quote is `accepted` once
	 * the quoted author's server has approved it — and, here, once the quoted
	 * post is one this instance holds and the reader may see; `pending` while
	 * the approval or the post itself is still being waited for; `rejected`
	 * when the author refused and `revoked` when they took the approval back.
	 */
	public const QUOTE_ACCEPTED = 'accepted';
	public const QUOTE_PENDING = 'pending';
	public const QUOTE_REJECTED = 'rejected';
	public const QUOTE_REVOKED = 'revoked';

	/**
	 * Where a quote state that cannot be read off the wire object is kept.
	 *
	 * `quote` and `quoteAuthorization` are properties of the stored wire object
	 * and come back with it, but a refusal leaves no trace there: the quoting
	 * post is somebody else's document and this instance may not rewrite it.
	 * The details column is local, derived data — exactly what a refusal is.
	 */
	public const DETAIL_QUOTE_STATE = 'quote_state';

	/**
	 * How many attachments a single post may bring in. Twice what Mastodon
	 * lets an author attach, so nothing real is ever cut.
	 */
	// Ten, which is Pixelfed's album ceiling: a ten-picture album federated
	// from there used to arrive here two pictures short.
	public const MAX_ATTACHMENTS = 10;

	/**
	 * Mastodon calls a followers-only post `private`; this app has always
	 * called it `followers`. The two vocabularies have to be translated in
	 * both directions: without it a client's followers-only post arrives as a
	 * value this app does not know, and `StreamService::setRecipient()` used
	 * to address exactly those to the public collection.
	 */
	private const CLIENT_VISIBILITIES = [
		'public' => self::TYPE_PUBLIC,
		'unlisted' => self::TYPE_UNLISTED,
		'private' => self::TYPE_FOLLOWERS,
		'followers' => self::TYPE_FOLLOWERS,
		'direct' => self::TYPE_DIRECT,
	];

	/**
	 * Translate a visibility as a client writes it into this app's own
	 * vocabulary. Anything unrecognised becomes `direct` — the most
	 * restrictive option. Guessing wrong in the other direction publishes
	 * somebody's private post to the whole Fediverse.
	 */
	public static function visibilityFromClient(string $visibility): string {
		return self::CLIENT_VISIBILITIES[strtolower(trim($visibility))] ?? self::TYPE_DIRECT;
	}

	/**
	 * True when a client sent a visibility this app understands. Callers that
	 * can report an error to the client should use this and answer 422 rather
	 * than silently posting to nobody.
	 */
	public static function isKnownClientVisibility(string $visibility): bool {
		return array_key_exists(strtolower(trim($visibility)), self::CLIENT_VISIBILITIES);
	}

	/**
	 * The visibilities a client may ask for, for telling somebody which ones
	 * those are when they asked for something else.
	 *
	 * @return string[]
	 */
	public static function clientVisibilities(): array {
		return array_keys(self::CLIENT_VISIBILITIES);
	}

	/**
	 * The hashtags on this item.
	 *
	 * Only a Note actually stores any — it overrides this — but the tags belong
	 * to the exported status entity, which is built here, and `StreamRequest`
	 * asks any Stream for them when it writes one.
	 *
	 * @return string[]
	 */
	public function getHashtags(): array {
		return [];
	}

	/** Translate this app's vocabulary back into what a client expects. */
	public static function visibilityForClient(string $visibility): string {
		return ($visibility === self::TYPE_FOLLOWERS) ? 'private' : $visibility;
	}

	/**
	 * Mastodon's notification type for each notification sub-type. Kept as one
	 * map so the `types`/`exclude_types` API filter and the exported entity
	 * cannot drift apart.
	 */
	/**
	 * A notification this instance raises that is not an activity anybody
	 * sent: a poll of yours closing, an account you asked about posting, a
	 * moderator warning you, or a block cutting your follows. Mastodon
	 * documents a type for each; there is no ActivityPub verb for any of them,
	 * so the subtype is this app's own name for the event.
	 */
	public const SUBTYPE_POLL = 'PollClosed';
	public const SUBTYPE_STATUS = 'NewStatus';
	public const SUBTYPE_WARNING = 'ModerationWarning';
	public const SUBTYPE_SEVERED = 'SeveredRelationships';

	private const NOTIFICATION_TYPES = [
		Like::TYPE => 'favourite',
		Announce::TYPE => 'reblog',
		Mention::TYPE => 'mention',
		Update::TYPE => 'update',
		Follow::TYPE => 'follow',
		Follow::TYPE_REQUEST => 'follow_request',
		self::SUBTYPE_POLL => 'poll',
		self::SUBTYPE_STATUS => 'status',
		self::SUBTYPE_WARNING => 'moderation_warning',
		self::SUBTYPE_SEVERED => 'severed_relationships',
	];

	private string $activityId = '';
	private string $content = '';
	private string $visibility = '';
	/** BCP 47, normalised; empty when nobody said — never a guess, see normalizeLanguage() */
	private string $language = '';
	/** when the author last edited this, ISO-8601 — the ActivityPub `updated` */
	private string $updated = '';
	private string $attributedTo = '';
	private string $inReplyTo = '';
	/** FEP-044f: the id of the object this post quotes, empty when it quotes none */
	private string $quote = '';
	/** FEP-044f: the quoted author's stamp of approval, once one has been granted */
	private string $quoteAuthorization = '';
	private array $attachments = [];
	private array $mentions = [];
	private array $emojis = [];
	private bool $sensitive = false;
	private string $conversation = '';
	private ?Cache $cache = null;
	private int $publishedTime = 0;
	private ?StreamAction $action = null;
	private string $timeline = '';
	private bool $filterDuplicate = false;
	private bool $pinned = false;
	private ?StreamCard $card = null;

	/**
	 * The parents already looked up in this request, keyed by their
	 * ActivityPub id: `{'https://…' => [status nid, author nid]}`.
	 *
	 * A timeline of replies to the same thread would otherwise ask the database
	 * for the same parent once per reply.
	 *
	 * @var array<string, array{int, int}>
	 */
	private static array $replyParents = [];

	/**
	 * The quoted statuses already exported in this request, keyed by their
	 * ActivityPub id **and the viewer it was resolved for**; `null` for one this
	 * instance does not hold, or that the viewer may not see. A quote that goes
	 * round tends to be quoted by several of the posts on one page.
	 *
	 * The viewer is half the key because the value is a filtered copy: one
	 * request serves one reader under PHP-FPM, but a worker process or a queued
	 * job exports timelines for several actors in a row, and a memo keyed by
	 * post alone would hand the first reader's copy to the second.
	 *
	 * @var array<string, ?array>
	 */
	private static array $quotedStatuses = [];

	/**
	 * How deep the export currently is inside a chain of quotes. A quote of a
	 * quote of a quote is a lookup and a nested entity per level; Mastodon
	 * stops after the first and so does this.
	 */
	private static int $quoteDepth = 0;

	public function __construct(?ACore $parent = null) {
		parent::__construct($parent);
	}

	/**
	 * @return string
	 */
	public function getActivityId(): string {
		return $this->activityId;
	}

	/**
	 * @param string $activityId
	 *
	 * @return Stream
	 */
	public function setActivityId(string $activityId): Stream {
		$this->activityId = $activityId;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}

	/**
	 * @param string $content
	 *
	 * @return Stream
	 */
	public function setContent(string $content): Stream {
		$this->content = $content;

		return $this;
	}

	/**
	 * @param string $visibility
	 *
	 * @return Stream
	 */
	public function setVisibility(string $visibility): self {
		$this->visibility = $visibility;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getVisibility(): string {
		return $this->visibility;
	}

	/**
	 * The content warning. On ActivityPub this is the object's `summary`
	 * (which is also the database column), so the two accessors share one field —
	 * a remote CW survives the AP import and a local one survives the save.
	 *
	 * @return string
	 */
	public function getSpoilerText(): string {
		return $this->getSummary();
	}

	/**
	 * @param string $text
	 *
	 * @return Stream
	 */
	public function setSpoilerText(string $text): self {
		$this->setSummary($text);

		return $this;
	}

	/**
	 * The language of the content as a BCP 47 tag, or an empty string when
	 * nobody said. This used to default to `'en'`, so every local post
	 * federated as English and every remote post was shown as English
	 * whatever it was written in; an empty value is the honest answer and
	 * becomes `null` in the client format, which Mastodon allows.
	 *
	 * @return string
	 */
	public function getLanguage(): string {
		return $this->language;
	}

	/**
	 * Anything that is not a language tag is dropped rather than federated:
	 * the value ends up as a key of `contentMap` on every other server.
	 *
	 * @param string $language
	 *
	 * @return $this
	 */
	public function setLanguage(string $language): self {
		$this->language = self::normalizeLanguage($language);

		return $this;
	}

	/**
	 * A language tag as this app is willing to federate it: a 2–3 letter
	 * primary subtag, optionally a script and a region, cased the way BCP 47
	 * does (`pt-BR`, `zh-Hant-TW`). Anything else — a language name, markup,
	 * a variant this app does not understand — becomes an empty string, so
	 * the caller's default applies. Loose on purpose: Mastodon's own list is
	 * mostly ISO 639-1 with a handful of regional variants, and a stricter
	 * check would need a registry that ages.
	 */
	public static function normalizeLanguage(string $language): string {
		$language = str_replace('_', '-', trim($language));
		if (!preg_match('/^([a-z]{2,3})(?:-([a-z]{4}))?(?:-([a-z]{2}|\d{3}))?$/i', $language, $m)) {
			return '';
		}

		$tag = strtolower($m[1]);
		if (($m[2] ?? '') !== '') {
			$tag .= '-' . ucfirst(strtolower($m[2]));
		}
		if (($m[3] ?? '') !== '') {
			$tag .= '-' . strtoupper($m[3]);
		}

		return $tag;
	}

	/**
	 * When the author last edited the post, as the ActivityPub `updated`
	 * property; empty for a post that was never edited.
	 *
	 * Mastodon reads an `Update{Note}` without `updated` as an implicit
	 * update — it refreshes poll counters and discards the content change —
	 * so every edit has to carry one, and `published` has to stay what it
	 * was: it is the creation time, and moving it is not an edit to anybody.
	 *
	 * @return string
	 */
	public function getUpdated(): string {
		return $this->updated;
	}

	public function setUpdated(string $updated): self {
		$this->updated = $updated;

		return $this;
	}

	/**
	 * The `updated` column of a stream row, as the ActivityPub property.
	 *
	 * The column is a datetime and holds UTC — `StreamRequest` converts before
	 * it binds — so the instant survives, while the exact text a peer sent does
	 * not: an `updated` of `2026-09-12T10:00:00+02:00` reads back as
	 * `2026-09-12T08:00:00Z`. That is the same moment written the way this app
	 * writes its own edits (`PostService::editPost()`), and nothing anywhere
	 * compares the two as strings.
	 *
	 * Empty for a row with no edit time, which is `NULL` in the column and the
	 * ordinary case: most posts are never edited.
	 */
	private static function updatedFromRow(string $stored): string {
		if ($stored === '') {
			return '';
		}

		try {
			return (new DateTime($stored, new DateTimeZone('UTC')))
				->setTimezone(new DateTimeZone('UTC'))
				->format('Y-m-d\TH:i:s\Z');
		} catch (Exception) {
			return '';
		}
	}

	/**
	 * @return string
	 */
	public function getAttributedTo(): string {
		return $this->attributedTo;
	}

	/**
	 * @param string $attributedTo
	 *
	 * @return Stream
	 */
	public function setAttributedTo(string $attributedTo): Stream {
		$this->attributedTo = $attributedTo;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getInReplyTo(): string {
		return $this->inReplyTo;
	}

	/**
	 * @param string $inReplyTo
	 *
	 * @return Stream
	 */
	public function setInReplyTo(string $inReplyTo): Stream {
		$this->inReplyTo = $inReplyTo;

		return $this;
	}

	/**
	 * The id of the post this one quotes, empty when it quotes none.
	 *
	 * FEP-044f calls this `quote`; Mastodon 4.5 emits that name and keeps
	 * emitting the older `quoteUrl`/`_misskey_quote` aliases beside it, and
	 * reads any of them. Only ever an id here: the quoted post is a row of its
	 * own, fetched like a reply's parent, never a copy embedded in this one.
	 */
	public function getQuote(): string {
		return $this->quote;
	}

	public function setQuote(string $quote): self {
		$this->quote = $quote;

		return $this;
	}

	/**
	 * The quoted author's approval of this quote, as the URI their server
	 * handed back in `Accept{QuoteRequest}`. Mastodon dereferences it before it
	 * renders the quoted post inline, so a quote that has one is shown as a
	 * card and one that has none is shown as a bare link.
	 */
	public function getQuoteAuthorization(): string {
		return $this->quoteAuthorization;
	}

	public function setQuoteAuthorization(string $quoteAuthorization): self {
		$this->quoteAuthorization = $quoteAuthorization;

		return $this;
	}

	/**
	 * A quote state that was decided elsewhere — a refusal or a withdrawal —
	 * or an empty string when nothing was. Anything else is derived at export
	 * time from what this instance actually holds; see exportQuoteAsLocal().
	 */
	public function getQuoteState(): string {
		$state = $this->getDetailsAll()[self::DETAIL_QUOTE_STATE] ?? '';

		return is_string($state) ? $state : '';
	}

	public function setQuoteState(string $state): self {
		$this->setDetail(self::DETAIL_QUOTE_STATE, $state);

		return $this;
	}

	/**
	 * @return MediaAttachment[]
	 */
	public function getAttachments(): array {
		return $this->attachments;
	}

	/**
	 * @param MediaAttachment[] $attachments
	 *
	 * @return self
	 */
	public function setAttachments(array $attachments): self {
		$this->attachments = $attachments;

		return $this;
	}

	/**
	 * @return array[] Mastodon CustomEmoji entries used in this status
	 */
	public function getEmojis(): array {
		return $this->emojis;
	}

	public function setEmojis(array $emojis): self {
		$this->emojis = $emojis;

		return $this;
	}

	public function getMentions(): array {
		return $this->mentions;
	}

	public function setMentions(array $mentions): self {
		$this->mentions = $mentions;

		return $this;
	}

	/**
	 * A content warning is itself a statement that the body should not be
	 * shown unasked, which is what `sensitive` means to a client — so a post
	 * carrying one is sensitive whether or not the flag was set as well.
	 *
	 * @return bool
	 */
	public function isSensitive(): bool {
		return $this->sensitive || $this->getSpoilerText() !== '';
	}

	/**
	 * Whether the author pinned this post to their profile. Only the author
	 * can pin, so the flag is a property of the post rather than of the
	 * viewer; it is attached by PinService where a pin state is known.
	 */
	public function isPinned(): bool {
		return $this->pinned;
	}

	public function setPinned(bool $pinned): Stream {
		$this->pinned = $pinned;

		return $this;
	}

	/**
	 * The link preview of this post. Cards are local, derived data attached
	 * by LinkPreviewService where one is known — never part of the wire
	 * object.
	 */
	public function getCard(): ?StreamCard {
		return $this->card;
	}

	public function setCard(?StreamCard $card): Stream {
		$this->card = $card;

		return $this;
	}

	/**
	 * @param bool $sensitive
	 *
	 * @return Stream
	 */
	public function setSensitive(bool $sensitive): Stream {
		$this->sensitive = $sensitive;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getConversation(): string {
		return $this->conversation;
	}

	/**
	 * @param string $conversation
	 *
	 * @return Stream
	 */
	public function setConversation(string $conversation): Stream {
		$this->conversation = $conversation;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getPublishedTime(): int {
		return $this->publishedTime;
	}

	/**
	 * @param int $time
	 *
	 * @return Stream
	 */
	public function setPublishedTime(int $time): Stream {
		$this->publishedTime = $time;

		return $this;
	}

	/**
	 */
	public function convertPublished() {
		try {
			$dTime = new DateTime($this->getPublished());
			$this->setPublishedTime($dTime->getTimestamp());
		} catch (Exception $e) {
		}
	}

	/**
	 * @return bool
	 */
	public function hasCache(): bool {
		return ($this->cache !== null);
	}

	/**
	 * @return Cache
	 */
	public function getCache(): ?Cache {
		return $this->cache;
	}

	/**
	 * @param Cache $cache
	 *
	 * @return Stream
	 */
	public function setCache(Cache $cache): Stream {
		$this->cache = $cache;

		return $this;
	}

	public function addCacheItem(string $url): Stream {
		$cacheItem = new CacheItem($url);

		if (!$this->hasCache()) {
			$this->setCache(new Cache());
		}

		$this->getCache()
			->addItem($cacheItem);

		return $this;
	}

	public function getAction(): ?StreamAction {
		return $this->action;
	}

	public function setAction(StreamAction $action): Stream {
		$this->action = $action;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function hasAction(): bool {
		return ($this->action !== null);
	}

	/**
	 * @return string
	 */
	public function getTimeline(): string {
		return $this->timeline;
	}

	/**
	 * @param string $timeline
	 *
	 * @return Stream
	 */
	public function setTimeline(string $timeline): self {
		$this->timeline = $timeline;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isFilterDuplicate(): bool {
		return $this->filterDuplicate;
	}

	/**
	 * @param bool $filterDuplicate
	 *
	 * @return Stream
	 */
	public function setFilterDuplicate(bool $filterDuplicate): Stream {
		$this->filterDuplicate = $filterDuplicate;

		return $this;
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);

		$this->setEmojis($this->extractEmojisFromTag($data));

		$this->setInReplyTo($this->validate(self::AS_ID, 'inReplyTo', $data, ''));
		$this->setQuote($this->quoteIdOf($data));
		$this->setQuoteAuthorization($this->validate(self::AS_ID, 'quoteAuthorization', $data, ''));
		$this->setAttributedTo($this->validate(self::AS_ID, 'attributedTo', $data, ''));
		$this->setSensitive($this->getBool('sensitive', $data, false));
		$this->setObjectId($this->get('object', $data, ''));
		$this->setConversation($this->validate(self::AS_ID, 'conversation', $data, ''));
		$this->setContent($this->get('content', $data, ''));
		$this->setLanguage(self::languageOf($data));
		$this->setUpdated($this->validate(self::AS_DATE, 'updated', $data, ''));
		try {
			$this->importAttachments($this->getArray('attachment', $data, []));
		} catch (ItemAlreadyExistsException $e) {
		}
		$this->convertPublished();

		if (isset($data['likes']['totalItems'])) {
			$remoteLikes = (int)$data['likes']['totalItems'];
			$this->setDetailInt('likes', $remoteLikes);
			$this->setDetailInt('remote_likes', $remoteLikes);
		}
		if (isset($data['shares']['totalItems'])) {
			$remoteShares = (int)$data['shares']['totalItems'];
			$this->setDetailInt('boosts', $remoteShares);
			$this->setDetailInt('remote_boosts', $remoteShares);
		}
		if (isset($data['replies']['totalItems'])) {
			$this->setDetailInt('replies', (int)$data['replies']['totalItems']);
		}
	}

	/**
	 * The language a wire object declares: a top-level `language` where a
	 * server sends one, otherwise the key of its `contentMap` — Mastodon
	 * sends nothing else. Empty when it declares none.
	 */
	private static function languageOf(array $data): string {
		$language = self::normalizeLanguage((string)($data['language'] ?? ''));
		if ($language !== '') {
			return $language;
		}

		foreach (['contentMap', 'summaryMap'] as $map) {
			$translations = $data[$map] ?? null;
			if (!is_array($translations)) {
				continue;
			}

			// the keys are language tags; PHP turns a numeric one into an int,
			// and no language tag is a number
			foreach ($translations as $tag => $ignored) {
				if (!is_string($tag)) {
					continue;
				}

				$language = self::normalizeLanguage($tag);
				if ($language !== '') {
					return $language;
				}
			}
		}

		return '';
	}

	/**
	 * @throws ItemAlreadyExistsException
	 */
	public function importAttachments(array $list): void {
		$urlGenerator = Server::get(IURLGenerator::class);

		$new = [];
		foreach ($list as $item) {
			// A signed Create is authenticated, not trusted: a peer may list as
			// many attachments as it likes, and each one is a row written and a
			// file queued for download inside the inbox request. Real posts carry
			// a handful (Mastodon allows four), so the rest of a list of fifty
			// thousand is dropped rather than imported.
			if (count($new) >= self::MAX_ATTACHMENTS) {
				break;
			}

			try {
				/** @var Document $attachment */
				$attachment = AP::instance()->getItemFromData($item, $this);
			} catch (Exception $e) {
				continue;
			}

			if ($attachment->getType() !== Document::TYPE
				&& $attachment->getType() !== Image::TYPE) {
				continue;
			}

			try {
				$attachment->setUrl(
					$this->validateEntryString(ACore::AS_URL, $attachment->getUrl())
				);
			} catch (InvalidResourceEntryException $e) {
				continue;
			}

			if ($attachment->getUrl() === '') {
				continue;
			}

			try {
				$interface = AP::instance()->getInterfaceFromType($attachment->getType());
			} catch (ItemUnknownException $e) {
				continue;
			}

			$interface->save($attachment);
			$new[] = $attachment->convertToMediaAttachment($urlGenerator);
		}

		$this->setAttachments($new);
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		try {
			$dTime = new DateTime($this->get('published_time', $data, 'yesterday'));
			$this->setPublishedTime($dTime->getTimestamp());
		} catch (Exception $e) {
		}

		$this->setActivityId($this->validate(self::AS_ID, 'activity_id', $data, ''));
		$this->setContent($this->validate(self::AS_CONTENT, 'content', $data, ''));
		$this->setSensitive($this->getBool('sensitive', $data, false));
		$this->setObjectId($this->validate(self::AS_ID, 'object_id', $data, ''));
		$this->setAttributedTo($this->validate(self::AS_ID, 'attributed_to', $data, ''));
		$this->setInReplyTo($this->validate(self::AS_ID, 'in_reply_to', $data));
		$this->setDetailsAll($this->getArray('details', $data, []));

		// Five fields that used to be read out of the stored wire object and
		// nowhere else, because none of them had a column;
		// Version1000Date20260912000007 gave each one its own, and the column
		// is what is read now. The reasoning that put them in the JSON is kept
		// below, at the fallback, because the fallback is still what a row
		// written before that step is read through.
		//
		// `tag`, the mentions and hashtags a post names: re-exported on every
		// Update and every outbox entry, so a post that loses it tells its
		// peers that the people it names are not named by it.
		$this->setTags($this->validateArray(self::AS_TAGS, 'tags', $data, []));
		// the language and the edit stamp, both rewritten by an Update — a
		// remote edit through NoteInterface as much as a local one through
		// PostService::editPost()
		$this->setLanguage($this->get('language', $data, ''));
		$this->setUpdated(self::updatedFromRow($this->get('updated', $data, '')));
		// the quote and its approval, the same kind of thing: a property of the
		// wire object, rewritten whenever the wire object is
		$this->setQuote($this->validate(self::AS_ID, 'quote', $data, ''));
		$this->setQuoteAuthorization($this->validate(self::AS_ID, 'quote_authorization', $data, ''));

		$source = $this->get('source', $data, '');
		if ($source !== '') {
			$sourceData = json_decode($source, true);
			if (is_array($sourceData)) {
				// Emoji still come from the raw wire object rather than from
				// the `tags` column: an emoji tag carries an `icon`, and what
				// `AS_TAGS` validation keeps of a tag is its type, href and
				// name — the column holds the post's tags as the model holds
				// them, which is already without the icons.
				$this->setEmojis($this->extractEmojisFromTag($sourceData));

				// The fallback, for a row stored before the columns existed and
				// not yet reached by the BackfillStreamPostFields repair step:
				// the schema change lands during `occ upgrade` and the backfill
				// runs after it, so an instance serves reads in between. Each
				// field falls back on its own, because a post may legitimately
				// have four of the five empty.
				if ($this->getTags() === []) {
					$this->setTags($this->validateArray(self::AS_TAGS, 'tag', $sourceData, []));
				}
				if ($this->getLanguage() === '') {
					$this->setLanguage(self::languageOf($sourceData));
				}
				if ($this->getUpdated() === '') {
					$this->setUpdated($this->validate(self::AS_DATE, 'updated', $sourceData, ''));
				}
				if ($this->getQuote() === '') {
					$this->setQuote($this->quoteIdOf($sourceData));
				}
				if ($this->getQuoteAuthorization() === '') {
					$this->setQuoteAuthorization(
						$this->validate(self::AS_ID, 'quoteAuthorization', $sourceData, '')
					);
				}
				$details = $this->getDetailsAll();
				if (!array_key_exists('remote_likes', $details) && isset($sourceData['likes']['totalItems'])) {
					$remoteLikes = (int)$sourceData['likes']['totalItems'];
					$this->setDetailInt('remote_likes', $remoteLikes);
					if (!array_key_exists('likes', $details) || $details['likes'] === 0) {
						$this->setDetailInt('likes', $remoteLikes);
					}
				}
				if (!array_key_exists('remote_boosts', $details) && isset($sourceData['shares']['totalItems'])) {
					$remoteBoosts = (int)$sourceData['shares']['totalItems'];
					$this->setDetailInt('remote_boosts', $remoteBoosts);
					if (!array_key_exists('boosts', $details) || $details['boosts'] === 0) {
						$this->setDetailInt('boosts', $remoteBoosts);
					}
				}
				if (isset($sourceData['replies']['totalItems'])) {
					$this->setDetailInt('replies', (int)$sourceData['replies']['totalItems']);
				}
			}
		}

		$this->setFilterDuplicate($this->getBool('filter_duplicate', $data, false));
		// hydrated rather than handed on as the stored arrays, so that the one
		// place that knows how a media link is shaped gets to rebuild it: the
		// stored link is absolute and may name an address we no longer answer on
		$attachments = [];
		foreach ($this->getArray('attachments', $data, []) as $attachment) {
			$attachments[] = (new MediaAttachment())->import($attachment);
		}
		$this->setAttachments($attachments);
		$this->setMentions($this->getDetails('mentions'));
		$this->setVisibility($this->get('visibility', $data));

		$cache = new Cache();
		$cache->import($this->getArray('cache', $data, []));
		$this->setCache($cache);
	}

	#[\Override]
	public function importFromLocal(array $data) {
		parent::importFromLocal($data);

		$this->setId($this->get('url', $data));
		$this->setUrl($this->get('url', $data));
		$this->setLocal($this->getBool('local', $data));
		$this->setContent($this->get('content', $data));
		$this->setSensitive($this->getBool('sensitive', $data));
		$this->setSpoilerText($this->get('spoiler_text', $data));
		$this->setVisibility(self::visibilityFromClient($this->get('visibility', $data)));
		$this->setLanguage($this->get('language', $data));

		$action = new StreamAction();
		$action->updateValueBool(StreamAction::LIKED, $this->getBool('favourited', $data));
		$action->updateValueBool(StreamAction::BOOSTED, $this->getBool('reblogged', $data));
		$this->setAction($action);

		try {
			$dTime = new DateTime($this->get('created_at', $data, 'yesterday'));
			$this->setPublishedTime($dTime->getTimestamp());
		} catch (Exception $e) {
		}

		//		"in_reply_to_id" => null,
		//			"in_reply_to_account_id" => null,
		//			'replies_count' => 0,
		//			'reblogs_count' => 0,
		//			'favourites_count' => 0,
		//			'muted' => false,
		//			'bookmarked' => false,
		//			"reblog" => null,
		//			'noindex' => false

		$attachments = [];
		foreach ($this->getArray('media_attachments', $data) as $dataAttachment) {
			$attachment = new MediaAttachment();
			$attachment->import($dataAttachment);
			$attachments[] = $attachment;
		}
		$this->setAttachments($attachments);

		$this->setMentions($this->getArray('mentions', $data));

		// import from cache with new format !
		$actor = new Person();
		$actor->importFromLocal($this->getArray('account', $data));
		$actor->setExportFormat(ACore::FORMAT_LOCAL);
		$this->setActor($actor);
		//		$this->setCompleteDetails(true);
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function exportAsActivityPub(): array {
		$result = array_merge(
			parent::exportAsActivityPub(),
			[
				'content' => $this->getContent(),
				// `attributedTo` is always set to a full actor URI (see
				// PostService and PollService), so it is emitted as-is. It used
				// to be prefixed with `urlSocial`, which produced a valid value
				// only because nothing ever calls `setUrlSocial()` on a stream —
				// that is done on Person rows alone. The day anything did, every
				// outgoing post would have carried a doubled URL and remote
				// thread resolution would have stopped working.
				'attributedTo' => $this->getAttributedTo(),
				'inReplyTo' => $this->getInReplyTo(),
				'sensitive' => $this->isSensitive(),
				'conversation' => $this->getConversation(),
				'updated' => $this->getUpdated(),
			],
			$this->exportQuoteAsActivityPub(),
			$this->exportRepliesAsActivityPub(),
			$this->exportInteractionPolicy(),
			$this->exportLanguageMaps()
		);

		// TODO: use exportFormat
		if ($this->isCompleteDetails()) {
			$result = array_merge(
				$result,
				[
					'details' => $this->getDetailsAll(),
					'action' => ($this->hasAction()) ? $this->getAction() : [],
					'cache' => ($this->hasCache()) ? $this->getCache() : '',
					'publishedTime' => $this->getPublishedTime()
				]
			);
		}

		$this->cleanArray($result);

		return $result;
	}

	/**
	 * The `replies` collection, which is how a peer discovers a thread.
	 *
	 * Everything else publishes one and reads it: a reply reaches the instances
	 * that hold the post it answers and nowhere else, so a reader on a third
	 * instance sees a post with no replies unless there is a collection to walk.
	 * Without it, a conversation that starts here is a conversation only the
	 * participants' own servers ever see whole.
	 *
	 * Only for a local post. A remote one's replies live on the server that
	 * holds it, under an id of its choosing; naming a collection here that this
	 * instance does not serve, under an id it does not own, would send every
	 * reader to a 404.
	 *
	 * `first` is a URL rather than an inlined page: the page it names is the
	 * one the route serves, and a page written out here would have to be
	 * queried for during a model export that has no database.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function exportRepliesAsActivityPub(): array {
		if (!$this->isLocal() || $this->getId() === '') {
			return [];
		}

		$id = $this->getId() . self::REPLIES_PATH;

		return [
			'replies' => [
				'id' => $id,
				'type' => OrderedCollection::TYPE,
				'first' => $id . '?page=1',
			],
		];
	}

	/**
	 * `contentMap`/`summaryMap`: the text keyed by its language, which is how
	 * ActivityPub says what language a post is in and the only place Mastodon
	 * looks. Nothing when the language is unknown — a wrong key is worse than
	 * none — and no map for an empty text.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function exportLanguageMaps(): array {
		$language = $this->getLanguage();
		if ($language === '') {
			return [];
		}

		$maps = [];
		if ($this->getContent() !== '') {
			$maps['contentMap'] = [$language => $this->getContent()];
		}
		if ($this->getSummary() !== '') {
			$maps['summaryMap'] = [$language => $this->getSummary()];
		}

		return $maps;
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function exportAsLocal(): array {
		$actions = ($this->hasAction()) ? $this->getAction()->getValues() : [];
		$favorited = false;
		$reblogged = false;
		$bookmarked = false;
		foreach ($actions as $action => $value) {
			if ($value) {
				switch ($action) {
					case StreamAction::BOOSTED:
						$reblogged = true;
						break;
					case StreamAction::LIKED:
						$favorited = true;
						break;
					case StreamAction::BOOKMARKED:
						$bookmarked = true;
						break;
				}
			}
		}
		[$inReplyToId, $inReplyToAccountId] = $this->resolveInReplyTo();

		$result = [
			'local' => $this->isLocal(),
			'content' => $this->getContent(),
			'sensitive' => $this->isSensitive(),
			'spoiler_text' => $this->getSpoilerText(),
			'visibility' => self::visibilityForClient($this->getVisibility()),
			// nullable in Mastodon's entity, and null is what "nobody said" is
			'language' => ($this->getLanguage() === '') ? null : $this->getLanguage(),
			'in_reply_to_id' => $inReplyToId,
			'in_reply_to_account_id' => $inReplyToAccountId,
			'quote' => $this->exportQuoteAsLocal(),
			'mentions' => $this->exportMentionsAsLocal(),
			'emojis' => $this->getEmojis(),
			'tags' => $this->exportTagsAsLocal(),
			'replies_count' => $this->getDetailInt('replies'),
			'reblogs_count' => $this->getDetailInt('boosts'),
			'favourites_count' => $this->getDetailInt('likes'),
			'favourited' => $favorited,
			'reblogged' => $reblogged,
			'muted' => false,
			'bookmarked' => $bookmarked,
			'pinned' => $this->isPinned(),
			'card' => $this->card?->jsonSerialize(),
			// null, not absent. A Question overwrites this with the real poll;
			// every other status has to carry the key, because the entity's
			// rule here is that a client never has to test for a missing one
			'poll' => null,
			'uri' => $this->getId(),
			'url' => $this->getId(),
			'reblog' => null,
			'media_attachments' => $this->getAttachments(),
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getPublishedTime()) . '.000Z',
			'edited_at' => $this->editedAt(),
			'noindex' => false
		];

		// TODO - store created_at full string with milliseconds ?
		if ($this->hasActor()) {
			$actor = $this->getActor();
			$result['account'] = $actor->exportAsLocal();
		}

		return array_merge(parent::exportAsLocal(), $result);
	}

	/**
	 * The parent of a reply, as the two numeric ids Mastodon addresses it by.
	 *
	 * These were hard-coded to null, and a reply with no `in_reply_to_id` is
	 * not a reply: Elk and Phanpy render it as a fresh top-level post, and the
	 * status handed back from `POST /statuses` lost the link the client needed
	 * to slot it under the post being answered. The row only carries the
	 * parent's ActivityPub id, so the numbers come from a lookup — memoised for
	 * the request, since a thread's replies all name the same parent.
	 *
	 * A parent this instance has never seen has no numeric id here, and null is
	 * then the honest answer.
	 *
	 * @return array{?string, ?string}
	 */
	private function resolveInReplyTo(): array {
		$parentId = $this->getInReplyTo();
		if ($parentId === '') {
			return [null, null];
		}

		if (!array_key_exists($parentId, self::$replyParents)) {
			self::$replyParents[$parentId] = $this->lookupParent($parentId);
		}

		[$nid, $accountNid] = self::$replyParents[$parentId];

		return [
			($nid > 0) ? (string)$nid : null,
			($accountNid > 0) ? (string)$accountNid : null,
		];
	}

	/**
	 * @return array{int, int}
	 */
	private function lookupParent(string $parentId): array {
		try {
			$parent = Server::get(StreamRequest::class)->getStreamById($parentId);
			$author = $parent->hasActor() ? $parent->getActor()->getNid() : 0;

			return [$parent->getNid(), $author];
		} catch (\Throwable $e) {
			return [0, 0];
		}
	}

	/** Forgets the memoised parents; for tests, which share one process. */
	public static function resetReplyParentCache(): void {
		self::$replyParents = [];
	}

	/** Forgets the memoised quoted statuses; for tests, which share one process. */
	public static function resetQuoteCache(): void {
		self::$quotedStatuses = [];
		self::$quoteDepth = 0;
	}

	/**
	 * The id of the quoted object, whichever of the four names a peer used for
	 * it, and whether it arrived as a bare id, an embedded object or a Link.
	 *
	 * `quote` is FEP-044f and what Mastodon 4.5 reads first; `quoteUri` is
	 * Fedibird's, `quoteUrl` Mastodon's own alias, `_misskey_quote` Misskey's.
	 * Mastodon emits the last two beside `quote` to this day, and a server that
	 * predates FEP-044f sends nothing else.
	 */
	private function quoteIdOf(array $data): string {
		foreach (['quote', 'quoteUri', 'quoteUrl', '_misskey_quote'] as $key) {
			$value = $data[$key] ?? null;
			if (is_array($value)) {
				// an embedded object names itself in `id`, a Link in `href`
				$value = $value['id'] ?? $value['href'] ?? null;
			}

			if (!is_string($value) || trim($value) === '') {
				continue;
			}

			$id = $this->validateEntryString(self::AS_ID, trim($value), false);
			if ($id !== '') {
				return $id;
			}
		}

		return '';
	}

	/**
	 * The quote as it goes onto the wire: FEP-044f's `quote`, the two aliases
	 * Mastodon still emits for readers that predate it, and the approval once
	 * there is one. Nothing at all for a post that quotes nothing — the keys
	 * are dropped by cleanArray().
	 *
	 * @return array<string, string>
	 */
	private function exportQuoteAsActivityPub(): array {
		$quote = $this->getQuote();
		if ($quote === '') {
			return [];
		}

		return [
			'quote' => $quote,
			'quoteUrl' => $quote,
			'_misskey_quote' => $quote,
			'quoteAuthorization' => $this->getQuoteAuthorization(),
		];
	}

	/**
	 * Who may quote this post, for our own posts only — somebody else's server
	 * says who may quote theirs.
	 *
	 * Mastodon 4.5 offers no quote button at all for a post that carries no
	 * `interactionPolicy.canQuote`, so a post from here would be unquotable
	 * however open it is. The policy mirrors what QuoteRequestInterface
	 * actually answers: anyone, automatically, for a post addressed to the
	 * public collection, and nobody but the author for anything narrower.
	 *
	 * @return array<string, array<string, array<string, string[]>>>
	 */
	private function exportInteractionPolicy(): array {
		if (!$this->isLocal()) {
			return [];
		}

		$allowed = $this->isQuotable()
			? [self::CONTEXT_PUBLIC]
			: array_filter([$this->getAttributedTo()]);

		return ['interactionPolicy' => ['canQuote' => ['automaticApproval' => $allowed]]];
	}

	/**
	 * Whether this post is open enough to be quoted: addressed to the public
	 * collection, which `public` and `unlisted` both are. The same rule
	 * `PinService::pin()` and `BoostService::create()` apply, and for the same
	 * reason — a quote carries the audience of the quoter, so anything
	 * narrower would be handed to readers the author never addressed.
	 */
	public function isQuotable(): bool {
		return $this->isPublic()
			|| in_array($this->getVisibility(), [self::TYPE_PUBLIC, self::TYPE_UNLISTED], true);
	}

	/**
	 * Mastodon's Quote entity, or null for a post that quotes nothing.
	 *
	 * `accepted` with the quoted status inline is what a client renders as a
	 * card. `pending` is the honest answer while the quoted post is still being
	 * fetched — and the only answer for a post this reader may not see, which
	 * is why the lookup runs as the viewer: a quote must not become a way of
	 * reading somebody's followers-only post.
	 *
	 * @return ?array{state: string, quoted_status: ?array}
	 */
	private function exportQuoteAsLocal(): ?array {
		if ($this->getQuote() === '') {
			return null;
		}

		$state = $this->getQuoteState();
		if ($state === self::QUOTE_REJECTED || $state === self::QUOTE_REVOKED) {
			return ['state' => $state, 'quoted_status' => null];
		}

		// What makes a quote accepted is the approval, not the lookup. The
		// stamp is the quoted author's word that their post may be shown
		// inside this one, and FEP-044f is explicit that a quote without one
		// renders as a link. Holding the quoted post answers a different
		// question — whether we *could* show it — and reading the state off
		// that reported every quote as accepted the moment it was written,
		// including ones the author went on to refuse.
		if ($this->getQuoteAuthorization() === '') {
			return ['state' => self::QUOTE_PENDING, 'quoted_status' => null];
		}

		// Approved, but the post may still be missing here or closed to this
		// reader. That is not `pending`: pending says the author has not
		// answered, and they have.
		return ['state' => self::QUOTE_ACCEPTED, 'quoted_status' => $this->resolveQuoted()];
	}

	/**
	 * The quoted post as a status entity, or null when this instance does not
	 * hold it or the viewer may not see it.
	 */
	private function resolveQuoted(): ?array {
		if (self::$quoteDepth > 0) {
			return null;
		}

		$streamRequest = Server::get(StreamRequest::class);
		$key = $streamRequest->getViewerId() . "\0" . $this->getQuote();
		if (array_key_exists($key, self::$quotedStatuses)) {
			return self::$quotedStatuses[$key];
		}

		self::$quoteDepth++;
		try {
			$quoted = $streamRequest->getStreamById($this->getQuote(), true, ACore::FORMAT_LOCAL);
			self::$quotedStatuses[$key] = $quoted->exportAsLocal();
		} catch (\Throwable $e) {
			self::$quotedStatuses[$key] = null;
		} finally {
			self::$quoteDepth--;
		}

		return self::$quotedStatuses[$key];
	}

	/**
	 * The hashtags as Mastodon's Tag entities.
	 *
	 * The app used to emit its own `hashtags: ["foo"]` alongside nothing a
	 * client recognises, so a post's tags were invisible in every client and
	 * there was nothing to tap through to the hashtag timeline.
	 *
	 * @return array<array{name: string, url: string}>
	 */
	private function exportTagsAsLocal(): array {
		$tags = [];
		foreach ($this->getHashtags() as $hashtag) {
			$hashtag = ltrim(trim((string)$hashtag), '#');
			if ($hashtag === '') {
				continue;
			}

			$tags[] = ['name' => $hashtag, 'url' => $this->hashtagUrl($hashtag)];
		}

		return $tags;
	}

	private function hashtagUrl(string $hashtag): string {
		try {
			return Server::get(IURLGenerator::class)->linkToRouteAbsolute(
				'social.Navigation.timeline', ['path' => 'tags/' . $hashtag]
			);
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * The mentions, with every id a string.
	 *
	 * An unresolvable mention is stored with an integer `0` for an id, and a
	 * client that declares the field a string fails to decode the mention —
	 * and with it the status carrying it.
	 */
	private function exportMentionsAsLocal(): array {
		return array_map(
			static function (array $mention): array {
				$mention['id'] = (string)($mention['id'] ?? '0');

				return $mention;
			},
			array_values(array_filter($this->getMentions(), 'is_array'))
		);
	}

	/**
	 * When this status was last edited, or null if it never was.
	 *
	 * The edit stamp is the ActivityPub `updated`, remote or local. Rows
	 * edited before this app emitted one carry the edit differently: the
	 * edit used to move `published` and leave `published_time` — which
	 * `created_at` is built from — at the original, so for those the two
	 * disagreeing is what says the post was edited.
	 */
	private function editedAt(): ?string {
		if ($this->getUpdated() !== '') {
			try {
				return gmdate('Y-m-d\TH:i:s', (new DateTime($this->getUpdated()))->getTimestamp()) . '.000Z';
			} catch (Exception $e) {
				return null;
			}
		}

		$published = $this->getPublished();
		if ($published === '' || $this->getPublishedTime() === 0) {
			return null;
		}

		try {
			$edited = (new DateTime($published))->getTimestamp();
		} catch (Exception $e) {
			return null;
		}

		// a second of slack: creating a post writes both from the same moment,
		// but not from the same value
		if ($edited - $this->getPublishedTime() < 2) {
			return null;
		}

		return gmdate('Y-m-d\TH:i:s', $edited) . '.000Z';
	}

	/**
	 * Mastodon's name for a notification sub-type, or an empty string for a
	 * sub-type Mastodon has no notification for.
	 */
	public static function notificationTypeOfSubType(string $subType): string {
		return self::NOTIFICATION_TYPES[$subType] ?? '';
	}

	/**
	 * The notification sub-types that the given Mastodon notification types
	 * select. Unrecognised types select nothing, so a caller that asked only
	 * for those gets an empty list back and must not query at all.
	 *
	 * @param string[] $types
	 *
	 * @return string[]
	 */
	public static function subTypesOfNotificationTypes(array $types): array {
		$subTypes = array_flip(self::NOTIFICATION_TYPES);

		return array_values(array_intersect_key($subTypes, array_flip($types)));
	}

	#[\Override]
	public function exportAsNotification(): array {
		$type = self::notificationTypeOfSubType($this->getSubType());

		$result = [
			'id' => (string)$this->getNid(),
			'type' => $type,
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getPublishedTime()) . '.000Z',
			'status' => $this->getObject(),
		];

		if ($this->hasActor()) {
			$actor = $this->getActor();
			$result['account'] = $actor->exportAsLocal();
		}

		return array_merge(parent::exportAsNotification(), $result);
	}

	#[\Override]
	public function jsonSerialize(): array {
		$result = parent::jsonSerialize();

		// `attachment` is the ActivityPub name for these; the client format
		// already carries them as `media_attachments`, and a second copy under
		// a key Mastodon does not define was only ever confusing.
		if ($this->getExportFormat() !== self::FORMAT_LOCAL) {
			$result['attachment'] = $this->getAttachments();
		}

		return $result;
	}

	/**
	 * The client format is a fixed key set, and nothing may be filtered out of
	 * it.
	 *
	 * `Note::jsonSerialize()` runs its result through `cleanArray()`, which
	 * drops every empty string and empty list. For an ActivityPub document that
	 * is the intent — an absent property is simply absent. For a Mastodon
	 * status entity it is silent data loss: a post with no content warning lost
	 * `spoiler_text`, one with no attachments lost `media_attachments`, one
	 * that is not a reply lost `in_reply_to_id`, and a client that declares
	 * those keys non-optional — which Mastodon's own behaviour entitles it to —
	 * failed to decode the status, and with it the page it arrived on.
	 *
	 * It went unnoticed because `ApiContractTest` asserted against
	 * `exportAsLocal()`, which is not what a client receives.
	 */
	#[\Override]
	protected function cleanArray(array &$arr) {
		if ($this->getExportFormat() === self::FORMAT_LOCAL) {
			return;
		}

		parent::cleanArray($arr);
	}
}
