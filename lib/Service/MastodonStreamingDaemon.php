<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Firehose\Subscriber;
use OCA\Social\Atproto\Firehose\WebSocketServer;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/** Serves the Mastodon user and user-notification WebSocket streams. */
class MastodonStreamingDaemon {
	public const DEFAULT_BIND = '127.0.0.1:8788';
	private const PATH = '/api/v1/streaming';
	private const POLL_IDLE = 0.25;
	private const POLL_INTERVAL = 1;
	private const PING_INTERVAL = 20;
	private const BATCH = 50;
	private const MAX_BATCHES_PER_POLL = 10;
	private bool $stopping = false;

	public function __construct(
		private ClientService $clientService,
		private AccountService $accountService,
		private StreamRequest $streamRequest,
		private StreamService $streamService,
		private FilterService $filterService,
		private NotificationPolicyService $notificationPolicyService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(string): void $say
	 */
	public function run(string $bind, int $maxSeconds, callable $say): int {
		$server = new WebSocketServer(
			$bind,
			path: self::PATH,
			binaryFrames: false,
			authorize: \Closure::fromCallable([$this, 'authorize']),
			onMessage: \Closure::fromCallable([$this, 'onMessage']),
		);
		$this->listenForStop();
		$started = $this->time->getTime();
		$deadline = $maxSeconds > 0 ? $started + $maxSeconds : 0;
		$say('Mastodon streaming listening on ' . $bind);

		while (!$this->stopping) {
			if ($deadline > 0 && $this->time->getTime() >= $deadline) {
				break;
			}
			foreach ($server->serve(self::POLL_IDLE) as $subscriber) {
				$this->initialize($subscriber);
			}
			foreach ($server->subscribers() as $subscriber) {
				try {
					$this->poll($server, $subscriber);
					if ($this->time->getTime() - (int)($subscriber->context['lastPing'] ?? 0) >= self::PING_INTERVAL) {
						$server->ping($subscriber);
						$subscriber->context['lastPing'] = $this->time->getTime();
					}
				} catch (Throwable $e) {
					$this->logger->warning('Mastodon streaming poll failed', ['exception' => $e]);
				}
			}
		}

		$server->shutdown();
		$say('Mastodon streaming stopped');

		return 0;
	}

	private function authorize(Subscriber $subscriber): bool {
		$stream = $subscriber->query['stream'] ?? '';
		if (!is_string($stream) || ($stream !== '' && !$this->supportsStream($stream))) {
			return false;
		}
		$token = '';
		$authorization = $subscriber->headers['authorization'] ?? '';
		if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $match) === 1) {
			$token = $match[1];
		} elseif (is_string($subscriber->query['access_token'] ?? null)) {
			$token = $subscriber->query['access_token'];
		}
		$subprotocol = '';
		if ($token === '') {
			$protocols = array_map('trim', explode(',', $subscriber->headers['sec-websocket-protocol'] ?? ''));
			foreach ($protocols as $protocol) {
				$candidate = preg_replace('/^Bearer\s+/i', '', $protocol) ?? '';
				if ($candidate !== '' && !in_array(strtolower($candidate), ['bearer', 'access_token', 'mastodon'], true)) {
					$token = $candidate;
					$subprotocol = $protocol;
					break;
				}
			}
		}
		if ($token === '') {
			return false;
		}

