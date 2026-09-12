<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Post;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class PostService {
	public const POLL_MAX_OPTIONS = 4;

	/**
	 * How long a poll may run, in seconds, and the shortest it may run for.
	 * Mastodon's own bounds, and what `InstanceService` advertises as
	 * `polls.min_expiration`/`max_expiration`: a client offers exactly the
	 * durations it is told about, so a ceiling of its own here silently turned
	 * every longer poll a client offered into a shorter one.
	 */
	public const POLL_MIN_EXPIRATION = 300;
	public const POLL_MAX_EXPIRATION = 2629746;

	/**
	 * The languages whose region subtag names a different written language,
	 * and which Mastodon therefore offers as post languages of their own.
	 * For every other language a Nextcloud locale's region describes the UI,
	 * not the text: `en_GB` is English.
	 */
	private const REGIONAL_LANGUAGES = ['pt', 'zh'];

	public function __construct(
		private StreamService $streamService,
		private AccountService $accountService,
		private ActivityService $activityService,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private ModerationService $moderationService,
		private StatusRevisionService $revisionService,
		private NotificationService $notificationService,
		private LinkifyService $linkifyService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param Post $post
	 * @param string $token
	 *
	 * @return ACore
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 * @throws UnauthorizedFediverseException
	 */
	public function createPost(Post $post, string &$token = ''): ?ACore {
		$this->moderationService->assertNotSuspended($post->getActor()->getId());
		$this->assertWithinLength($post->getContent(), $post->getSpoilerText());
		$this->fixRecipientAndHashtags($post);

		$note = new Note();
		if ($post->hasPoll()) {
			$poll = $post->getPoll();
			$options = array_slice(array_values(array_filter(
				array_map('strval', $poll['options'] ?? []),
				static fn (string $option): bool => trim($option) !== ''
			)), 0, self::POLL_MAX_OPTIONS);
			if (count($options) < 2) {
				throw new \InvalidArgumentException('a poll needs at least two options');
			}

			$note = new Question();
			$note->setPollData(
				$options,
				(bool)($poll['multiple'] ?? false),
				min(
					max((int)($poll['expires_in'] ?? 86400), self::POLL_MIN_EXPIRATION),
					self::POLL_MAX_EXPIRATION
				)
			);
		}
		$actor = $post->getActor();
		$this->streamService->assignItem($note, $actor, $post->getType());

		$note->setAttributedTo($actor->getId());
		// The warning rides as the object's `summary`, which is what every other
		// server reads it from — and unlike the content it is plain text
		// wherever it is read: `spoiler_text` to a client, interpolated rather
		// than rendered by this app's own frontend. Encoding it entity by entity
		// federated `Bob&#039;s finale` to every other instance and baked those
		// entities into the next edit, so markup is dropped instead of encoded:
		// nothing downstream has to undo it, and editPost() agrees.
		$note->setSpoilerText(strip_tags($post->getSpoilerText()));
		$note->setSensitive($post->isSensitive());
		$note->setAttachments($post->getMedias());
		$note->setVisibility($post->getType());
		$note->setLanguage($this->languageFor($post->getLanguage(), $actor));

		$this->streamService->replyTo($note, $post->getReplyTo());
		$quotedAuthor = $this->applyQuote($note, $post->getQuotedId());
		$this->streamService->addRecipients($note, $post->getType(), $post->getTo());
		$this->streamService->addHashtags($note, $post->getHashtags());
		$this->streamService->addCustomEmojis(
			$note, $post->getContent(), $post->getSpoilerText()
		);
		//		$this->streamService->addAttachments($note, $post->getDocuments());

		// Last, because the links are built out of the `tag` array the three
		// calls above assemble: a mention is only linked once it is known which
		// actor it resolved to, and only a mention the tags vouch for is linked
		// at all. Nothing here can name somebody the tags do not.
		$note->setContent($this->linkifyService->toHtml($post->getContent(), $note->getTags()));

		// the stored source is what survives the database and federates on
		// Update — a poll's options and counts, and for every post the
		// language, which has no column of its own: snapshot the assembled
		// object
		$this->snapshotSource($note);

		$token = $this->activityService->createActivity($actor, $note, $activity);
		$this->accountService->cacheLocalActorDetailCount($actor);

		// after the post exists: the request names it as the instrument, and
		// the quoted author's server dereferences both before approving
		if ($quotedAuthor !== null) {
			$this->requestQuoteApproval($actor, $note, $quotedAuthor);
		}

		$this->logger->debug('Activity: ' . json_encode($activity));

		return $activity;
	}

	/**
	 * @param ?string $language the language the client sent, null or empty to
	 *                          keep the post's; a post that never had one gets
	 *                          the poster's default
	 *
	 * @throws \Exception
	 */
	public function editPost(
		int $nid, Person $actor, string $content, ?string $spoilerText = null, ?bool $sensitive = null,
		?string $language = null,
	): Stream {
		$this->moderationService->assertNotSuspended($actor->getId());
		$stream = $this->streamService->getStreamByNid($nid);

		if ($stream->getAttributedTo() !== $actor->getId()) {
			throw new \Exception('Not authorized to edit this post');
		}

		$this->assertWithinLength(
			$content, $spoilerText ?? $stream->getSpoilerText()
		);

		// the revision recorded below is the version being replaced, so it has
		// to be taken before any of the fields are overwritten
		$original = clone $stream;
		// the post's existing tags: an edit does not re-address anybody, so the
		// people it may link to are the people it already named
		$stream->setContent($this->linkifyService->toHtml($content, $stream->getTags()));
		if ($spoilerText !== null) {
			$stream->setSpoilerText(strip_tags($spoilerText));
		}

		// an edit may write a shortcode that was not there before, and the tag
		// is what makes it render — here and on every instance the Update
		// reaches. Not an address, so this does not widen the audience.
		$this->streamService->addCustomEmojis($stream, $content, $stream->getSpoilerText());
		if ($sensitive !== null) {
			$stream->setSensitive($sensitive);
		}
		$stream->setLanguage($this->languageFor((string)$language, $actor, $stream->getLanguage()));

		// `published` stays the creation time. The edit used to be stamped
		// there instead, and Mastodon — which reads an Update without
		// `updated` as an implicit one — refreshed the poll counters and threw
		// the new content away. UTC and second-resolution like `published`.
		$stream->setUpdated(gmdate('Y-m-d\TH:i:s\Z'));
		$this->snapshotSource($stream);

		$this->streamService->updateStream($stream);
		$this->revisionService->recordEdit($original, $stream);

		$updated = $this->streamService->getStreamByNid($nid);
		$updated->addInstancePath(
			new InstancePath(
				$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			)
		);

		try {
			$this->activityService->updateActivity($actor, $updated);
		} catch (\Exception $e) {
			$this->logger->warning('Failed to federate post update', ['exception' => $e]);
		}

		$this->notificationService->onStatusEdited($updated);
		return $updated;
	}

	/**
	 * Refuses a post longer than the limit this instance advertises as
	 * `configuration.statuses.max_characters`, which nothing enforced: a client
	 * that does not read the limit, or reads a different one, had its post
	 * accepted and federated at whatever length it sent.
	 *
	 * Characters, not bytes — `mb_strlen()`, as the bio cap in `AccountService`
	 * counts — and the spoiler counts towards the same budget, the way Mastodon
	 * measures a status.
	 *
	 * @throws InvalidActionException the refusal ApiController answers 422 with
	 */
	private function assertWithinLength(string $content, string $spoilerText): void {
		if (mb_strlen($content) + mb_strlen($spoilerText) <= InstanceService::MAX_CHARACTERS) {
			return;
		}

		throw new InvalidActionException(
			'a post may not be longer than ' . InstanceService::MAX_CHARACTERS . ' characters'
		);
	}

	/**
	 * Puts the quoted post on the note and addresses its author.
	 *
	 * The quoted post is named by whatever the caller had: the numeric status
	 * id a Mastodon client sends as `quote_id`, or an ActivityPub URI. Only a
	 * post this instance holds can be quoted — the quote has to name a real
	 * object for anyone else to resolve — and only one that is public or
	 * unlisted, which is the rule `PinService::pin()` and
	 * `BoostService::create()` apply: a quote carries the quoted post into the
	 * quoter's audience, and a followers-only post has none of those readers.
	 *
	 * The author is addressed rather than merely notified: they are a recipient
	 * of the post that carries their words, and their server is the one that
	 * has to answer the QuoteRequest that follows — unless that server is this
	 * one, in which case the approval is granted here and now.
	 *
	 * @return ?Person the quoted author, when there is a quote and they are known
	 * @throws InvalidActionException when the post may not be quoted — the
	 *                                exception ApiController already answers 422 with, which is what a
	 *                                client can act on, and the one PinService::pin() raises for the
	 *                                same refusal
	 */
	private function applyQuote(Note $note, string $quotedId): ?Person {
		if ($quotedId === '') {
			return null;
		}

		try {
			$quoted = ctype_digit($quotedId)
				? $this->streamService->getStreamByNid((int)$quotedId)
				: $this->streamService->getStreamById($quotedId);
		} catch (\Exception $e) {
			throw new InvalidActionException('the post to quote is unknown here');
		}

		if (!$quoted->isQuotable()) {
			throw new InvalidActionException('you can only quote a public or unlisted post');
		}

		$note->setQuote($quoted->getId());

		if ($quoted->isLocal()) {
			// this server is the quoted author's server, so the approval is
			// ours to give and there is nobody to ask: sending a QuoteRequest
			// here would be this instance posting to its own inbox and waiting
			// for its own answer. The post is quotable — that was decided two
			// lines up, by the same rule QuoteRequestInterface would apply —
			// so stamp it now, before the source is snapshotted, and the very
			// first delivery carries an approval every peer can dereference.
			$note->setQuoteAuthorization(
				$quoted->getId() . '/quote_authorizations/'
				. QuoteRequestInterface::stamp($note->getId())
			);
			$note->setQuoteState(Stream::QUOTE_ACCEPTED);

			return null;
		}

		try {
			$author = $this->streamService->getAuthorFromPostId($quoted->getId());
		} catch (\Exception $e) {
			// the post is here but its author is not cached: the quote still
			// stands, the author simply learns of it the way anyone else does
			$this->logger->notice('quoting a post whose author cannot be resolved', [
				'quoted' => $quoted->getId(),
				'exception' => $e,
			]);

			return null;
		}

		$note->addCc($author->getId());
		$inbox = ($author->getSharedInbox() !== '') ? $author->getSharedInbox() : $author->getInbox();
		if ($inbox !== '') {
			$note->addInstancePath(
				new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
			);
		}

		return $author;
	}

	/**
	 * FEP-044f: ask the quoted author's server for permission.
	 *
	 * Mastodon 4.5 renders a quote inline only once the quoting post carries a
	 * `quoteAuthorization`, and only grants one in answer to a QuoteRequest —
	 * without this the quote federates as a bare link on every Mastodon
	 * instance. The approval arrives later, as an `Accept{QuoteRequest}`, and
	 * `QuoteRequestInterface` writes it onto the post.
	 *
	 * A failure here is not a failure of the post: it is already published.
	 */
	private function requestQuoteApproval(Person $actor, Stream $note, Person $author): void {
		$request = new QuoteRequest();
		$request->setId($note->getId() . '#quote-request');
		$request->setActor($actor);
		$request->setObjectId($note->getQuote());
		$request->setInstrument($note->getId());
		$request->setToArray([$author->getId()]);

		$inbox = ($author->getSharedInbox() !== '') ? $author->getSharedInbox() : $author->getInbox();
		if ($inbox === '') {
			return;
		}

		$request->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
		);

		try {
			$this->activityService->request($request);
		} catch (\Exception $e) {
			$this->logger->warning('could not ask for approval of a quote', [
				'quote' => $note->getQuote(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * The stored wire object is what a reload reads the language and the edit
	 * stamp from, and what a remote Update rewrites; a local edit has to
	 * rewrite it the same way or the next re-export federates the old text.
	 */
	private function snapshotSource(Stream $stream): void {
		$stream->setSource(json_encode($stream, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * The language a post is written in: what the client said, else what the
	 * post already had, else the poster's default.
	 */
	private function languageFor(string $requested, Person $actor, string $current = ''): string {
		$requested = Stream::normalizeLanguage($requested);
		if ($requested !== '') {
			return $requested;
		}
		if ($current !== '') {
			return $current;
		}

		return $this->defaultLanguageOf($actor);
	}

	/**
	 * The poster's Nextcloud language, which is the best guess this app has
	 * for the language they write in. `IFactory::getUserLanguage()` already
	 * falls back to the instance default and then to English; a locale's
	 * region is dropped unless it names a different written language, so
	 * Mastodon's per-language filters — keyed by plain `de`, not `de-DE` —
	 * still match the post.
	 */
	private function defaultLanguageOf(Person $actor): string {
		$user = ($actor->getUserId() !== '') ? $this->userManager->get($actor->getUserId()) : null;
		$language = Stream::normalizeLanguage($this->l10nFactory->getUserLanguage($user));
		if ($language === '') {
			return 'en';
		}

		$primary = explode('-', $language, 2)[0];

		return in_array($primary, self::REGIONAL_LANGUAGES, true) ? $language : $primary;
	}

	/**
	 * @param Post $post
	 */
	/**
	 * The accounts and hashtags written into the text itself.
	 *
	 * The entities come from `LinkifyService`, which is also what builds the
	 * links in the published HTML: one parse, so what the post addresses, what
	 * its `tag` array names and what its markup links can never be three
	 * different lists. The pair of regular expressions this replaced read a
	 * handle as "everything up to the next space", which swallowed the full
	 * stop at the end of a sentence and addressed `bob@example.invalid.`.
	 */
	public function fixRecipientAndHashtags(Post $post) {
		foreach ($this->linkifyService->entitiesIn($post->getContent()) as $entity) {
			match ($entity['type']) {
				LinkifyService::TYPE_MENTION => $post->addTo($entity['name']),
				LinkifyService::TYPE_HASHTAG => $post->addHashtag($entity['name']),
				default => null,
			};
		}
	}
}
