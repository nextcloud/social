<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\PlaceController;
use OCA\Social\Controller\StatusApiController;

/**
 * A post made with a `place_id` the search route handed out keeps that
 * place: in the answer to `POST /api/v1/statuses` and when it is read back
 * with `GET /api/v1/statuses/{id}`. The place is one known by name only,
 * whose `lat` and `long` the search answers empty, as in issue #2596; a
 * client shows its place picker only once a post comes back with a place.
 */
class PlaceKeptTest extends ApiTestCase {
	public function testAPostMadeWithAPlaceIdFromTheSearchKeepsItsPlace(): void {
		$name = 'Apictr Berlin ' . bin2hex(random_bytes(4));
		// a place becomes searchable once a post has named it
		$this->publish('bob', ['status' => 'named here first', 'place_name' => $name, 'place_country' => 'DE']);

		$search = $this->call(PlaceController::class, 'search', [], ['q' => $name], 'alice');
		$this->assertSame(200, $search->status);
		$this->assertCount(1, $search->body);
		$place = $search->body[0];
		$this->assertSame($name, $place->name);
		$this->assertSame('DE', $place->country);
		$this->assertSame('', $place->lat, 'a place known without coordinates');
		$this->assertSame('', $place->long);

		$post = $this->publish('alice', ['status' => 'taken here', 'visibility' => 'private', 'place_id' => $place->id]);
		$this->assertNotNull($post->place, 'the answer to the post carries the place');
		$this->assertSame($place->id, $post->place->id);
		$this->assertSame($name, $post->place->name);

		$read = $this->call(StatusApiController::class, 'statusGet', ['nid' => $post->id], [], 'alice');
		$this->assertSame(200, $read->status);
		$this->assertNotNull($read->body->place, 'the post read back carries the place');
		$this->assertSame($place->id, $read->body->place->id);
		$this->assertSame($name, $read->body->place->name);
		$this->assertSame('DE', $read->body->place->country);
	}
}
