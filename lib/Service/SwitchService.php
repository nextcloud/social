<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * Moving in from a network that is not this one.
 *
 * Importing posts is somebody else's job here (`PostImportService`) and so is
 * importing a follow list (`MigrationService`). What is left is the part no
 * export can do for you: **finding the people again**.
 *
 * Instagram and X hand over a list of accounts you followed *there*, and those
 * names mean nothing on the fediverse — except that Threads federates, and a
 * Threads handle is the Instagram one at `threads.net`. So the list is worth
 * one lookup each: most will not be there, and the ones that are, are people
 * you already chose to follow once.
 *
 * Nothing is followed here. This answers who exists; the follow is a separate
 * decision the wizard asks for, one checkbox at a time.
 */
class SwitchService {
	/**
	 * How many names one request looks up.
	 *
	 * Each is a webfinger against another server, so a list of eight hundred
	 * is eight hundred outbound requests — which is a page that never finishes
	 * and a server that looks like it is scanning. The wizard asks again for
	 * the next batch and says how far it has got, so the work is visible and
	 * can be stopped.
	 */
	public const PROBE_BATCH = 25;

	/** The most names one archive is read for. */
	private const MAX_CANDIDATES = 5000;

	/** Where an Instagram name can be followed, if its owner turned it on. */
	private const THREADS = 'threads.net';

	public function __construct(
		private CacheActorService $cacheActorService,
	) {
	}

	/**
	 * The handles worth looking up, out of an archive.
	 *
	 * Instagram's JSON export says who you follow in
	 * `following.json` / `connections/followers_and_following/following.json`,
	 * as `string_list_data[].value`. The same shape appears in several places
	 * in that archive and has changed names between exports, so the file is
	 * searched for the shape rather than for a path.
	 *
	 * @param string $body the uploaded file
	 *
	 * @return string[] handles at threads.net, in the order the archive lists
	 *                  them, without repeats
	 */
	public function candidates(string $body): array {
		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			return [];
		}

		$names = [];
		$this->harvest($decoded, $names);

		$handles = [];
		foreach ($names as $name) {
			$handle = strtolower($name) . '@' . self::THREADS;
			if (!in_array($handle, $handles, true)) {
				$handles[] = $handle;
			}

			if (count($handles) >= self::MAX_CANDIDATES) {
				break;
			}
		}

		return $handles;
	}

	/**
	 * Which of those accounts actually exist.
	 *
	 * One batch, because the wizard walks the list a batch at a time — see
	 * `PROBE_BATCH`. What comes back says how many were *checked* rather than
	 * how many were found, so the caller's cursor moves whether or not
	 * anybody was there; a cursor that moved by the number found would ask
	 * about the same names for ever.
	 *
	 * @param string[] $handles what to look up
	 *
	 * @return array{checked: int, found: array<array{handle: string, name: string, url: string, avatar: string}>}
	 */
	public function probe(array $handles): array {
		$batch = array_slice(array_values($handles), 0, self::PROBE_BATCH);
		$found = [];

		foreach ($batch as $handle) {
			$handle = ltrim(trim((string)$handle), '@');
			if ($handle === '' || !str_contains($handle, '@')) {
				continue;
			}

			try {
				$actor = $this->cacheActorService->getFromAccount($handle);
			} catch (Exception) {
				// not there, or not federating: the common answer, and not a
				// failure worth telling anybody about
				continue;
			}

			$found[] = [
				'handle' => $actor->getAccount(),
				'name' => ($actor->getName() !== '') ? $actor->getName() : $actor->getPreferredUsername(),
				'url' => $actor->getId(),
				'avatar' => $actor->getIcon()?->getUrl() ?? '',
			];
		}

		return ['checked' => count($batch), 'found' => $found];
	}

	/**
	 * The card to post where you used to be.
	 *
	 * A handle and an address somebody can copy into a last post on the
	 * network they are leaving. Written here rather than in the page so that
	 * the handle is the one this server actually publishes — a page that built
	 * it from the Nextcloud user id would be wrong for every account whose
	 * fediverse name differs from it, which is most of the interesting ones.
	 *
	 * @param Person $actor whose
	 *
	 * @return array{handle: string, url: string, text: string}
	 */
	public function announcement(Person $actor): array {
		$handle = '@' . $actor->getAccount();
		$url = $actor->getId();

		return [
			'handle' => $handle,
			'url' => $url,
			'text' => sprintf(
				"I've moved to the fediverse. You can find me at %s — %s",
				$handle,
				$url
			),
		];
	}

	/**
	 * Every `string_list_data[].value` anywhere in the document.
	 *
	 * Instagram nests the same shape under a different key in each export it
	 * has ever written, so this walks for the shape instead of naming a path.
	 *
	 * @param array<mixed> $node where to look
	 * @param string[] $into what was found, by reference
	 */
	private function harvest(array $node, array &$into): void {
		foreach ($node as $key => $value) {
			if (!is_array($value)) {
				continue;
			}

			if ($key === 'string_list_data') {
				foreach ($value as $entry) {
					$name = is_array($entry) ? trim((string)($entry['value'] ?? '')) : '';
					// a handle, not a URL and not a display name
					if ($name !== '' && preg_match('/^[A-Za-z0-9._]{1,30}$/', $name) === 1) {
						$into[] = $name;
					}
				}

				continue;
			}

			$this->harvest($value, $into);
		}
	}
}
