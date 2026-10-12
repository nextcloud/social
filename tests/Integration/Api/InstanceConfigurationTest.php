<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\DiscoveryController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\StatusApiController;

/**
 * `GET /api/v2/instance` tells a client the limits to hold a post to before
 * it sends it: a composer counts characters with them, offers poll options
 * up to them. Each limit is read here from the answer, as a client reads it,
 * and then tried against what the server enforces — a server stricter than
 * it says refuses posts the client showed as fitting, and one laxer than it
 * says is not something a client can find out.
 */
class InstanceConfigurationTest extends ApiTestCase {
	private function configuration(): \stdClass {
		$instance = $this->call(InstanceApiController::class, 'instanceV2', [], [], null);
		$this->assertSame(200, $instance->status);

		return $instance->body->configuration;
	}

	private function post(array $params): ApiResponse {
		return $this->call(StatusApiController::class, 'statusNew', [], $params, 'alice', 'POST');
	}

	public function testAPostIsHeldToMaxCharactersCountedTheWayTheInstanceSays(): void {
		$statuses = $this->configuration()->statuses;
		$max = $statuses->max_characters;
		$url = 'https://example.org/' . str_repeat('p', 60);

		$this->assertSame(200, $this->post(['status' => str_repeat('a', $max)])->status, 'max_characters fits');
		$this->assertSame(422, $this->post(['status' => str_repeat('a', $max + 1)])->status, 'one more does not');
		$this->assertSame(422, $this->post(['status' => str_repeat('a', $max - 1), 'spoiler_text' => 'cw'])->status, 'the warning counts too');

		// what a client counts: the URL as characters_reserved_per_url, however long it is
		$withUrl = str_repeat('a', $max - $statuses->characters_reserved_per_url - 1) . ' ' . $url;
		$this->assertSame(200, $this->post(['status' => $withUrl])->status, 'a URL counts as characters_reserved_per_url');
		$this->assertSame(422, $this->post(['status' => 'a' . $withUrl])->status, 'and one character more does not fit');
	}

	/**
	 * The post of issue #2594, as written: 4950 characters, a space and a
	 * link of 80 characters. By the advertised count it is 4974 of 5000, and
	 * the server takes it, where counting the link as written made it 5031
	 * and refused it.
	 */
	public function testAPostWithALongLinkThatFitsByTheAdvertisedCountIsTaken(): void {
		$statuses = $this->configuration()->statuses;
		$this->assertSame(5000, $statuses->max_characters);
		$this->assertSame(23, $statuses->characters_reserved_per_url);

		$status = str_repeat('x', 4950) . ' https://example.com/' . str_repeat('a', 60);
		$post = $this->post(['status' => $status]);

		$this->assertSame(200, $post->status, json_encode($post->body) ?: '');
		$this->assertStringContainsString('https://example.com/' . str_repeat('a', 60), $post->body->content, 'the link is kept whole');
	}

	public function testAPollMayHaveMaxOptionsAndEndNoSoonerThanMinExpiration(): void {
		$polls = $this->configuration()->polls;
		$options = array_map(static fn (int $i): string => str_repeat((string)$i, $polls->max_characters_per_option), range(1, $polls->max_options));

		$poll = $this->post(['status' => 'pick one', 'poll' => ['options' => $options, 'expires_in' => 1]]);

		$this->assertSame(200, $poll->status, json_encode($poll->body) ?: '');
		$this->assertCount($polls->max_options, $poll->body->poll->options, 'max_options options are all kept');
		$this->assertSame($options[0], $poll->body->poll->options[0]->title, 'an option of max_characters_per_option is kept whole');
		$lasts = strtotime($poll->body->poll->expires_at) - strtotime($poll->body->created_at);
		$this->assertGreaterThanOrEqual($polls->min_expiration, $lasts, 'a poll lasts at least min_expiration');
	}

	/**
	 * Past the limits a poll is refused, as Mastodon refuses it, rather than
	 * published with the options that fit: a fifth answer dropped, or an
	 * answer cut short, is a different question from the one that was asked.
	 */
	public function testAPollPastMaxOptionsOrMaxCharactersPerOptionIsRefused(): void {
		$polls = $this->configuration()->polls;
		$options = array_map(static fn (int $i): string => 'option ' . $i, range(1, $polls->max_options));

		$tooMany = $this->post(['status' => 'pick one', 'poll' => ['options' => [...$options, 'one more'], 'expires_in' => 3600]]);
		$this->assertSame(422, $tooMany->status, 'one option more than max_options');

		$tooLong = $this->post(['status' => 'pick one', 'poll' => [
			'options' => ['short', str_repeat('x', $polls->max_characters_per_option + 1)], 'expires_in' => 3600,
		]]);
		$this->assertSame(422, $tooLong->status, 'an option one character longer than max_characters_per_option');

		$tooFew = $this->post(['status' => 'pick one', 'poll' => ['options' => ['alone'], 'expires_in' => 3600]]);
		$this->assertSame(422, $tooFew->status, 'a single option');
	}

	public function testAnAccountMayFeatureMaxFeaturedTags(): void {
		$max = $this->configuration()->accounts->max_featured_tags;

		for ($i = 1; $i <= $max; $i++) {
			$this->publish('alice', ['status' => '#apictrfeat' . $i]);
			$featured = $this->call(DiscoveryController::class, 'featureTag', [], ['name' => 'apictrfeat' . $i], 'alice', 'POST');
			$this->assertSame(200, $featured->status, 'tag ' . $i . ': ' . (json_encode($featured->body) ?: ''));
		}

		$this->publish('alice', ['status' => '#apictrfeatmore']);
		$over = $this->call(DiscoveryController::class, 'featureTag', [], ['name' => 'apictrfeatmore'], 'alice', 'POST');
		$this->assertSame(422, $over->status, 'one more than max_featured_tags is refused');
	}
}
