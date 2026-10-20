<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\AccountService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The local notification state for a thread muted by a Bluesky client.
 * Bluesky owns the private preference; only threads stored here can be
 * mirrored into this instance's conversation state.
 */
class BlueskyThreadMutes {
	public const MUTE = 'app.bsky.graph.muteThread';
	public const UNMUTE = 'app.bsky.graph.unmuteThread';

	public function __construct(
		private AccountService $accounts,
		private StreamRequest $streams,
		private ConversationsRequest $conversations,
		private LoggerInterface $logger,
		private LocalRecordResolver $records,
	) {
	}

	/** Apply a successful thread mute or unmute made through a Bluesky app. */
	public function fromApp(ClientSession $session, string $method, array $body): void {
		$uri = (string)($body['root'] ?? '');
		$postId = $this->records->postId($uri);
		if ($postId === '') {
			return;
		}

		try {
			$post = $this->streams->getStreamById($postId, false, ACore::FORMAT_LOCAL);
			$viewer = $this->accounts->getActorFromUserId($session->userId);
			$this->conversations->setMuted(
				$viewer->getId(),
				$this->conversations->rootOf($post->getId()),
				$method === self::MUTE
			);
		} catch (StreamNotFoundException $e) {
			return;
		} catch (Throwable $e) {
			$this->logger->info('A Bluesky thread mute was not mirrored here', ['method' => $method, 'uri' => $uri, 'exception' => $e]);
		}
	}
}
