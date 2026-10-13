<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\StatusApiController;

/**
 * A poll is a status to its readers: it is liked, boosted and taken back
 * through `POST /api/v1/statuses/{id}/{act}` exactly like a note, and the
 * answer carries the reader's flags.
 */
class PollInteractionsTest extends ApiTestCase {
	private function poll(): \stdClass {
		return $this->publish('alice', [
			'status' => 'which one? ' . bin2hex(random_bytes(4)),
			'visibility' => 'public',
			'poll' => ['options' => ['left', 'right'], 'expires_in' => 600],
		]);
	}

	public function testAPollIsLikedAndUnlikedLikeAnyStatus(): void {
		$poll = $this->poll();

		$liked = $this->act('bob', $poll->id, 'favourite');
		$this->assertSame($poll->id, $liked->id);
		$this->assertTrue($liked->favourited, 'the answer carries the like');
		$this->assertInstanceOf(\stdClass::class, $liked->poll, 'and is still the poll');

		$read = $this->call(StatusApiController::class, 'statusGet', ['nid' => $poll->id], [], 'bob');
		$this->assertTrue($read->body->favourited, 'the like is stored');

		$unliked = $this->act('bob', $poll->id, 'unfavourite');
		$this->assertFalse($unliked->favourited);
	}

	public function testAPollIsBoostedAndUnboostedLikeAnyStatus(): void {
		$poll = $this->poll();

		$boosted = $this->act('bob', $poll->id, 'reblog');
		$this->assertTrue($boosted->reblogged, 'the answer carries the boost');

		$read = $this->call(StatusApiController::class, 'statusGet', ['nid' => $poll->id], [], 'bob');
		$this->assertTrue($read->body->reblogged, 'the boost is stored');

		$unboosted = $this->act('bob', $poll->id, 'unreblog');
		$this->assertFalse($unboosted->reblogged);
	}
}
