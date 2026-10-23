<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationSocialGraphCandidates;
use OCA\Social\Service\RecommendationTopicCatalog;
use PHPUnit\Framework\TestCase;

class RecommendationSocialGraphCandidatesTest extends TestCase {
	public function testSecondDegreeRecallStaysBoundedAndRequiresTopicLanguageAndHardHideEligibility(): void {
		$visible = (new Note())->setLanguage('de')->setContent('<p>Apple releases an iPhone update</p>');
		$unrelated = (new Note())->setContent('<p>Local tennis match results</p>');
		$wrongLanguage = (new Note())->setLanguage('en')->setContent('<p>Apple publishes an iPhone update</p>');
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->once())->method('recommendationSocialGraphCandidates')
			->with('since', 10, ['already-seen'], ['de'])
			->willReturn([
				'1' => $visible,
				'2' => $unrelated,
				'3' => $wrongLanguage,
			]);

		$source = new RecommendationSocialGraphCandidates(
			$streams,
			new RecommendationFeatureExtractor(new RecommendationTopicCatalog()),
		);
		$result = $source->matching('since', 2, ['already-seen'], ['de'], ['tech.apple'], ['tech.politics']);

		$this->assertSame(['1' => $visible], $result);
	}

	public function testHiddenTopicAndEmptyTopicListProduceNoGraphCandidates(): void {
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->never())->method('recommendationSocialGraphCandidates');
		$source = new RecommendationSocialGraphCandidates(
			$streams,
			new RecommendationFeatureExtractor(new RecommendationTopicCatalog()),
		);

		$this->assertSame([], $source->matching('since', 0, [], [], ['tech.apple'], []));
		$this->assertSame([], $source->matching('since', 2, [], [], [], []));
	}
}
