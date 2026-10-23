<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Db\RecommendationFeedbackRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\AppFramework\Utility\ITimeFactory;

/** Validates explicit, reversible recommendations against a post the viewer can read. */
class RecommendationFeedbackService {
	public const UNDO_SECONDS = 600;
	private const MAX_AUTHOR_KEY_LENGTH = 2048;
	private const OPERATIONS = ['HIDE_POST', 'MORE_TOPIC', 'LESS_TOPIC', 'MORE_AUTHOR', 'MORE_FORMAT'];

	public function __construct(
		private RecommendationFeedbackRequest $request,
		private RecommendationPreferenceService $preferences,
		private RecommendationTopicCatalog $catalog,
		private RecommendationFeatureExtractor $features,
		private StreamRequest $streams,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return array{feedback_id: string, event_id: string, operation: string, applied_scope: array{type: string, key: string}, feedback_version: int, changed_for_next_session: bool, undo_until: int, duplicate: bool} */
	public function record(Person $viewer, string $eventId, string $postId, string $operation, string $topicId = ''): array {
		$eventId = strtolower(trim($eventId));
		$postId = trim($postId);
		$operation = strtoupper(trim($operation));
		$topicId = trim($topicId);
		if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $eventId) !== 1) {
			throw new InvalidArgumentException('event_id must be a UUID');
		}
		if (preg_match('/^[0-9]{1,20}$/', $postId) !== 1) {
			throw new InvalidArgumentException('post_id must be a stream identifier');
		}
		if (!in_array($operation, self::OPERATIONS, true)) {
			throw new InvalidArgumentException('Unknown recommendation feedback operation');
		}
		if (!$this->preferences->isEnabled()) {
			throw new ItemNotFoundException('Recommendations are disabled');
		}

		$existing = $this->request->byEvent($viewer->getUserId(), $eventId);
		if ($existing !== null) {
			return self::response($existing, $this->request->feedbackVersion($viewer->getUserId()), true);
		}
		$this->preferences->get($viewer->getUserId());

		$this->streams->setViewer($viewer);
		$visible = $this->streams->getVisibleByNids([$postId]);
		$post = $visible[$postId] ?? null;
		if (!$post instanceof Stream || $post->getAttributedTo() === $viewer->getId()) {
			throw new ItemNotFoundException('Post not found');
		}

		$features = $this->features->extract($post);
		[$scope, $scopeKey] = $this->scope($operation, $post, $features, $topicId);
		$feedbackId = self::uuid();
		$now = $this->timeFactory->getTime();
		$stored = $this->request->record(
			$viewer->getUserId(), $feedbackId, $eventId, $scope, $scopeKey, $operation, $now
		);