		try {
			$client = $this->clientService->getFromToken($token);
			if ($client->getAuthId() <= 0 || $client->getAuthUserId() === '' || ($stream !== '' && !$this->hasRequiredScopes($client))) {
				return false;
			}
			$viewer = $this->accountService->getActorFromUserId($client->getAuthUserId());
			$viewer->setUserId($client->getAuthUserId())->setExportFormat(ACore::FORMAT_LOCAL);
			if ($subprotocol !== '') {
				$subscriber->responseSubprotocol = $subprotocol;
			}
			$subscriber->context = [
				'userId' => $client->getAuthUserId(),
				'scopes' => $client->getAuthScopes(),
				'subscriptions' => $stream === '' ? [] : [$stream => true],
				'viewer' => $viewer,
				'homeSince' => $this->streamRequest->newestHomeNid($viewer),
				'notificationSince' => $this->streamRequest->newestNidFor([md5($viewer->getId())], 'notif'),
				'lastPoll' => 0,
				'lastPing' => $this->time->getTime(),
			];
			return true;
		} catch (ClientNotFoundException|ActorDoesNotExistException $e) {
			return false;
		} catch (Throwable $e) {
			$this->logger->warning('Mastodon streaming authorization failed', ['exception' => $e]);
			return false;
		}
	}

	private function supportsStream(string $stream): bool {
		return in_array($stream, ['user', 'user:notification'], true);
	}

	private function onMessage(Subscriber $subscriber, int $opcode, string $payload): ?string {
		if ($opcode !== 0x1) {
			return null;
		}
		$message = json_decode($payload, true);
		if (!is_array($message) || !in_array($message['type'] ?? null, ['subscribe', 'unsubscribe'], true)) {
			return $this->errorMessage((string)($subscriber->context['stream'] ?? ''), 'Invalid subscription message');
		}
		$stream = $message['stream'] ?? '';
		if (!is_string($stream) || !$this->supportsStream($stream)) {
			return $this->errorMessage(is_string($stream) ? $stream : '', 'Unknown stream');
		}
		if (!$this->hasRequiredScopesForContext($subscriber->context)) {
			return $this->errorMessage($stream, 'Insufficient scope');
		}
		if ($message['type'] === 'subscribe') {
			$subscriber->context['subscriptions'][$stream] = true;
		} else {
			unset($subscriber->context['subscriptions'][$stream]);
		}

		return null;
	}

	private function hasRequiredScopesForContext(array $context): bool {
		$scopes = $context['scopes'] ?? [];
		return is_array($scopes) && (in_array('read', $scopes, true)
			|| (in_array('read:statuses', $scopes, true) && in_array('read:notifications', $scopes, true)));
	}

	private function errorMessage(string $stream, string $message): string {
		return (string)json_encode([
			'stream' => $stream === '' ? [] : [$stream],
			'event' => 'error',
			'payload' => $message,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private function hasRequiredScopes(SocialClient $client): bool {
		$scopes = $client->getAuthScopes();
		if (in_array('read', $scopes, true)) {
			return true;
		}

		return in_array('read:statuses', $scopes, true)
			&& in_array('read:notifications', $scopes, true);
	}

	private function initialize(Subscriber $subscriber): void {
		$context = $subscriber->context;
		$viewer = $context['viewer'] ?? null;
		if (!$viewer instanceof Person) {
			return;
		}
		$this->streamService->setViewer($viewer);
	}

	private function poll(WebSocketServer $server, Subscriber $subscriber): void {
		$now = $this->time->getTime();
		if ($now - (int)($subscriber->context['lastPoll'] ?? 0) < self::POLL_INTERVAL) {
			return;
		}
		$subscriber->context['lastPoll'] = $now;
		$viewer = $subscriber->context['viewer'] ?? null;
		if (!$viewer instanceof Person) {
			$server->close($subscriber, 'invalid stream state');
			return;
		}
		$this->streamService->setViewer($viewer);
		$subscriptions = $subscriber->context['subscriptions'] ?? [];
		if (isset($subscriptions['user'])) {
			$this->sendStatuses($server, $subscriber, $viewer);
		}
		if (isset($subscriptions['user']) || isset($subscriptions['user:notification'])) {
			$this->sendNotifications($server, $subscriber, $viewer);
		}
	}

	private function sendStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer): void {
		$this->sendPages($server, $subscriber, 'homeSince', function (string $since) use ($viewer): array {
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setProbe(ProbeOptions::HOME)
				->setSince($since)
				->setLimit(self::BATCH)
				->setInverted(true);
			$statuses = $this->streamService->getTimeline($options);
			$cursor = $statuses === [] ? $since : (string)$statuses[0]->getNid();
			$filtered = $this->filterService->apply(array_reverse($statuses), Filter::CONTEXT_HOME, $viewer);
			return ['cursor' => $cursor, 'items' => $filtered, 'hasMore' => count($statuses) === self::BATCH];
		}, 'update', ['user']);
	}

	private function sendNotifications(WebSocketServer $server, Subscriber $subscriber, Person $viewer): void {
		$this->sendPages($server, $subscriber, 'notificationSince', function (string $since) use ($viewer): array {
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setProbe(ProbeOptions::NOTIFICATIONS)
				->setSince($since)
				->setLimit(self::BATCH)
				->setInverted(true);
			$page = $this->streamService->getTimeline($options);
			$cursor = $page === [] ? $since : (string)$page[0]->getNid();
			$page = array_reverse($page);
			$shown = $this->notificationPolicyService->partition($viewer, $page)['shown'];
			return [
				'cursor' => $cursor,
				'items' => $this->filterService->applyToNotifications($shown, $viewer),
				'hasMore' => count($page) === self::BATCH,
			];
		}, 'notification', ['user', 'user:notification']);
	}

	/**
	 * Fetches new pages in chronological order and advances the cursor over
	 * filtered rows as well as rows sent to the client.
	 *
	 * @param callable(string): array{cursor: string, items: array, hasMore: bool} $loadPage
	 * @param string[] $streams
	 */
	private function sendPages(WebSocketServer $server, Subscriber $subscriber, string $cursorKey, callable $loadPage, string $event, array $streams): void {
		$subscriptions = $subscriber->context['subscriptions'] ?? [];
		$activeStreams = array_values(array_filter($streams, static fn (string $name): bool => isset($subscriptions[$name])));
		if ($activeStreams === []) {
			return;
		}
		for ($pageNumber = 0; $pageNumber < self::MAX_BATCHES_PER_POLL && !$subscriber->closed; $pageNumber++) {
			$since = (string)($subscriber->context[$cursorKey] ?? '0');
			$page = $loadPage($since);
			$items = $page['items'];
			if ($page['cursor'] === $since && $items === []) {
				return;
			}
			foreach ($items as $item) {
				if ($item instanceof Stream) {
					$payload = $event === 'notification' ? $item->exportAsNotification() : $item->jsonSerialize();
				} else {
					$payload = $item;
				}
				foreach ($activeStreams as $activeStream) {
					$this->send($server, $subscriber, [$activeStream], $event, $payload);
				}
			}
			$subscriber->context[$cursorKey] = $page['cursor'];
			if (!$page['hasMore']) {
				return;
			}
		}
	}

	private function send(WebSocketServer $server, Subscriber $subscriber, array $streams, string $event, array $payload): void {
		try {
			$status = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			$message = json_encode([
				'stream' => $streams,
				'event' => $event,
				'payload' => $status,
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			$server->send($subscriber, $message);
		} catch (Throwable $e) {
			$this->logger->warning('Could not serialize Mastodon streaming event', ['exception' => $e]);
		}
	}

	private function listenForStop(): void {
		if (!function_exists('pcntl_signal')) {
			return;
		}
		pcntl_async_signals(true);
		pcntl_signal(SIGTERM, fn () => $this->stopping = true);
		pcntl_signal(SIGINT, fn () => $this->stopping = true);
	}
}
