<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\StatusApiController;
use OCA\Social\Db\StreamRequest;
use OCP\Server;

/**
 * The `source` column holds a post's ActivityPub document, and an edit or a
 * vote rewrites it. Both read the post in the client format first, and the
 * snapshot was taken as the post stood: the Mastodon entity went into the
 * column in place of the document. Read back from there are the custom
 * emoji (from its `tag`) and a poll's options and counts (from its `oneOf`).
 */
class StoredSourceTest extends ApiTestCase {
	/** @return array<string, mixed> the stored document of the status */
	private function storedSource(\stdClass $status): array {
		$source = json_decode(Server::get(StreamRequest::class)->getStreamById($status->uri)->getSource(), true);
		$this->assertIsArray($source);

		return $source;
	}

	public function testAnEditStoresTheActivityPubDocument(): void {
		$status = $this->publish('alice', ['status' => 'first words']);

		$edited = $this->call(StatusApiController::class, 'statusUpdate', ['nid' => $status->id], [
			'status' => 'second words',
		], 'alice', 'PUT');
		$this->assertSame(200, $edited->status, json_encode($edited->body) ?: '');

		$source = $this->storedSource($status);
		$this->assertSame('Note', $source['type'] ?? null);
		$this->assertStringContainsString('second words', (string)($source['content'] ?? ''));
		$this->assertArrayNotHasKey('spoiler_text', $source, 'the document, not the client entity');
	}

	public function testAVoteKeepsThePollAndItsCount(): void {
		$poll = $this->publish('alice', ['status' => 'cats or dogs?', 'poll' => ['options' => ['cats', 'dogs'], 'expires_in' => 3600]]);

		$vote = $this->call(StatusApiController::class, 'pollVote', ['nid' => $poll->id], ['choices' => ['0']], 'bob', 'POST');
		$this->assertSame(200, $vote->status, json_encode($vote->body) ?: '');

		$source = $this->storedSource($poll);
		$this->assertSame('Question', $source['type'] ?? null);
		$this->assertSame(1, $source['oneOf'][0]['replies']['totalItems'] ?? null);

		$read = $this->call(StatusApiController::class, 'pollGet', ['nid' => $poll->id], [], 'carol');
		$this->assertSame(200, $read->status, json_encode($read->body) ?: '');
		$this->assertSame(1, $read->body->votes_count);
		$this->assertCount(2, $read->body->options);
	}
}
