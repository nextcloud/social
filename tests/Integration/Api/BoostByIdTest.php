<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\StatusApiController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Integration\Helper\RowSeeder;

/**
 * A boost asked for by its own id, `GET /api/v1/statuses/{id}`, is the
 * status a timeline shows for it: the boosted status in `reblog`, decorated
 * for the reader. A boost of a status the reader may not see is a 404, as on
 * Mastodon, which refuses a status the reader is not permitted to see and
 * never serves a boost without what it boosts.
 */
class BoostByIdTest extends ApiTestCase {
	/** @return array{\stdClass, string} the boosted post and the boost's id */
	private function boostedPost(): array {
		$post = $this->publish('alice', ['status' => 'boosted, then asked for by the boost', 'visibility' => 'public']);
		$this->follow('carol', 'bob');
		$this->act('bob', $post->id, 'reblog');

		$home = $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'home'], [], 'carol');
		$boosts = array_values(array_filter($home->body, static fn (\stdClass $status): bool => $status->reblog !== null));
		$this->assertCount(1, $boosts, 'the boost is on the follower\'s timeline');

		return [$post, $boosts[0]->id];
	}

	public function testABoostAskedForByItsOwnIdCarriesTheBoostedStatus(): void {
		[$post, $boostId] = $this->boostedPost();

		foreach (['carol' => 'a follower of the booster', 'bob' => 'the booster'] as $reader => $who) {
			$boost = $this->call(StatusApiController::class, 'statusGet', ['nid' => $boostId], [], $reader);
			$this->assertSame(200, $boost->status, $who);
			$this->assertSame($boostId, $boost->body->id, $who . ': the boost itself');
			$this->assertSame((string)$this->account('bob')->getNid(), $boost->body->account->id, $who . ': by the booster');
			$this->assertInstanceOf(\stdClass::class, $boost->body->reblog, $who . ': with what it boosts');
			$this->assertSame($post->id, $boost->body->reblog->id, $who);
			$this->assertSame((string)$this->account('alice')->getNid(), $boost->body->reblog->account->id, $who . ': by its author');
			$this->assertEntity('Status', $boost->body->reblog, $who);
		}

		$asBooster = $this->call(StatusApiController::class, 'statusGet', ['nid' => $boostId], [], 'bob');
		$this->assertTrue($asBooster->body->reblog->reblogged, 'decorated for the reader: the booster sees their boost');
		// what a reader did to the boosted status shows on the boost in a timeline too
		$this->act('carol', $post->id, 'favourite');
		$home = $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'home'], [], 'carol');
		$onHome = array_values(array_filter($home->body, static fn (\stdClass $status): bool => $status->id === $boostId));
		$this->assertTrue($onHome[0]->reblog->favourited ?? null, 'the follower\'s favourite, on the boost in their timeline');

		$anonymous = $this->call(StatusApiController::class, 'statusGet', ['nid' => $boostId], [], null);
		$this->assertSame($post->id, $anonymous->body->reblog->id ?? null, 'a public boost of a public post, read by nobody');
	}

	public function testABoostOfAStatusTheReaderMayNotSeeIsNotFound(): void {
		[$post, $boostId] = $this->boostedPost();
		// the boosted status no longer addressed to the public: only its
		// author, and whoever it is addressed to, may read it
		RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM_DEST, [
			'stream_id' => md5($post->uri), 'actor_id' => md5(Stream::CONTEXT_PUBLIC),
		]);

		$this->assertSame(404, $this->call(StatusApiController::class, 'statusGet', ['nid' => $boostId], [], 'carol')->status, 'a reader who may not see it');
		$this->assertSame(404, $this->call(StatusApiController::class, 'statusGet', ['nid' => $boostId], [], null)->status, 'nobody');

		$asAuthor = $this->call(StatusApiController::class, 'statusGet', ['nid' => $boostId], [], 'alice');
		$this->assertSame(200, $asAuthor->status, 'its author still may');
		$this->assertSame($post->id, $asAuthor->body->reblog->id);
	}
}
