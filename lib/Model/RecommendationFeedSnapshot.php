<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use InvalidArgumentException;

/** Immutable, bounded ranking snapshot with viewer- and mode-bound cursors. */
final readonly class RecommendationFeedSnapshot {
	public const CURSOR_VERSION = 3;
	public const MAX_POSTS = 1000;
	public const ALGORITHM_VERSION = 4;

	/** @param list<string> $rankedNids @param list<string> $explorationNids @param list<array{nid: string, reason: array<string, mixed>}> $explanations */
	public function __construct(
		public string $id,
		public string $viewerKey,
		public string $mode,
		public string $mediaKind,
		public int $algorithmVersion,
		public int $profileVersion,
		public int $generation,
		public int $createdAt,
		public int $expiresAt,
		public array $rankedNids,
		public array $explorationNids = [],
		public array $explanations = [],
	) {
		if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1 || preg_match('/^[a-f0-9]{64}$/', $viewerKey) !== 1) {
			throw new InvalidArgumentException('Invalid recommendation snapshot identity');
		}
		if ($mode === '' || $algorithmVersion < 1 || $profileVersion < 0 || $generation < 0 || $createdAt < 0 || $expiresAt <= $createdAt) {
			throw new InvalidArgumentException('Invalid recommendation snapshot metadata');
		}
		foreach ($rankedNids as $nid) {
			if (!is_string($nid) || $nid === '') {
				throw new InvalidArgumentException('Snapshot post identifiers must be non-empty strings');
			}
		}
		if (count($rankedNids) > self::MAX_POSTS || count(array_unique($rankedNids)) !== count($rankedNids)) {
			throw new InvalidArgumentException('Recommendation snapshots must be bounded and unique');
		}
		if (!array_is_list($rankedNids)) {
			throw new InvalidArgumentException('Snapshot post identifiers must be an ordered list');
		}
		if (!array_is_list($explorationNids)) {
			throw new InvalidArgumentException('Exploration posts must be unique members of the ranked snapshot');
		}
		foreach ($explorationNids as $nid) {
			if (!is_string($nid) || $nid === '') {
				throw new InvalidArgumentException('Exploration post identifiers must be non-empty strings');
			}
		}
		if (count(array_unique($explorationNids)) !== count($explorationNids)
			|| array_diff($explorationNids, $rankedNids) !== []) {
			throw new InvalidArgumentException('Exploration posts must be unique members of the ranked snapshot');
		}
		if (!self::validExplanations($explanations, $rankedNids)) {
			throw new InvalidArgumentException('Snapshot explanations must be bounded and match ranked posts');
		}
	}

	/** @param list<string> $rankedNids @param list<string> $explorationNids @param list<array{nid: string, reason: array<string, mixed>}> $explanations */
	public static function create(
		string $viewerId,
		string $mode,
		string $mediaKind,
		int $profileVersion,
		int $generation,
		int $now,
		array $rankedNids,
		int $ttl = 3600,
		array $explorationNids = [],
		array $explanations = [],
	): self {
		$rankedNids = array_slice($rankedNids, 0, self::MAX_POSTS);
		foreach ($rankedNids as $nid) {
			if (!is_string($nid) || $nid === '') {
				throw new InvalidArgumentException('Snapshot post identifiers must be non-empty strings');
			}
		}
		$rankedNids = array_values(array_unique($rankedNids));
		$explorationNids = array_values(array_unique(array_filter(
			$explorationNids,
			static fn (mixed $nid): bool => is_string($nid) && in_array($nid, $rankedNids, true)
		)));

		return new self(
			bin2hex(random_bytes(16)),
			hash('sha256', $viewerId),
			$mode,
			$mediaKind,
			self::ALGORITHM_VERSION,
			$profileVersion,
			$generation,
			$now,
			$now + max(1, min(3600, $ttl)),
			$rankedNids,
			$explorationNids,
			$explanations,
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'viewer_key' => $this->viewerKey,
			'mode' => $this->mode,
			'media_kind' => $this->mediaKind,
			'algorithm_version' => $this->algorithmVersion,
			'profile_version' => $this->profileVersion,
			'generation' => $this->generation,
			'created_at' => $this->createdAt,
			'expires_at' => $this->expiresAt,
			'ranked_nids' => $this->rankedNids,
			'exploration_nids' => $this->explorationNids,
			'explanations' => $this->explanations,
		];
	}

	/** Rehydrate a cache entry; malformed or stale-shaped entries are rejected. */
	public static function fromArray(array $data): ?self {
		if (!is_string($data['id'] ?? null)
			|| !is_string($data['viewer_key'] ?? null)
			|| !is_string($data['mode'] ?? null)
			|| !is_string($data['media_kind'] ?? null)
			|| !is_int($data['algorithm_version'] ?? null)
			|| !is_int($data['profile_version'] ?? null)
			|| !is_int($data['generation'] ?? null)
			|| !is_int($data['created_at'] ?? null)
			|| !is_int($data['expires_at'] ?? null)
			|| !is_array($data['ranked_nids'] ?? null)
			|| (array_key_exists('exploration_nids', $data) && !is_array($data['exploration_nids']))
			|| (array_key_exists('explanations', $data) && !is_array($data['explanations']))) {
			return null;
		}
		try {
			return new self(
				$data['id'],
				$data['viewer_key'],
				$data['mode'],
				$data['media_kind'],
				$data['algorithm_version'],
				$data['profile_version'],
				$data['generation'],
				$data['created_at'],
				$data['expires_at'],
				$data['ranked_nids'],
				$data['exploration_nids'] ?? [],
				$data['explanations'] ?? [],
			);
		} catch (InvalidArgumentException) {
			return null;
		}
	}

	/** @param list<string> $rankedNids */
	private static function validExplanations(array $explanations, array $rankedNids): bool {
		if (!array_is_list($explanations) || count($explanations) > count($rankedNids)) {
			return false;
		}
		$seen = [];
		foreach ($explanations as $entry) {
			if (!is_array($entry) || !is_string($entry['nid'] ?? null) || !is_array($entry['reason'] ?? null)
				|| !in_array($entry['nid'], $rankedNids, true) || isset($seen[$entry['nid']])) {
				return false;
			}
			$seen[$entry['nid']] = true;
			$reason = $entry['reason'];
			$allowedAlso = ['FOLLOWED_AUTHOR', 'BEHAVIORAL_PROFILE', 'SIMILARITY_FEEDBACK', 'AUTOMATIC_PROFILE', 'AUTHOR_FEEDBACK', 'FORMAT_FEEDBACK', 'CACHED_CANDIDATE'];
			$allowedNotes = ['BASE_RANK', 'DIVERSITY_PLACEMENT', 'EXPLORATION_SLOT'];
			if (!in_array($reason['primary'] ?? null, ['TOPIC_EXPLICIT', 'AUTOMATIC_PROFILE', 'EXPLORATION'], true)
				|| !is_array($reason['topic_ids'] ?? null) || !array_is_list($reason['topic_ids']) || count($reason['topic_ids']) > 8
				|| !is_array($reason['also'] ?? null) || !array_is_list($reason['also']) || count($reason['also']) > count($allowedAlso)
				|| ($reason['candidate_source'] ?? null) !== 'LOCAL_CACHED_POSTS'
				|| !in_array($reason['protocol'] ?? null, ['activitypub', 'atproto'], true)
				|| !is_bool($reason['personalisation_used'] ?? null)
				|| ($reason['selection_stage'] ?? null) !== 'RERANK'
				|| !in_array($reason['rerank_note'] ?? null, $allowedNotes, true)
				|| !is_int($reason['profile_version'] ?? null) || $reason['profile_version'] < 0
				|| !is_int($reason['eligibility_policy_version'] ?? null) || $reason['eligibility_policy_version'] < 1
				|| !is_int($reason['explanation_version'] ?? null) || $reason['explanation_version'] < 1
				|| !is_int($reason['algorithm_version'] ?? null) || $reason['algorithm_version'] < 1
				|| !is_int($reason['created_at'] ?? null) || $reason['created_at'] < 0) {
				return false;
			}
			foreach ($reason['also'] as $also) {
				if (!is_string($also) || !in_array($also, $allowedAlso, true)) {
					return false;
				}
			}
			foreach ($reason['topic_ids'] as $topicId) {
				if (!is_string($topicId) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $topicId) !== 1) {
					return false;
				}
			}
		}
		return true;
	}

	public function cursor(int $position, string $secret): string {
		if ($position < 0 || $position > count($this->rankedNids) || strlen($secret) < 32) {
			throw new InvalidArgumentException('Invalid recommendation cursor input');
		}
		$payload = json_encode([
			'v' => self::CURSOR_VERSION,
			'sid' => $this->id,
			'algorithm' => $this->algorithmVersion,
			'viewer' => $this->viewerKey,
			'mode' => $this->mode,
			'media' => $this->mediaKind,
			'profile' => $this->profileVersion,
			'generation' => $this->generation,
			'expires' => $this->expiresAt,
			'position' => $position,
		], JSON_THROW_ON_ERROR);
		$encoded = self::base64UrlEncode($payload);
		$signature = hash_hmac('sha256', $encoded, $secret, true);

		return $encoded . '.' . self::base64UrlEncode($signature);
	}

	/**
	 * Verify a cursor before loading its immutable snapshot from shared cache.
	 * Returns null for every malformed, expired, cross-viewer or cross-mode token.
	 *
	 * @return array{id: string, position: int, profile_version: int, generation: int, expires_at: int}|null
	 */
	public static function verifyCursor(
		string $cursor,
		string $secret,
		string $viewerId,
		string $mode,
		string $mediaKind,
		int $profileVersion,
		int $generation,
		int $now,
	): ?array {
		if (strlen($secret) < 32 || strlen($cursor) > 2048 || substr_count($cursor, '.') !== 1) {
			return null;
		}
		[$encoded, $signature] = explode('.', $cursor, 2);
		$decodedSignature = self::base64UrlDecode($signature);
		$decodedPayload = self::base64UrlDecode($encoded);
		if ($decodedSignature === null || $decodedPayload === null
			|| !hash_equals(hash_hmac('sha256', $encoded, $secret, true), $decodedSignature)) {
			return null;
		}

		try {
			$payload = json_decode($decodedPayload, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($payload)
			|| ($payload['v'] ?? null) !== self::CURSOR_VERSION
			|| ($payload['algorithm'] ?? null) !== self::ALGORITHM_VERSION
			|| !is_string($payload['sid'] ?? null)
			|| preg_match('/^[a-f0-9]{32}$/', $payload['sid']) !== 1
			|| !is_string($payload['viewer'] ?? null)
			|| !hash_equals(hash('sha256', $viewerId), $payload['viewer'])
			|| ($payload['mode'] ?? null) !== $mode
			|| ($payload['media'] ?? null) !== $mediaKind
			|| !is_int($payload['profile'] ?? null)
			|| $payload['profile'] !== $profileVersion
			|| !is_int($payload['generation'] ?? null)
			|| $payload['generation'] !== $generation
			|| !is_int($payload['expires'] ?? null)
			|| $payload['expires'] <= $now
			|| !is_int($payload['position'] ?? null)
			|| $payload['position'] < 0) {
			return null;
		}

		return [
			'id' => $payload['sid'],
			'position' => $payload['position'],
			'profile_version' => $payload['profile'],
			'generation' => $payload['generation'],
			'expires_at' => $payload['expires'],
		];
	}

	/** @return list<string> */
	public function page(int $position, int $limit): array {
		if ($position < 0 || $position > count($this->rankedNids)) {
			return [];
		}

		return array_slice($this->rankedNids, $position, max(0, min(50, $limit)));
	}

	private static function base64UrlEncode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function base64UrlDecode(string $value): ?string {
		if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
			return null;
		}
		$decoded = base64_decode(strtr($value, '-_', '+/'), true);
		if ($decoded === false || self::base64UrlEncode($decoded) !== $value) {
			return null;
		}

		return $decoded;
	}
}
