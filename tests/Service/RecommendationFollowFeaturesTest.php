<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\RecommendationFollowFeatures;
use PHPUnit\Framework\TestCase;

class RecommendationFollowFeaturesTest extends TestCase {
	public function testOnlyAcceptedFollowRowsContributeTheIndependentFollowFeature(): void {
		$viewer = (new Person())->setId('https://example.test/users/reader')->setLocal(true);
		$author = 'https://remote.example/users/author';
		$post = (new Note())->setAttributedTo($author);
		$pending = (new Follow())->setAccepted(false);
		$follows = $this->createMock(FollowsRequest::class);
		$follows->expects($this->once())->method('getBetweenMany')
			->with($viewer->getId(), [$author])
			->willReturn(['following' => [$author => $pending], 'followedBy' => []]);

		$this->assertSame([], (new RecommendationFollowFeatures($follows))->followedAuthors($viewer, [$post]));
	}

	public function testAnEmptyCandidateBatchDoesNotQueryTheFollowTable(): void {
		$viewer = (new Person())->setId('https://example.test/users/reader')->setLocal(true);
		$follows = $this->createMock(FollowsRequest::class);
		$follows->expects($this->never())->method('getBetweenMany');

		$this->assertSame([], (new RecommendationFollowFeatures($follows))->followedAuthors($viewer, []));
	}
}
