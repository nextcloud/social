<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Db\StarterPacksRequest;
use OCA\Social\Service\CollectionPackService;
use OCA\Social\Service\StarterPackService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bluesky's suggested starter packs (`app.bsky.unspecced.getSuggestedStarterPacks`),
 * read in as starter packs beside the fediverse's collections.
 *
 * Asked of the AppView signed out, so every instance reads the same list,
 * once a day with the collections (`CollectionPackService::refresh()`). A
 * pack's members are its list's (`app.bsky.graph.getList`), at most as
 * many as a starter pack here holds, each stored in the actor cache from
 * the profile the list answers with, so an opened pack draws from the
 * cache. A member the instance blocks, or that Bluesky's moderation limits,
 * is left out; a pack whose creator is either is not offered.
 *
 * Bluesky asks no consent of an account to be in a starter pack, which is a
 * public list by design, so there is nothing to verify the way a
 * fediverse collection's `featureAuthorization` is.
 */
class SuggestedStarterPacks {
	/** The `origin` of a pack read from here. */
	public const ORIGIN = 'bluesky';

	/** How many suggested packs are asked for. */
	public const LIMIT = 12;

	public function __construct(
		private AppViewClient $appView,
		private ActorMapper $actorMapper,
		private BlueskyActorService $actors,
		private Blocklist $blocklist,
		private StarterPacksRequest $starterPacksRequest,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the suggested packs again, stores them, and removes the ones
	 * Bluesky no longer suggests. An AppView that does not answer throws,
	 * and what was read last time stays.
	 *
	 * @return array{packs: int, members: int}
	 */
	public function refresh(): array {
		$answer = $this->appView->query('app.bsky.unspecced.getSuggestedStarterPacks', ['limit' => self::LIMIT]);
		$views = is_array($answer['starterPacks'] ?? null) ? $answer['starterPacks'] : [];

		$result = ['packs' => 0, 'members' => 0];
		$seen = [];
		foreach ($views as $view) {
			$pack = is_array($view) ? $this->pack($view) : null;
			if ($pack === null) {
				continue;
			}
			$this->starterPacksRequest->save($pack);
			$seen[$pack['uri']] = true;
			$result['packs']++;
			$result['members'] += count($pack['members']);
		}

		$this->forgetAllBut($seen);

		return $result;
	}

	/**
	 * Removes every pack read from here but the ones named; with none named,
	 * all of them, as when Bluesky is switched off or no longer a source.
	 *
	 * @param array<string, true> $keep by `at://` URI
	 */
	public function forgetAllBut(array $keep = []): void {
		foreach ($this->starterPacksRequest->getAll() as $pack) {
			if ($pack['origin'] === self::ORIGIN && !isset($keep[$pack['uri']])) {
				$this->starterPacksRequest->deleteById($pack['id']);
			}
		}
	}

	/**
	 * One suggested pack as a row, or null when it is not one to offer.
	 *
	 * @param array<string, mixed> $view a `starterPackView`
	 *
	 * @return array{uri: string, slug: string, origin: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, members: list<string>, fetched: int}|null
	 */
	private function pack(array $view): ?array {
		$uri = (string)($view['uri'] ?? '');
		$parsed = Syntax::parseAtUri($uri);
		$creator = is_array($view['creator'] ?? null) ? $view['creator'] : [];
		$did = (string)($creator['did'] ?? '');
		$list = (string)($view['list']['uri'] ?? '');
		if ($parsed === null || ($parsed['collection'] ?? '') !== StarterPacks::COLLECTION
			|| $did === '' || $did !== ($parsed['authority'] ?? '') || $list === ''
			|| !$this->admits($creator)) {
			return null;
		}

		try {
			$members = $this->members($list);
		} catch (Throwable $e) {
			$this->logger->notice('[SuggestedStarterPacks] the members of a suggested starter pack could not be read', [
				'pack' => $uri, 'exception' => $e,
			]);

			return null;
		}
		if ($members === []) {
			return null;
		}

		$handle = (string)($creator['handle'] ?? '');
		$name = trim((string)($view['record']['name'] ?? ''));

		return [
			'uri' => $uri,
			'slug' => CollectionPackService::slug($name, $uri),
			'origin' => self::ORIGIN,
			'curator' => BlueskyIds::actorId($did),
			'curator_acct' => ($handle !== '' && $handle !== 'handle.invalid') ? $handle : $did,
			'name' => mb_substr(($name !== '') ? $name : $handle, 0, 255),
			'summary' => mb_substr(trim((string)($view['record']['description'] ?? '')), 0, 1000),
			'topic' => '',
			'language' => '',
			'url' => 'https://bsky.app/starter-pack/' . (($handle !== '') ? $handle : $did) . '/' . (string)($parsed['rkey'] ?? ''),
			'members' => $members,
			'fetched' => $this->timeFactory->getTime(),
		];
	}

	/**
	 * The actor ids of a pack's list, in its order, the ones this instance
	 * admits, each cached from the profile the list answered with.
	 *
	 * @return list<string>
	 */
	private function members(string $list): array {
		$answer = $this->appView->query('app.bsky.graph.getList', ['list' => $list, 'limit' => StarterPackService::MAX_HANDLES]);

		$members = [];
		foreach (is_array($answer['items'] ?? null) ? $answer['items'] : [] as $item) {
			$subject = is_array($item['subject'] ?? null) ? $item['subject'] : [];
			$did = (string)($subject['did'] ?? '');
			if ($did === '' || !$this->admits($subject)) {
				continue;
			}
			$actorId = BlueskyIds::actorId($did);
			if (!in_array($actorId, $members, true)) {
				$members[] = $actorId;
			}
		}

		return $members;
	}

	/**
	 * Whether an account may be offered: stored in the actor cache from its
	 * profile if it is not there yet, and neither blocked here nor limited by
	 * Bluesky's moderation — the rule `NotificationPoller` takes a Bluesky
	 * account in under.
	 *
	 * @param array<string, mixed> $profile a `profileView`
	 */
	private function admits(array $profile): bool {
		$did = (string)($profile['did'] ?? '');
		if ($did === '' || $this->blocklist->isBlockedDid($did)) {
			return false;
		}

		$actor = $this->actors->cached($did);
		if ($actor !== null) {
			return !BlueskyActorService::isLimited($actor) && !$this->blocklist->isBlockedActor($actor);
		}

		try {
			$person = $this->actorMapper->person($profile);
			if (BlueskyActorService::isLimited($person)) {
				return false;
			}
			$this->actors->store($person);
		} catch (Throwable $e) {
			$this->logger->debug('[SuggestedStarterPacks] a Bluesky account could not be stored', ['did' => $did, 'exception' => $e->getMessage()]);

			return false;
		}

		return true;
	}
}
