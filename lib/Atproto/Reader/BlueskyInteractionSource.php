<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Interaction\InteractionSource;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Who liked, reposted or quoted a post on Bluesky, for a post that is there
 * — read from there, or written here and published: as the AppView lists
 * them (`getLikes`, `getRepostedBy`, `getQuotes`). Each account is the one
 * cached here, or is cached as it is found; each quoting post is stored as
 * any Bluesky post is.
 */
class BlueskyInteractionSource implements InteractionSource {
	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PostRefs $refs,
		private PostStore $store,
		private ActorMapper $mapper,
		private BlueskyActorService $actors,
		private LabelerService $labelers,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return $this->config->isEnabled() && $this->refs->strongRef($post->getId()) !== null;
	}

	#[\Override]
	public function actors(Stream $post, string $type, int $limit, ?int $timeout = null): array {
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		[$method, $key] = $type === Like::TYPE ? ['app.bsky.feed.getLikes', 'likes'] : ['app.bsky.feed.getRepostedBy', 'repostedBy'];
		try {
			$params = ['uri' => $uri, 'limit' => max(1, min(100, $limit))];
			$answer = $timeout === null
				? $this->appView->query($method, $params)
				: $this->appView->query($method, $params, timeout: $timeout);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky reactions not read', ['uri' => $uri, 'exception' => $e]);

			throw $e;
		}
		if (!is_array($answer[$key] ?? null)) {
			throw new UnexpectedValueException('Bluesky reaction response has no ' . $key . ' list');
		}
		$ids = [];
		foreach ($answer[$key] as $entry) {
			if (!is_array($entry)) {
				throw new UnexpectedValueException('Bluesky reaction response contains an invalid entry');
			}
			$profile = is_array($entry['actor'] ?? null) ? $entry['actor'] : $entry;
			$did = is_array($profile) ? (string)($profile['did'] ?? '') : '';
			if ($did === '' || !is_string($profile['handle'] ?? null) || $profile['handle'] === '') {
				throw new UnexpectedValueException('Bluesky reaction response contains an incomplete actor');
			}
			try {
				$cached = $this->actors->cached($did);
				$avatarUrl = trim((string)($profile['avatar'] ?? ''));
				if ($cached === null) {
					$this->actors->store($this->mapper->person($profile));
				} elseif (!$cached->isLocal() && $avatarUrl !== '') {
					// Reaction views carry an avatar but not a full profile. Refresh
					// that image on the cached actor without replacing its richer
					// description, banner, PDS details or moderation state.
					$cachedAvatar = $cached->getIcon();
					if ($cachedAvatar === null || $cachedAvatar->getUrl() !== $avatarUrl) {
						$mapped = $this->mapper->person($profile);
						$avatar = $mapped->getIcon();
						if ($avatar !== null) {
							$cached->setIcon($avatar);
							$this->actors->store($cached);
						}
					}
				}
				$ids[] = BlueskyIds::actorId($did);
			} catch (Throwable $e) {
				$this->logger->debug('A Bluesky account was not cached', ['did' => $did, 'exception' => $e]);
			}
		}

		return $ids;
	}

	#[\Override]
	public function quotes(Stream $post, int $limit, ?int $timeout = null): int {
		$uri = $this->refs->strongRef($post->getId())['uri'] ?? '';
		try {
			$params = ['uri' => $uri, 'limit' => max(1, min(100, $limit))];
			$headers = ['atproto-accept-labelers' => $this->labelers->acceptHeader()];
			$answer = $timeout === null
				? $this->appView->query('app.bsky.feed.getQuotes', $params, $headers)
				: $this->appView->query('app.bsky.feed.getQuotes', $params, $headers, $timeout);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky quotes not read', ['uri' => $uri, 'exception' => $e]);

			throw $e;
		}
		if (!is_array($answer['posts'] ?? null)) {
			throw new UnexpectedValueException('Bluesky quote response has no posts list');
		}
		$stored = 0;
		foreach ($answer['posts'] as $view) {
			if (!is_array($view) || !is_string($view['uri'] ?? null) || $view['uri'] === '') {
				throw new UnexpectedValueException('Bluesky quote response contains an invalid post');
			}
			if ($this->store->storePost($view, false)) {
				$stored++;
			} else {
				$this->store->refreshKnownPostCountsFromView($view);
			}
		}

		return $stored;
	}
}
