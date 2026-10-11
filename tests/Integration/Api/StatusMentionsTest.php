<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\StatusApiController;

/**
 * A status written here names the accounts it mentions in `mentions`.
 *
 * The list was only ever filled for posts that arrived over ActivityPub, so
 * every post written on this instance — through the API or the web client —
 * reached clients with `mentions: []` while its text linked the handle. A
 * client links handles from that list and prefills a reply from it: a reply
 * from Tusky or Ivory to such a post addressed nobody.
 */
class StatusMentionsTest extends ApiTestCase {
	public function testAPublishedStatusListsTheAccountItMentions(): void {
		$bob = $this->account('bob');

		$status = $this->publish('alice', ['status' => 'hello @' . self::PREFIX . 'bob']);

		$this->assertCount(1, $status->mentions);
		$this->assertSame((string)$bob->getNid(), $status->mentions[0]->id);
		$this->assertSame(self::PREFIX . 'bob', $status->mentions[0]->username);
		$this->assertSame(self::PREFIX . 'bob', $status->mentions[0]->acct, 'a local account is named without a domain, as in Account.acct');
		$this->assertSame($bob->getId(), $status->mentions[0]->url);

		$read = $this->call(StatusApiController::class, 'statusGet', ['nid' => $status->id], [], 'carol');
		$this->assertEquals($status->mentions, $read->body->mentions, 'and it is stored, not only answered');
	}

	/**
	 * An edit is a fresh parse of the text, and `mentions` follows it: a name
	 * written in is listed, a name taken out is not — Mastodon keeps the
	 * second only as a silent mention, which the API does not list.
	 */
	public function testAnEditThatChangesWhoIsNamedChangesTheMentions(): void {
		$bob = $this->account('bob');
		$carol = $this->account('carol');
		$status = $this->publish('alice', ['status' => 'hello @' . self::PREFIX . 'bob']);

		$edited = $this->call(StatusApiController::class, 'statusUpdate', ['nid' => $status->id], [
			'status' => 'hello @' . self::PREFIX . 'carol',
		], 'alice', 'PUT');
		$this->assertSame(200, $edited->status, json_encode($edited->body) ?: '');

		$read = $this->call(StatusApiController::class, 'statusGet', ['nid' => $status->id], [], 'alice');
		$this->assertSame(
			[(string)$carol->getNid()],
			array_map(static fn (\stdClass $mention): string => $mention->id, $read->body->mentions),
			'the name written in is listed and the one taken out is not'
		);
	}

	public function testAnEditThatAddsANameKeepsTheOnesStillWritten(): void {
		$bob = $this->account('bob');
		$carol = $this->account('carol');
		$status = $this->publish('alice', ['status' => 'hello @' . self::PREFIX . 'bob']);

		$this->call(StatusApiController::class, 'statusUpdate', ['nid' => $status->id], [
			'status' => 'hello @' . self::PREFIX . 'bob and @' . self::PREFIX . 'carol',
		], 'alice', 'PUT');

		$read = $this->call(StatusApiController::class, 'statusGet', ['nid' => $status->id], [], 'alice');
		$ids = array_map(static fn (\stdClass $mention): string => $mention->id, $read->body->mentions);
		sort($ids);
		$expected = [(string)$bob->getNid(), (string)$carol->getNid()];
		sort($expected);
		$this->assertSame($expected, $ids);
	}

	public function testAStatusThatMentionsNobodyListsNobody(): void {
		$status = $this->publish('alice', ['status' => 'nobody named here, not even @nobody-at-all']);

		$this->assertSame([], $status->mentions);
	}
}
