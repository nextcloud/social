<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Firehose\Subscriber;
use OCA\Social\Atproto\Firehose\WebSocketServer;
use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StatusRevisionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/** Serves the Mastodon user, direct-message, and public WebSocket and SSE streams. */
class MastodonStreamingDaemon {
	public const DEFAULT_BIND = '127.0.0.1:8788';
	private const PATH = '/api/v1/streaming';
	private const POLL_IDLE = 0.25;
	private const POLL_INTERVAL = 1;
	private const PING_INTERVAL = 20;
	private const BATCH = 50;
	private const MAX_HASHTAG_SUBSCRIPTIONS = 20;
	private const MAX_LIST_SUBSCRIPTIONS = 20;
	private const MAX_BATCHES_PER_POLL = 10;
	private const PUBLIC_STREAMS = ['public', 'public:media', 'public:local', 'public:local:media', 'public:remote', 'public:remote:media'];
	private bool $stopping = false;

	public function __construct(
		private ClientService $clientService,
		private AccountService $accountService,
		private StreamRequest $streamRequest,
		private StatusRevisionsRequest $statusRevisionsRequest,
		private StreamService $streamService,
		private ConversationService $conversationService,
		private ListsRequest $listsRequest,
		private PostDecorationService $postDecorationService,
		private FilterService $filterService,
		private FeedDisplayService $feedDisplayService,
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
			eventStreams: true,
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
		if ($subscriber->eventStream) {
			$path = $subscriber->requestPath;
			if (str_starts_with($path, self::PATH . '/public')) {
				$local = str_ends_with($path, '/local');
				$remote = str_ends_with($path, '/remote');
				$onlyMedia = filter_var($subscriber->query['only_media'] ?? false, FILTER_VALIDATE_BOOL);
				$pathStream = 'public' . ($local ? ':local' : ($remote ? ':remote' : '')) . ($onlyMedia ? ':media' : '');
			} elseif ($path === self::PATH . '/hashtag' || $path === self::PATH . '/hashtag/local') {
				$pathStream = $path === self::PATH . '/hashtag/local' ? 'hashtag:local' : 'hashtag';
				$stream = $pathStream;
			} elseif ($path === self::PATH . '/direct') {
				$pathStream = 'direct';
			} elseif ($path === self::PATH . '/list') {
				$pathStream = 'list';
			} else {
				$pathStream = str_ends_with($path, '/notification') ? 'user:notification' : 'user';
			}
			if ($stream !== '' && $stream !== $pathStream) {
				return false;
			}
			$stream = $pathStream;
		}
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
			if ($client->getAuthId() <= 0 || $client->getAuthUserId() === '' || ($stream !== '' && !$this->hasRequiredScopes($client, $stream))) {
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
				'directSince' => $this->streamRequest->newestNidFor([md5($viewer->getId())], 'dm'),
				'revisionSince' => $this->statusRevisionsRequest->newestId(),
				'lastPoll' => 0,
				'lastPing' => $this->time->getTime(),
			];
			$publicSince = $this->streamRequest->newestNidFor([md5(ACore::CONTEXT_PUBLIC)]);
			foreach (self::PUBLIC_STREAMS as $publicStream) {
				$subscriber->context['publicSince:' . $publicStream] = $publicSince;
			}
			if (in_array($stream, ['hashtag', 'hashtag:local'], true)
				&& !$this->addHashtagSubscription($subscriber, $stream, $subscriber->query['tag'] ?? '')) {
				return false;
			}
			if ($stream === 'list' && !$this->addListSubscription($subscriber, $subscriber->query['list'] ?? '')) {
				return false;
			}
			return true;
		} catch (ClientNotFoundException|ActorDoesNotExistException $e) {
			return false;
		} catch (Throwable $e) {
			$this->logger->warning('Mastodon streaming authorization failed', ['exception' => $e]);
			return false;
		}
	}

	private function supportsStream(string $stream): bool {
		return in_array($stream, ['user', 'user:notification', 'direct', 'list', 'hashtag', 'hashtag:local', ...self::PUBLIC_STREAMS], true);
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
		if (!$this->hasRequiredScopesForContext($subscriber->context, $stream)) {
			return $this->errorMessage($stream, 'Insufficient scope');
		}
		if ($message['type'] === 'subscribe') {
			if ($stream === 'list') {
				if (!$this->addListSubscription($subscriber, $message['list'] ?? '')) {
					return $this->errorMessage($stream, 'A valid list owned by this account is required');
				}
				return null;
			}
			if (in_array($stream, ['hashtag', 'hashtag:local'], true)) {
				$tag = $message['tag'] ?? '';
				$key = $this->hashtagSubscriptionKey($stream, $tag);
				if ($key === '') {
					return $this->errorMessage($stream, 'A valid tag is required');
				}
				$hashtagSubscriptions = $subscriber->context['hashtagSubscriptions'] ?? [];
				if (is_array($hashtagSubscriptions) && !isset($hashtagSubscriptions[$key])
					&& count($hashtagSubscriptions) >= self::MAX_HASHTAG_SUBSCRIPTIONS) {
					return $this->errorMessage($stream, 'Too many hashtag subscriptions');
				}
				$this->addHashtagSubscription($subscriber, $stream, $tag);
				return null;
			}
			$subscriber->context['subscriptions'][$stream] = true;
		} else {
			if ($stream === 'list') {
				$listId = $this->listId($message['list'] ?? '');
				if ($listId !== null && isset($subscriber->context['listSubscriptions'][$listId])) {
					unset($subscriber->context['listSubscriptions'][$listId], $subscriber->context['listSince:' . $listId]);
					if (($subscriber->context['listSubscriptions'] ?? []) === []) {
						unset($subscriber->context['listSubscriptions'], $subscriber->context['subscriptions']['list']);
					}
				}
				return null;
			}
			if (in_array($stream, ['hashtag', 'hashtag:local'], true)) {
				$key = $this->hashtagSubscriptionKey($stream, $message['tag'] ?? '');
				if ($key !== '') {
					unset($subscriber->context['hashtagSubscriptions'][$key]);
				}
				$hasStream = false;
				foreach ($subscriber->context['hashtagSubscriptions'] ?? [] as $subscription) {
					$hasStream = $hasStream || $subscription['stream'] === $stream;
				}
				if (!$hasStream) {
					unset($subscriber->context['subscriptions'][$stream]);
				}
				return null;
			}
			unset($subscriber->context['subscriptions'][$stream]);
		}

		return null;
	}

	private function addHashtagSubscription(Subscriber $subscriber, string $stream, mixed $tag): bool {
		$key = $this->hashtagSubscriptionKey($stream, $tag);
		if ($key === '') {
			return false;
		}
		$subscriptions = $subscriber->context['hashtagSubscriptions'] ?? [];
		if (!is_array($subscriptions)) {
			$subscriptions = [];
		}
		if (!isset($subscriptions[$key]) && count($subscriptions) >= self::MAX_HASHTAG_SUBSCRIPTIONS) {
			return false;
		}
		if (!is_string($tag)) {
			return false;
		}
		$tag = mb_strtolower(ltrim(trim($tag), '#'), 'UTF-8');
		$subscriber->context['subscriptions'][$stream] = true;
		$subscriber->context['hashtagSubscriptions'][$key] = [
			'stream' => $stream,
			'tag' => $tag,
			'local' => $stream === 'hashtag:local',
			'since' => (string)($subscriber->context['publicSince:public'] ?? '0'),
		];

		return true;
	}

	private function hashtagSubscriptionKey(string $stream, mixed $tag): string {
		if (!in_array($stream, ['hashtag', 'hashtag:local'], true) || !is_string($tag)) {
			return '';
		}
		$tag = ltrim(trim($tag), '#');
		if ($tag === '' || mb_strlen($tag, 'UTF-8') > HashtagsRequest::HASHTAG_MAX_LENGTH
			|| preg_match('/[\p{C}\s]/u', $tag) !== 0) {
			return '';
		}

		return hash('sha256', $stream . ':' . mb_strtolower($tag, 'UTF-8'));
	}

	private function hasRequiredScopesForContext(array $context, string $stream): bool {
		$scopes = $context['scopes'] ?? [];
		if (!is_array($scopes)) {
			return false;
		}
		if (in_array('read', $scopes, true)) {
			return true;
		}
		if (in_array($stream, [...self::PUBLIC_STREAMS, 'hashtag', 'hashtag:local', 'direct', 'list'], true)) {
			return in_array('read:statuses', $scopes, true);
		}

		return in_array('read:statuses', $scopes, true) && in_array('read:notifications', $scopes, true);
	}

	private function errorMessage(string $stream, string $message): string {
		return (string)json_encode([
			'stream' => $stream === '' ? [] : [$stream],
			'event' => 'error',
			'payload' => $message,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private function hasRequiredScopes(SocialClient $client, string $stream): bool {
		$scopes = $client->getAuthScopes();
		if (in_array('read', $scopes, true)) {
			return true;
		}
		if (in_array($stream, [...self::PUBLIC_STREAMS, 'hashtag', 'hashtag:local', 'direct', 'list'], true)) {
			return in_array('read:statuses', $scopes, true);
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
		if (isset($subscriptions['direct'])) {
			$this->sendDirectConversations($server, $subscriber, $viewer);
		}
		if (isset($subscriptions['list'])) {
			foreach ($subscriber->context['listSubscriptions'] ?? [] as $id => $subscription) {
				if (is_array($subscription)) {
					$this->sendListStatuses($server, $subscriber, $viewer, (int)$id, $subscription);
				}
			}
		}
		foreach (self::PUBLIC_STREAMS as $stream) {
			if (isset($subscriptions[$stream])) {
				$this->sendPublicStatuses($server, $subscriber, $viewer, $stream);
			}
		}
		foreach ($subscriber->context['hashtagSubscriptions'] ?? [] as $key => $subscription) {
			$this->sendHashtagStatuses($server, $subscriber, $viewer, $key, $subscription);
		}
		$this->sendEditedPublicStatuses($server, $subscriber, $viewer);
	}

	/** Sends edits to public and hashtag subscribers without replaying existing revisions. */
	private function sendEditedPublicStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer): void {
		$subscriptions = $subscriber->context['subscriptions'] ?? [];
		$activeStreams = array_values(array_filter(
			[...self::PUBLIC_STREAMS, 'hashtag', 'hashtag:local'],
			static fn (string $name): bool => isset($subscriptions[$name])
		));
		if ($activeStreams === []
			&& !isset($subscriptions['user'])
			&& !isset($subscriptions['direct'])
			&& ($subscriber->context['listSubscriptions'] ?? []) === []) {
			return;
		}

		$since = (int)($subscriber->context['revisionSince'] ?? 0);
		$revisions = $this->statusRevisionsRequest->getAfterId($since, self::BATCH);
		if ($revisions === []) {
			return;
		}
		$subscriber->context['revisionSince'] = $revisions[array_key_last($revisions)]['id'];

		$latestByStatus = [];
		foreach ($revisions as $revision) {
			$latestByStatus[$revision['stream_id_prim']] = true;
		}
		foreach (array_keys($latestByStatus) as $streamIdPrim) {
			try {
				$status = $this->streamService->getStreamByIdPrim($streamIdPrim, true);
			} catch (Throwable) {
				continue;
			}
			$isPublic = ($status->getVisibility() ?: $this->streamService->visibilityOf($status)) === Stream::TYPE_PUBLIC;

			$matching = [];
			if ($isPublic) {
				foreach ($activeStreams as $stream) {
					if ($this->editedStatusMatchesStream($status, $stream)) {
						$matching[] = $stream;
					}
				}
				foreach ($subscriber->context['hashtagSubscriptions'] ?? [] as $subscription) {
					if (is_array($subscription)
						&& in_array(
							(string)($subscription['tag'] ?? ''),
							array_map(static fn (string $tag): string => strtolower(ltrim($tag, '#')), $status->getHashtags()),
							true
						)
						&& (!($subscription['local'] ?? false) || $status->isLocal())) {
						$matching[] = (string)($subscription['stream'] ?? 'hashtag');
					}
				}
			}
			if (isset($subscriptions['user']) && $this->editedStatusIsInTimeline($status, ProbeOptions::HOME, viewer: $viewer)) {
				$matching[] = 'user';
			}
			if (isset($subscriptions['direct'])
				&& ($status->getVisibility() ?: $this->streamService->visibilityOf($status)) === Stream::TYPE_DIRECT) {
				$conversation = $this->conversationService->updatedByStatus($viewer, $status->getNid());
				if ($conversation !== null) {
					$this->send($server, $subscriber, ['direct'], 'conversation', $conversation->jsonSerialize());
				}
			}
			foreach ($subscriber->context['listSubscriptions'] ?? [] as $subscription) {
				if (is_array($subscription)
					&& ($subscription['list'] ?? null) instanceof MastodonList
					&& $this->editedStatusIsInTimeline($status, '', $subscription['list'], $viewer)) {
					$matching[] = 'list';
				}
			}
			if ($matching === []) {
				continue;
			}

			foreach (array_unique($matching) as $stream) {
				$context = in_array($stream, [...self::PUBLIC_STREAMS, 'hashtag', 'hashtag:local'], true)
					? Filter::CONTEXT_PUBLIC
					: Filter::CONTEXT_HOME;
				$filtered = $this->filterService->apply([$status], $context, $viewer);
				if ($filtered !== []) {
					$this->send($server, $subscriber, [$stream], 'status.update', $filtered[0]);
				}
			}
		}
	}

	private function editedStatusIsInTimeline(Stream $status, string $probe, ?MastodonList $list = null, ?Person $viewer = null): bool {
		if ($this->feedDisplayService->hidesReplies($viewer?->getUserId() ?? '') && $status->getInReplyTo() !== '') {
			return false;
		}
		$nid = (string)$status->getNid();
		$options = (new ProbeOptions())
			->setFormat(ACore::FORMAT_LOCAL)
			->setProbe($probe)
			->setMinId(Nid::decrement($nid))
			->setMaxId(Nid::increment($nid))
			->setLimit(self::BATCH)
			->setInverted(true);

		if ($list !== null) {
			if ($viewer === null) {
				return false;
			}
			$this->listsRequest->setViewer($viewer);
			$statuses = $this->listsRequest->getTimeline($list, $options);
		} else {
			$statuses = $this->streamService->getTimeline($options);
		}

		foreach ($statuses as $candidate) {
			if (Nid::compare((string)$candidate->getNid(), $nid) === 0) {
				return true;
			}
		}

		return false;
	}

	private function editedStatusMatchesStream(Stream $status, string $stream): bool {
		if (str_starts_with($stream, 'hashtag')) {
			return false;
		}
		$local = str_contains($stream, ':local');
		$remote = str_contains($stream, ':remote');
		$onlyMedia = str_ends_with($stream, ':media');

		return (!$local || $status->isLocal())
			&& (!$remote || !$status->isLocal())
			&& (!$onlyMedia || $status->getAttachments() !== []);
	}

	private function sendDirectConversations(WebSocketServer $server, Subscriber $subscriber, Person $viewer): void {
		for ($pageNumber = 0; $pageNumber < self::MAX_BATCHES_PER_POLL && !$subscriber->closed; $pageNumber++) {
			$since = (string)($subscriber->context['directSince'] ?? '0');
			$page = $this->conversationService->getPage($viewer, ConversationService::MAX_LIMIT, 0, 0, $since, false);
			$conversations = $page['conversations'];
			$cursor = (string)$page['prev'];
			if (Nid::compare($cursor, $since) < 1) {
				$cursor = $since;
			}
			foreach ($conversations as $conversation) {
				$nid = $conversation->getLastStatusNid();
				if (Nid::compare((string)$nid, $cursor) > 0) {
					$cursor = (string)$nid;
				}
			}
			if ($cursor === $since && $conversations === []) {
				return;
			}
			foreach (array_reverse($conversations) as $conversation) {
				$this->send($server, $subscriber, ['direct'], 'conversation', $conversation->jsonSerialize());
			}
			$subscriber->context['directSince'] = $cursor;
			if (count($conversations) < ConversationService::MAX_LIMIT) {
				return;
			}
		}
	}

	private function sendListStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer, int $id, array $subscription): void {
		if (!(($subscription['list'] ?? null) instanceof MastodonList)) {
			$server->close($subscriber, 'invalid list stream state');
			return;
		}
		$list = $subscription['list'];
		$this->sendPages($server, $subscriber, 'listSince:' . $id, function (string $since) use ($viewer, $list): array {
			$this->listsRequest->setViewer($viewer);
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setLimit(self::BATCH)
				->setSince($since)
				->setInverted(true);
			$statuses = $this->listsRequest->getTimeline($list, $options);
			$cursor = $statuses === [] ? $since : (string)$statuses[0]->getNid();
			$hasMore = count($statuses) === self::BATCH;
			$statuses = array_reverse($statuses);
			$this->postDecorationService->decorate($statuses, $viewer->getId());
			if ($this->feedDisplayService->hidesReplies($viewer->getUserId())) {
				$statuses = array_values(array_filter($statuses, static fn (Stream $status): bool => $status->getInReplyTo() === ''));
			}
			return [
				'cursor' => $cursor,
				'items' => $this->filterService->apply($statuses, Filter::CONTEXT_HOME, $viewer),
				'hasMore' => $hasMore,
			];
		}, 'update', ['list']);
	}

	private function addListSubscription(Subscriber $subscriber, mixed $rawId): bool {
		$id = $this->listId($rawId);
		$viewer = $subscriber->context['viewer'] ?? null;
		if ($id === null || !$viewer instanceof Person) {
			return false;
		}
		$subscriptions = $subscriber->context['listSubscriptions'] ?? [];
		if (!is_array($subscriptions)) {
			$subscriptions = [];
		}
		if (isset($subscriptions[$id])) {
			return true;
		}
		if (count($subscriptions) >= self::MAX_LIST_SUBSCRIPTIONS) {
			return false;
		}

		try {
			$list = $this->listsRequest->getOwnedById($viewer->getId(), $id);
			$this->listsRequest->setViewer($viewer);
			$options = (new ProbeOptions())->setFormat(ACore::FORMAT_LOCAL)->setLimit(1);
			$latest = $this->listsRequest->getTimeline($list, $options);
			$subscriber->context['listSubscriptions'][$id] = [
				'id' => $id,
				'list' => $list,
				'since' => isset($latest[0]) ? (string)$latest[0]->getNid() : '0',
			];
			$subscriber->context['listSince:' . $id] = $subscriber->context['listSubscriptions'][$id]['since'];
			$subscriber->context['subscriptions']['list'] = true;
			return true;
		} catch (Throwable) {
			return false;
		}
	}

	private function listId(mixed $value): ?int {
		if (!is_string($value) && !is_int($value)) {
			return null;
		}
		$id = filter_var((string)$value, FILTER_VALIDATE_INT);

		return is_int($id) && $id > 0 ? $id : null;
	}

	/** @param array{stream: string, tag: string, local: bool, since: string} $subscription */
	private function sendHashtagStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer, string $key, array $subscription): void {
		$cursorKey = 'hashtagSince:' . $key;
		if (!isset($subscriber->context[$cursorKey])) {
			$subscriber->context[$cursorKey] = $subscription['since'];
		}
		$this->sendPages($server, $subscriber, $cursorKey, function (string $since) use ($viewer, $subscription): array {
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setProbe(ProbeOptions::HASHTAG)
				->setArgument($subscription['tag'])
				->setLocal($subscription['local'])
				->setSince($since)
				->setLimit(self::BATCH)
				->setInverted(true);
			$statuses = $this->streamService->getTimeline($options);
			$cursor = $statuses === [] ? $since : (string)$statuses[0]->getNid();
			$hasMore = count($statuses) === self::BATCH;
			$statuses = array_reverse($statuses);
			if ($this->feedDisplayService->hidesReplies($viewer->getUserId())) {
				$statuses = array_values(array_filter($statuses, static fn (Stream $status): bool => $status->getInReplyTo() === ''));
			}
			return [
				'cursor' => $cursor,
				'items' => $this->filterService->apply($statuses, Filter::CONTEXT_PUBLIC, $viewer),
				'hasMore' => $hasMore,
			];
		}, 'update', [$subscription['stream']]);
	}

	private function sendPublicStatuses(WebSocketServer $server, Subscriber $subscriber, Person $viewer, string $stream): void {
		$local = str_contains($stream, ':local');
		$remote = str_contains($stream, ':remote');
		$onlyMedia = str_ends_with($stream, ':media');
		$this->sendPages($server, $subscriber, 'publicSince:' . $stream, function (string $since) use ($viewer, $local, $remote, $onlyMedia): array {
			$options = (new ProbeOptions())
				->setFormat(ACore::FORMAT_LOCAL)
				->setProbe(ProbeOptions::PUBLIC)
				->setLocal($local)
				->setRemote($remote)
				->setOnlyMedia($onlyMedia)
				->setSince($since)
				->setLimit(self::BATCH)
				->setInverted(true);
			$statuses = $this->streamService->getTimeline($options);
			$cursor = $statuses === [] ? $since : (string)$statuses[0]->getNid();
			$hasMore = count($statuses) === self::BATCH;
			$statuses = array_reverse($statuses);
			if ($this->feedDisplayService->hidesReplies($viewer->getUserId())) {
				$statuses = array_values(array_filter($statuses, static fn (Stream $status): bool => $status->getInReplyTo() === ''));
			}
			$filtered = $this->filterService->apply($statuses, Filter::CONTEXT_PUBLIC, $viewer);
			return ['cursor' => $cursor, 'items' => $filtered, 'hasMore' => $hasMore];
		}, 'update', [$stream]);
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
			if ($subscriber->eventStream) {
				$server->send($subscriber, 'event: ' . $event . "\ndata: " . $status . "\n\n");
				return;
			}
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
