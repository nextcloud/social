<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use JsonSerializable;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;

/**
 * What a keyword filter does to what the viewer is shown.
 *
 * The filters themselves are stored by `FiltersRequest` and edited over
 * `/api/v2/filters`; this is the half that makes them more than a list. A
 * status the viewer has a `hide` filter for is removed from the timeline it
 * was filtered in; one matched by a `warn` filter stays, and is handed to the
 * client with the `filtered` key that tells it to blur the status and say
 * why. A status that matched nothing carries `filtered: []` — the key is not
 * optional, and its absence is read by clients as "nothing filtered", which is
 * the same answer for every viewer and therefore the wrong one.
 *
 * Filters are always the *viewer's*: every read is scoped by the actor id in
 * SQL, and the per-request cache below is keyed by it, so one account's
 * filters can never be applied to another account's timeline.
 */
class FilterService {
	/** @var array<string, Filter[]> actor id => its active filters, for this request */
	private array $cache = [];

	public function __construct(
		private FiltersRequest $filtersRequest,
	) {
	}

	/**
	 * The viewer's filters that apply to this context and can still match
	 * something.
	 *
	 * The expiry is checked here as well as in SQL: the cache outlives the
	 * query, and a filter that expires while a request is being answered must
	 * not go on hiding statuses because it was read a moment earlier.
	 *
	 * @return Filter[]
	 */
	public function activeFilters(string $actorId, string $context, ?int $now = null): array {
		if ($actorId === '' || !in_array($context, Filter::CONTEXTS, true)) {
			return [];
		}

		$this->cache[$actorId] ??= $this->filtersRequest->getActiveByActor($actorId, $now);

		$applicable = [];
		foreach ($this->cache[$actorId] as $filter) {
			if ($filter->appliesTo($context) && $filter->isActive($now) && $filter->getKeywords() !== []) {
				$applicable[] = $filter;
			}
		}

		return $applicable;
	}

	/**
	 * A page of statuses as the client should receive it: the ones a `hide`
	 * filter matched are gone, and every one that is left carries `filtered`.
	 *
	 * The items may be `Stream` objects, as a timeline hands them over, or
	 * status entities that have already been exported.
	 *
	 * @param array<Stream|JsonSerializable|array> $items
	 *
	 * @return array<array> status entities
	 */
	public function apply(array $items, string $context, ?Person $viewer): array {
		$filters = ($viewer === null) ? [] : $this->activeFilters($viewer->getId(), $context);

		$statuses = [];
		foreach ($items as $item) {
			$status = $this->applyToExported($this->exportForClient($item), $filters);
			if ($status !== null) {
				$statuses[] = $status;
			}
		}

		return $statuses;
	}

	/**
	 * One status, for the routes that answer with a single one. Null is "the
	 * viewer hides this", which such a route answers 404 for — the same answer
	 * as a status that does not exist, because a status the viewer has chosen
	 * not to see is one they are not asking about.
	 */
	public function applyToStatus(mixed $item, string $context, ?Person $viewer): ?array {
		$statuses = $this->apply([$item], $context, $viewer);

		return $statuses[0] ?? null;
	}

	/**
	 * The notifications that survive the viewer's `notifications` filters.
	 *
	 * The items are handed back as they came in, not exported: a notification
	 * entity is built by `Stream::exportAsNotification()` and reshaping it here
	 * would change what every client receives. So a `hide` filter drops the
	 * notification, and a `warn` filter leaves it alone — carrying `filtered`
	 * inside a notification's nested status needs that exporter to emit the
	 * key, and it does not yet.
	 *
	 * @param array<Stream> $notifications
	 *
	 * @return array<Stream>
	 */
	public function applyToNotifications(array $notifications, ?Person $viewer): array {
		$filters = ($viewer === null)
			? [] : $this->activeFilters($viewer->getId(), Filter::CONTEXT_NOTIFICATIONS);
		if ($filters === []) {
			return array_values($notifications);
		}

		$kept = [];
		foreach ($notifications as $notification) {
			if (!$this->isHidden($this->results($this->matchable($notification), $filters))) {
				$kept[] = $notification;
			}
		}

		return $kept;
	}

	/**
	 * What matched, as Mastodon's `FilterResult` entities — one per filter that
	 * matched, naming the filter and the text that matched it.
	 *
	 * `status_matches` is always empty: those are Mastodon's per-status
	 * filters, which this app does not have. The key is still sent, because a
	 * client that declares it non-optional cannot decode a status without it.
	 *
	 * @param array $status a status entity
	 * @param Filter[] $filters
	 *
	 * @return array<array{filter: array, keyword_matches: string[], status_matches: string[]}>
	 */
	public function results(array $status, array $filters): array {
		if ($filters === []) {
			return [];
		}

		$text = $this->searchableText($status);
		if ($text === '') {
			return [];
		}

		$results = [];
		foreach ($filters as $filter) {
			$matches = [];
			foreach ($filter->getKeywords() as $keyword) {
				$matched = $this->match($keyword, $text);
				if ($matched !== '') {
					$matches[] = $matched;
				}
			}

			if ($matches !== []) {
				$results[] = [
					'filter' => $filter->exportAsResultFilter(),
					// the text that matched, as Mastodon reports it: what the
					// keyword found, not the keyword
					'keyword_matches' => array_values(array_unique($matches)),
					'status_matches' => [],
				];
			}
		}

		return $results;
	}

