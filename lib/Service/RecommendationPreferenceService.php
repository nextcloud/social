<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Db\RecommendationPreferenceRequest;

/** Validates and owns the explicit, per-account topic controls. */
class RecommendationPreferenceService {
	public const MODES = ['HIDE', 'LESS', 'NEUTRAL', 'INTERESTED', 'PRIORITISE'];
	public const CONTENT_BALANCES = ['balanced', 'following', 'discovery'];
	public const MAX_TOPICS = 150;

	public function __construct(
		private RecommendationPreferenceRequest $request,
		private RecommendationTopicCatalog $catalog,
		private ConfigService $configService,
	) {
	}

	/** Instance kill switch: absent configuration is always disabled. */
	public function isEnabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::REC_V4_ENABLED);
	}

	public function setEnabled(bool $enabled): bool {
		$this->configService->setAppValue(ConfigService::REC_V4_ENABLED, $enabled ? '1' : '0');
		return $this->isEnabled();
	}

	/** @return list<array{id: string, parentId: ?string, labels: array{en: string, de: string}, aliases: list<string>, policyTags: list<string>, languageHints: list<string>, version: int, enabled: bool}> */
	public function catalog(): array {
		return $this->catalog->all();
	}

	/** @return array{revision: int, generation: int, learning_mode: string, learning: bool, content_balance: string, onboarding_step: int, onboarding_complete: bool, topics: list<array{topic_id: string, mode: string}>, effective_topics?: array<string, string|null>} */
	public function get(string $actorKey): array {
		$state = $this->request->get($actorKey);
		$state['learning'] = $state['learning_mode'] === 'on';
		$state['effective_topics'] = $this->effectiveTopics($state['topics']);
		return $state;
	}

	/** Change the separate, default-off consent for behavioural learning. */
	public function setLearningMode(string $actorKey, int $revision, string $mode): ?array {
		if (!$this->isEnabled()) {
			throw new InvalidArgumentException('Recommendations are disabled');
		}
		$state = $this->request->setLearningMode($actorKey, $revision, $mode);
		return $state === null ? null : $this->withEffectiveTopics($state);
	}

	/** @param list<mixed> $topics
	 * @return array{revision: int, generation: int, learning_mode: string, learning: bool, content_balance: string, onboarding_step: int, onboarding_complete: bool, topics: list<array{topic_id: string, mode: string}>, effective_topics: array<string, string|null>}|null
	 */
	public function replace(string $actorKey, int $revision, array $topics, ?string $contentBalance = null, ?int $onboardingStep = null, ?bool $onboardingComplete = null): ?array {
		if ($contentBalance !== null && !in_array($contentBalance, self::CONTENT_BALANCES, true)) {
			throw new InvalidArgumentException('Unknown content balance');
		}
		if ($onboardingStep !== null && ($onboardingStep < 0 || $onboardingStep > 5)) {
			throw new InvalidArgumentException('Onboarding step must be between 0 and 5');
		}
		if ($onboardingComplete === true && $onboardingStep !== 5) {
			throw new InvalidArgumentException('Completing onboarding requires the review step');
		}
		if ($onboardingStep === 5 && $onboardingComplete !== true) {
			throw new InvalidArgumentException('The review step must be marked complete');
		}

		$state = $this->request->replace($actorKey, $revision, $this->validate($topics), $contentBalance, $onboardingStep, $onboardingComplete);
		return $state === null ? null : $this->withEffectiveTopics($state);
	}

	/** @return array{revision: int, generation: int, learning_mode: string, learning: bool, content_balance: string, onboarding_step: int, onboarding_complete: bool, topics: list<array{topic_id: string, mode: string}>, effective_topics: array<string, string|null>}|null */
	public function reset(string $actorKey, int $revision): ?array {
		$state = $this->request->reset($actorKey, $revision);
		return $state === null ? null : $this->withEffectiveTopics($state);
	}

	/** Clear only implicit signals and increment their reset generation.
	 * @return array{revision: int, generation: int, learning_mode: string, learning: bool, content_balance: string, onboarding_step: int, onboarding_complete: bool, topics: list<array{topic_id: string, mode: string}>, effective_topics: array<string, string|null>}|null
	 */
	public function resetLearning(string $actorKey, int $revision): ?array {
		$state = $this->request->resetLearning($actorKey, $revision);
		return $state === null ? null : $this->withEffectiveTopics($state);
	}

	/** @param array{revision: int, generation: int, learning_mode: string, content_balance: string, onboarding_step: int, onboarding_complete: bool, topics: list<array{topic_id: string, mode: string}>} $state */
	private function withEffectiveTopics(array $state): array {
		$state['learning'] = $state['learning_mode'] === 'on';
		$state['effective_topics'] = $this->effectiveTopics($state['topics']);
		return $state;
	}

	/**
	 * Child choices override inherited soft values. Any explicit HIDE on the
	 * topic or an ancestor is absolute for this first release.
	 *
	 * @param list<array{topic_id: string, mode: string}> $choices
	 * @return array<string, string|null>
	 */
	private function effectiveTopics(array $choices): array {
		$explicit = [];
		foreach ($choices as $choice) {
			$explicit[$choice['topic_id']] = $choice['mode'];
		}
		$effective = [];
		foreach ($this->catalog->all() as $topic) {
			$id = $topic['id'];
			$ancestor = $this->catalog->parentOf($id);
			$inherited = null;
			while ($ancestor !== null) {
				if (($explicit[$ancestor] ?? null) === 'HIDE') {
					$inherited = 'HIDE';
					break;
				}
				if ($inherited === null && isset($explicit[$ancestor])) {
					$inherited = $explicit[$ancestor];
				}
				$ancestor = $this->catalog->parentOf($ancestor);
			}
			$effective[$id] = $inherited === 'HIDE' ? 'HIDE' : ($explicit[$id] ?? $inherited);
		}

		return $effective;
	}

	/** @param list<mixed> $topics
	 * @return list<array{topic_id: string, mode: string}>
	 */
	private function validate(array $topics): array {
		if (count($topics) > self::MAX_TOPICS) {
			throw new InvalidArgumentException('At most ' . self::MAX_TOPICS . ' topic preferences are allowed');
		}
		$seen = [];
		$normalised = [];
		foreach ($topics as $topic) {
			if (!is_array($topic)
				|| !isset($topic['topic_id'], $topic['mode'])
				|| !is_string($topic['topic_id'])
				|| !is_string($topic['mode'])) {
				throw new InvalidArgumentException('Each topic preference needs a topic_id and mode');
			}
			$id = $topic['topic_id'];
			$mode = strtoupper($topic['mode']);
			if (!$this->catalog->has($id)) {
				throw new InvalidArgumentException('Unknown topic: ' . $id);
			}
			if (!in_array($mode, self::MODES, true)) {
				throw new InvalidArgumentException('Unknown topic mode: ' . $mode);
			}
			if (isset($seen[$id])) {
				throw new InvalidArgumentException('Duplicate topic: ' . $id);
			}
			$seen[$id] = true;
			$normalised[] = ['topic_id' => $id, 'mode' => $mode];
		}

		usort($normalised, static fn (array $a, array $b): int => $a['topic_id'] <=> $b['topic_id']);
		return $normalised;
	}
}
