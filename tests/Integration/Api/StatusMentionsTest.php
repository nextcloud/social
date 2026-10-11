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

	public function testAStatusThatMentionsNobodyListsNobody(): void {
		$status = $this->publish('alice', ['status' => 'nobody named here, not even @nobody-at-all']);

		$this->assertSame([], $status->mentions);
	}
}