	/** @param array<array{filter: array, ...}> $results */
	public function isHidden(array $results): bool {
		foreach ($results as $result) {
			if (($result['filter']['filter_action'] ?? Filter::ACTION_WARN) === Filter::ACTION_HIDE) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The text a keyword is compared against: everything of the status a
	 * reader would read, which is what Mastodon matches on too — the content
	 * warning, the text, the descriptions of the attachments and the options
	 * of a poll. Markup is not part of it: a keyword must not match an `href`
	 * the reader never sees.
	 *
	 * A boost carries no text of its own, so it is the boosted status that is
	 * read — filtering the wrapper on its own text would filter nothing at all.
	 */
	public function searchableText(array $status): string {
		$status = (isset($status['reblog']) && is_array($status['reblog'])) ? $status['reblog'] : $status;

		$parts = [
			(string)($status['spoiler_text'] ?? ''),
			$this->plainText((string)($status['content'] ?? '')),
		];

		foreach ((array)($status['media_attachments'] ?? []) as $attachment) {
			$parts[] = $this->describedAs($attachment);
		}

		$options = $status['poll']['options'] ?? [];
		foreach ((is_array($options) ? $options : []) as $option) {
			$parts[] = is_array($option) ? (string)($option['title'] ?? '') : '';
		}

		return trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== '')));
	}

	/**
	 * The comparison a keyword stands for, as Mastodon builds it.
	 *
	 * Case never matters. `whole_word` puts a word boundary on each side — but
	 * only on a side that starts or ends with a word character, because `\b`
	 * before a `#` or after a `!` can never be satisfied and the keyword would
	 * match nothing at all.
	 *
	 * Every pattern here carries `/u`, which in PHP sets PCRE2's UTF *and* UCP
	 * flags: `\w` and `\b` then count `é` and `ß` as letters, so a whole-word
	 * filter on "café" does not also match "cafés". Dropping `/u` would not
	 * merely be a missed accent — it is what the byte-oriented `\b` reads a
	 * word boundary in the middle of a character as.
	 */
	public static function keywordRegex(FilterKeyword $keyword): string {
		$quoted = preg_quote($keyword->getKeyword(), '/');
		if (!$keyword->isWholeWord()) {
			return '/' . $quoted . '/iu';
		}

		$start = (preg_match('/^\w/u', $keyword->getKeyword()) === 1) ? '\b' : '';
		$end = (preg_match('/\w$/u', $keyword->getKeyword()) === 1) ? '\b' : '';

		return '/' . $start . $quoted . $end . '/iu';
	}

	/** The text the keyword matched, or '' for no match. */
	private function match(FilterKeyword $keyword, string $text): string {
		if ($keyword->getKeyword() === '') {
			return '';
		}

		// a keyword is stored as the account typed it and is never run as a
		// pattern: preg_quote() in keywordRegex() is what keeps '(' a bracket
		if (preg_match(self::keywordRegex($keyword), $text, $found) !== 1) {
			return '';
		}

		return (string)$found[0];
	}

	/**
	 * @param Filter[] $filters
	 */
	private function applyToExported(array $status, array $filters): ?array {
		$results = $this->results($status, $filters);
		if ($this->isHidden($results)) {
			return null;
		}

		$status['filtered'] = $results;
		if (isset($status['reblog']) && is_array($status['reblog'])) {
			// a client reads `filtered` off whichever of the two it renders,
			// and for a boost that is the boosted status
			$status['reblog']['filtered'] = $results;
		}

		return $status;
	}

	/** The status entity a client would be handed for this item. */
	private function exportForClient(mixed $item): array {
		if (is_array($item)) {
			return $item;
		}

		if ($item instanceof ACore) {
			$item->setExportFormat(ACore::FORMAT_LOCAL);
		}

		if ($item instanceof JsonSerializable) {
			$exported = $item->jsonSerialize();

			return is_array($exported) ? $exported : [];
		}

		return [];
	}

	/**
	 * A copy of the item to compare keywords against, leaving the item itself
	 * exactly as it was found: a notification is answered with the object it
	 * came in as, and exporting it here would change what the client receives.
	 */
	private function matchable(mixed $item): array {
		if (is_array($item)) {
			return $item;
		}

		if ($item instanceof Stream) {
			$object = $item->getObject();
			$subject = ($object instanceof Stream) ? $object : $item;

			return [
				'spoiler_text' => $subject->getSpoilerText(),
				'content' => $subject->getContent(),
				'media_attachments' => $subject->getAttachments(),
			];
		}

		return $this->exportForClient($item);
	}

	/** The alt text of an attachment, however the entity carries it. */
	private function describedAs(mixed $attachment): string {
		if (is_array($attachment)) {
			return (string)($attachment['description'] ?? '');
		}

		if (is_object($attachment) && method_exists($attachment, 'getDescription')) {
			return (string)$attachment->getDescription();
		}

		return '';
	}

	/**
	 * The text of a rendered status, with the markup taken out and the
	 * entities put back: a post saying `don&apos;t` is a post saying "don't",
	 * and a filter on "don't" has to match it.
	 */
	private function plainText(string $html): string {
		if ($html === '') {
			return '';
		}

		$text = preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $html) ?? $html;

		return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
