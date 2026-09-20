<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Details;
use OCA\Social\Security\RemoteAddress;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The tick next to a profile link.
 *
 * A profile field that names a web page is verified the way Mastodon does it:
 * the page is fetched and has to link back to the profile with `rel="me"`.
 * That is the whole check -- somebody who controls the page put the link
 * there, so the page is theirs. What is stored is when the page was last seen
 * linking back (`fields_verified`, keyed by the field's value, in the cached
 * actor's details) and when the fields were last looked at (`fields_checked`),
 * and the Account entity carries the first as `verified_at`.
 *
 * Fetching is bounded the way every other fetch a user can point this
 * instance at is: http(s) only, no local host unless the administrator opted
 * in, through the app's HTTP client (which re-checks the target on every
 * redirect and applies the size ceiling), and only the first part of the
 * page is read. A page that cannot be reached leaves the field unverified;
 * it is asked again on the next pass.
 */
class ProfileLinkVerifier {
	/** how much of a page is worth scanning for a link back */
	public const MAX_SCAN = 512 * 1024;
	/** how long a verdict stands before the page is asked again */
	public const RECHECK_SECONDS = 24 * 3600;
	/** how many local accounts one cron pass looks at */
	public const LOCAL_BATCH = 20;

	public function __construct(
		private CacheDocumentService $cacheDocumentService,
		private ConfigService $configService,
		private ActorsRequest $actorsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The web address a field value names, or '' when it names none. A remote
	 * field arrives as HTML (`<a href="…">…</a>`); a local one is what the
	 * account typed.
	 */
	public static function linkOf(string $value): string {
		if (preg_match('/href=["\']([^"\']+)["\']/i', $value, $match) === 1) {
			$link = html_entity_decode($match[1]);
		} elseif (preg_match('#https?://[^\s<>"\']+#i', $value, $match) === 1) {
			$link = $match[0];
		} else {
			return '';
		}

		return (filter_var($link, FILTER_VALIDATE_URL) === false) ? '' : $link;
	}

	/**
	 * Whether the page at `$link` links back to the actor with `rel="me"`.
	 */
	public function linksBack(string $link, Person $actor): bool {
		$parsed = parse_url($link);
		$scheme = strtolower($parsed['scheme'] ?? '');
		$host = $parsed['host'] ?? '';
		if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
			return false;
		}
		if (!$this->configService->isLocalNetworkAllowed() && RemoteAddress::isLocalHost($host)) {
			return false;
		}

		try {
			$html = substr($this->cacheDocumentService->retrieveContent($link), 0, self::MAX_SCAN);
		} catch (Throwable $e) {
			$this->logger->debug('[ProfileLinkVerifier] page not reachable', ['link' => $link, 'exception' => $e->getMessage()]);

			return false;
		}

		$targets = array_filter(array_unique([self::normalise($actor->getId()), self::normalise($actor->getUrl())]));
		if (preg_match_all('/<(?:a|link)\b[^>]*>/i', $html, $tags) < 1) {
			return false;
		}
		foreach ($tags[0] as $tag) {
			if (preg_match('/\brel=["\']([^"\']*)["\']/i', $tag, $rel) !== 1
				|| !in_array('me', preg_split('/\s+/', strtolower(trim($rel[1]))) ?: [], true)) {
				continue;
			}
			if (preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $href) !== 1) {
				continue;
			}
			if (in_array(self::normalise(html_entity_decode($href[1])), $targets, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks every linked field of an actor and records the verdicts on the
	 * cached copy's details. Skipped when the fields were looked at recently,
	 * so the details refresh (which runs often) costs a fetch per field only
	 * once a day.
	 *
	 * @return bool whether anything was checked
	 */
	public function verify(Person $cached, bool $force = false): bool {
		$details = $cached->getDetailsAll();
		$checked = (int)($details[Details::FIELDS_CHECKED] ?? 0);
		if (!$force && $checked > time() - self::RECHECK_SECONDS) {
			return false;
		}

		$previous = $details[Details::FIELDS_VERIFIED] ?? [];
		$verified = [];
		foreach ($cached->getFields() as $field) {
			$link = self::linkOf((string)($field['value'] ?? ''));
			if ($link === '') {
				continue;
			}
			if ($this->linksBack($link, $cached)) {
				// the first time it was seen linking back, kept as long as it does
				$verified[$field['value']] = (string)($previous[$field['value']] ?? (new DateTime('now'))->format(DATE_ATOM));
			}
		}

		$cached->setDetailArray(Details::FIELDS_VERIFIED, $verified);
		$cached->setDetailInt(Details::FIELDS_CHECKED, time());

		return true;
	}

	/**
	 * The cron pass for this instance's own accounts: their fields are checked
	 * against the web like anybody else's, and the verdict written to their
	 * cached copy, which is what the Account entity is exported from.
	 *
	 * @return int how many accounts were checked
	 */
	public function verifyLocalActors(int $limit = self::LOCAL_BATCH): int {
		$checked = 0;
		foreach ($this->actorsRequest->getAll() as $actor) {
			if ($checked >= $limit) {
				break;
			}
			if (array_filter(array_map(fn (array $f): string => self::linkOf((string)($f['value'] ?? '')), $actor->getFields())) === []) {
				continue;
			}
			try {
				$cached = $this->cacheActorsRequest->getFromId($actor->getId());
			} catch (Throwable $e) {
				continue;
			}
			// the cached copy is what is checked and what carries the verdict,
			// but the fields themselves are what the account set
			$cached->setFields($actor->getFields());
			if ($this->verify($cached)) {
				$this->cacheActorsRequest->updateDetails($cached);
				$checked++;
			}
		}

		return $checked;
	}

	private static function normalise(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		$url = preg_replace('/#.*$/', '', $url) ?? $url;
		$parsed = parse_url($url);
		if (!is_array($parsed) || !isset($parsed['host'])) {
			return rtrim($url, '/');
		}
		$path = rtrim($parsed['path'] ?? '', '/');

		return strtolower($parsed['scheme'] ?? 'https') . '://' . strtolower($parsed['host'])
			. (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $path
			. (isset($parsed['query']) ? '?' . $parsed['query'] : '');
	}
}