		return self::response($stored['record'], $stored['feedback_version'], $stored['duplicate']);
	}

	/** @return array{undone: bool, expired: bool, feedback_version: int} */
	public function undo(Person $viewer, string $feedbackId): array {
		if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $feedbackId) !== 1) {
			throw new InvalidArgumentException('Invalid feedback id');
		}
		return $this->request->undo($viewer->getUserId(), strtolower($feedbackId), $this->timeFactory->getTime());
	}

	/** Return only feedback choices that match a currently visible post. */
	public function context(Person $viewer, string $postId): array {
		$postId = trim($postId);
		if (preg_match('/^[0-9]{1,20}$/', $postId) !== 1) {
			throw new InvalidArgumentException('post_id must be a stream identifier');
		}
		if (!$this->preferences->isEnabled()) {
			throw new ItemNotFoundException('Recommendations are disabled');
		}

		$this->streams->setViewer($viewer);
		$post = $this->streams->getVisibleByNids([$postId])[$postId] ?? null;
		if (!$post instanceof Stream || $post->getAttributedTo() === $viewer->getId()) {
			throw new ItemNotFoundException('Post not found');
		}

		$features = $this->features->extract($post);
		$topics = [];
		foreach (array_keys($features['topics']) as $topicId) {
			$topic = $this->catalog->find($topicId);
			if ($topic === null || in_array('sensitive-topic', $topic['policyTags'], true)) {
				continue;
			}
			$topics[] = ['id' => $topicId, 'labels' => $topic['labels']];
		}

		return ['topics' => $topics, 'format' => $features['format']];
	}

	/** @return array{hidden_nids: list<string>, topics: array<string, string>, authors: array<string, string>, formats: array<string, string>} */
	public function current(string $actorKey): array {
		$state = ['hidden_nids' => [], 'topics' => [], 'authors' => [], 'formats' => []];
		foreach ($this->request->current($actorKey) as $entry) {
			$scopeKey = (string)$entry['scope_key'];
			$operation = (string)$entry['operation'];
			switch ($entry['scope']) {
				case 'post':
					if ($operation === 'HIDE_POST') {
						$state['hidden_nids'][] = $scopeKey;
					}
					break;
				case 'topic':
					$state['topics'][$scopeKey] = $operation;
					break;
				case 'author':
					$state['authors'][$scopeKey] = $operation;
					break;
				case 'format':
					$state['formats'][$scopeKey] = $operation;
					break;
			}
		}
		return $state;
	}

	/** @return list<array{scope: string, scope_key: string, operation: string, created_at: int}> */
	public function export(string $actorKey): array {
		$export = [];
		foreach ($this->request->current($actorKey) as $entry) {
			$export[] = [
				'scope' => (string)$entry['scope'],
				'scope_key' => (string)$entry['scope_key'],
				'operation' => (string)$entry['operation'],
				'created_at' => (int)$entry['created_at'],
			];
		}
		return $export;
	}

	/** Apply current explicit feedback before the deterministic ranker runs. */
	public function apply(array $candidate, string $topicId, array $feedback): array {
		$topicOperation = $feedback['topics'][$topicId] ?? null;
		$applied = false;
		if ($topicOperation === 'MORE_TOPIC') {
			$candidate['topic_match'] = min(1.0, (float)($candidate['topic_match'] ?? 0.0) + 0.28);
			$applied = true;
		} elseif ($topicOperation === 'LESS_TOPIC') {
			$candidate['topic_match'] = max(-1.0, (float)($candidate['topic_match'] ?? 0.0) - 0.55);
			$applied = true;
		}

		$authorId = (string)($candidate['author_key'] ?? '');
		if ($authorId !== '' && ($feedback['authors'][self::authorKey($authorId)] ?? null) === 'MORE_AUTHOR') {
			$candidate['author_affinity'] = 1.0;
			$applied = true;
		}
		$format = (string)($candidate['format'] ?? '');
		if ($format !== '' && ($feedback['formats'][$format] ?? null) === 'MORE_FORMAT') {
			$candidate['format_match'] = 1.0;
			$applied = true;
		}
		$candidate['feedback_applied'] = $applied;
		return $candidate;
	}

	/** @return array{string, string} */
	private function scope(string $operation, Stream $post, array $features, string $topicId): array {
		if ($operation === 'HIDE_POST') {
			return ['post', (string)$post->getNid()];
		}
		if ($operation === 'MORE_TOPIC' || $operation === 'LESS_TOPIC') {
			$topic = $this->catalog->find($topicId);
			if ($topic === null || in_array('sensitive-topic', $topic['policyTags'], true)
				|| !isset($features['topics'][$topicId])) {
				throw new InvalidArgumentException('The selected topic is not a reliable label for this post');
			}
			return ['topic', $topicId];
		}
		if ($operation === 'MORE_AUTHOR') {
			$author = $post->getAttributedTo();
			if ($author === '' || strlen($author) > self::MAX_AUTHOR_KEY_LENGTH) {
				throw new InvalidArgumentException('This post has no usable author identity');
			}
			return ['author', self::authorKey($author)];
		}
		$format = (string)$features['format'];
		if (!in_array($format, ['audio', 'image', 'poll', 'short', 'text', 'video'], true)) {
			throw new InvalidArgumentException('This post has no supported content format');
		}
		return ['format', $format];
	}

	private static function authorKey(string $authorId): string {
		return hash('sha256', $authorId);
	}

	/** @param array<string, mixed> $record @return array<string, mixed> */
	private static function response(array $record, int $version, bool $duplicate): array {
		return [
			'feedback_id' => (string)$record['feedback_id'],
			'event_id' => (string)$record['event_id'],
			'operation' => (string)$record['operation'],
			'applied_scope' => ['type' => (string)$record['scope'], 'key' => (string)$record['scope_key']],
			'feedback_version' => $version,
			'changed_for_next_session' => (int)($record['active'] ?? 1) === 1,
			'undo_until' => (int)$record['undo_until'],
			'duplicate' => $duplicate,
		];
	}

	private static function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex = bin2hex($bytes);
		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
	}
}
