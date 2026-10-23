<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationTopicCatalog;
use PHPUnit\Framework\TestCase;

/** Stable topic and format features from public post content. */
class RecommendationFeatureExtractorTest extends TestCase {
	private function extractor(): RecommendationFeatureExtractor {
		return new RecommendationFeatureExtractor(new RecommendationTopicCatalog());
	}

	public function testMatchesUntaggedWordsAndPropagatesNonSensitiveParents(): void {
		$post = (new Note())->setContent('<p>Nextcloud veröffentlicht heute eine neue Version.</p>')->setLanguage('de');

		$features = $this->extractor()->extract($post);

		$this->assertSame(2, $features['version']);
		$this->assertSame('de', $features['language']);
		$this->assertSame(0.72, $features['topics']['oss.nextcloud']['score']);
		$this->assertSame('text', $features['topics']['oss.nextcloud']['evidence']);
		$this->assertArrayHasKey('oss', $features['topics']);
	}

	public function testWordBoundariesPreventSubstringMatchesAndHashtagsRemainStrongEvidence(): void {
		$extractor = $this->extractor();
		$substring = $extractor->extract((new Note())->setContent('<p>Pineapple is delicious.</p>'));
		$this->assertArrayNotHasKey('tech.apple', $substring['topics']);
		$splitTag = $extractor->extract((new Note())->setContent('<p>Next</p><p>cloud update</p>'));
		$this->assertArrayNotHasKey('oss.nextcloud', $splitTag['topics']);

		$tagged = $extractor->extract((new Note())->setContent('<p>Self-hosting.</p>')->setHashtags(['OpenSource']));
		$this->assertSame(1.0, $tagged['topics']['oss']['score']);
		$this->assertSame('hashtag', $tagged['topics']['oss']['evidence']);
	}

	public function testSensitivePolicyTopicsAreNotInferredAndFormatUsesStoredMediaFacts(): void {
		$extractor = $this->extractor();
		$politics = $extractor->extract((new Note())->setContent('<p>International politics today.</p>'));
		$this->assertArrayNotHasKey('politics', $politics['topics']);
		$this->assertArrayNotHasKey('politics.international', $politics['topics']);

		$short = (new Note())->setAttachments([
			(new MediaAttachment())->import([
				'type' => 'video',
				'meta' => ['duration' => 20, 'original' => ['width' => 720, 'height' => 1280]],
			]),
		]);
		$this->assertSame('short', $extractor->extract($short)['format']);
		$this->assertSame('poll', $extractor->extract(new Question())['format']);
	}

	public function testExpandedTopicAliasesMatchEnglishAndGermanContent(): void {
		$extractor = $this->extractor();
		$englishAi = $extractor->extract((new Note())->setContent('<p>Artificial intelligence and machine learning research.</p>'));
		$germanClimate = $extractor->extract((new Note())->setContent('<p>Der Klimawandel verändert die Umwelt.</p>'));
		$englishSports = $extractor->extract((new Note())->setContent('<p>Football and basketball highlights.</p>'));
		$germanGaming = $extractor->extract((new Note())->setContent('<p>Neue Videospiele erscheinen heute.</p>'));
		$germanBusiness = $extractor->extract((new Note())->setContent('<p>Wirtschaft und Unternehmen im Wandel.</p>'));
		$germanCulture = $extractor->extract((new Note())->setContent('<p>Kultur und Kunst im Museum.</p>'));
		$germanPhotography = $extractor->extract((new Note())->setContent('<p>Fotografie mit natürlichem Licht.</p>'));
		$englishEducation = $extractor->extract((new Note())->setContent('<p>New approaches to education and teaching.</p>'));
		$englishSoftware = $extractor->extract((new Note())->setContent('<p>Programming and software development.</p>'));

		$this->assertArrayHasKey('tech.ai', $englishAi['topics']);
		$this->assertArrayHasKey('technology', $englishAi['topics']);
		$this->assertArrayHasKey('environment', $germanClimate['topics']);
		$this->assertArrayHasKey('sports', $englishSports['topics']);
		$this->assertArrayHasKey('gaming', $germanGaming['topics']);
		$this->assertArrayHasKey('business', $germanBusiness['topics']);
		$this->assertArrayHasKey('culture', $germanCulture['topics']);
		$this->assertArrayHasKey('photography', $germanPhotography['topics']);
		$this->assertArrayHasKey('education', $englishEducation['topics']);
		$this->assertArrayHasKey('software', $englishSoftware['topics']);
		$this->assertSame(2, $englishAi['version']);
	}
}
