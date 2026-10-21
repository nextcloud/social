<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\NotificationPoller;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\BackgroundJob\IJobList;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 of docs/Atproto-Compatibility.md against Bluesky's own software:
 * a local account follows a user on the dev PDS and reads him in her home
 * feed off the AppView; her like reaches the AppView as a record of her
 * repository; his like and his reply to her post reach her Activities and
 * her thread through the AppView's notifications.
 */
class AtprotoReadingTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('rd');
	}

	public function testALocalAccountFollowsReadsLikesAndIsAnsweredFromBluesky(): void {
		$bobDid = $this->network->createUser('reader' . bin2hex(random_bytes(3)));
		$bobHandle = $this->network->userHandle();
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);

		// resolving a handle with no `@` makes a cached actor out of the
		// AppView's profile; following it is accepted at once and watched
		$bobId = $this->network->await(fn () => $this->tryResolve($bobHandle));
		$this->assertNotNull($bobId, 'the handle resolved');
		$this->assertSame('https://bsky.app/profile/' . $bobDid, Server::get(CacheActorService::class)->getFromAccount($bobHandle, false)->getId(), 'the cached actor is the bsky.app profile by DID');
		$this->alice->follow($bobId);
		$this->assertTrue($this->alice->relationship($bobId)['following']);
		$account = $this->alice->account($bobId);
		$this->assertSame($bobHandle, $account['acct'], 'the bare handle is the account');
		$this->assertTrue($account['bluesky']['native']);

		// bob posts; the poller reads the AppView's feed into alice's home
		$words = [
			'Read from Bluesky one ' . bin2hex(random_bytes(4)),
			'Read from Bluesky two ' . bin2hex(random_bytes(4)),
			'Read from Bluesky three ' . bin2hex(random_bytes(4)),
		];
		$posts = array_map(fn (string $word): array => $this->network->postText($word . ' #interop'), $words);
		$this->assertNotNull($this->network->await(function () use ($bobDid, $words): ?bool {
			$texts = array_map(static fn (array $item): string => (string)($item['post']['record']['text'] ?? ''), $this->network->authorFeed($bobDid));

			return count(array_intersect(array_map(static fn (string $word): string => $word . ' #interop', $words), $texts)) === count($words) ? true : null;
		}), 'all posts are indexed before the author feed is polled');
		$watch = Server::get(AtprotoWatchRequest::class)->getByDid($bobDid);
		$this->assertNotNull($watch, 'the follow made a watch');
		$home = $this->network->await(function () use ($words, $bobDid): ?array {
			// the watch directly rather than the pass: an empty first read
			// backs it off past this wait
			Server::get(FeedPoller::class)->pollWatch(Server::get(AtprotoWatchRequest::class)->getByDid($bobDid));
			$found = [];
			foreach ($this->alice->get('/api/v1/timelines/home', ['limit' => '40']) as $status) {
				if (!is_array($status)) {
					continue;
				}
				foreach ($words as $word) {
					if (str_contains((string)($status['content'] ?? ''), $word)) {
						$found[$word] = $status;
					}
				}
			}

			return count($found) === count($words) ? $found : null;
		});
		$this->assertNotNull($home, 'all posts reached the home timeline, not only the newest one');
		$latest = $home[$words[2]];
		$this->assertSame('https://bsky.app/profile/' . $bobDid . '/post/' . basename($posts[2]['uri']), $latest['uri']);
		$this->assertSame('https://bsky.app/profile/' . $bobHandle . '/post/' . basename($posts[2]['uri']), $latest['url']);
		$this->assertSame($bobHandle, $latest['account']['acct']);
		$this->assertStringContainsString('class="mention hashtag"', (string)$latest['content'], 'the hashtag facet is a tag link');

		// alice likes it: a record in her repository, counted by the AppView
		$this->alice->favourite((string)$latest['id']);
		$this->runQueuedJobs();
		$likers = $this->network->await(fn () => in_array($identity->did, $this->network->likers($posts[2]['uri']), true) ? [$identity->did] : null);
		$this->assertNotNull($likers, 'the like reached the AppView');

		// bob likes and answers alice's post; the notifications poll brings
		// both here, the reply under her post
		$mine = $this->alice->postStatus('Answer me from Bluesky ' . bin2hex(random_bytes(4)));
		Server::get(Publisher::class)->reconcile();
		$mineOnBluesky = $this->network->await(function () use ($identity, $mine): ?array {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (($item['post']['uri'] ?? '') !== '' && str_contains((string)($item['post']['record']['text'] ?? ''), 'Answer me from Bluesky')) {
					return $item['post'];
				}
			}

			return null;
		});
		$this->assertNotNull($mineOnBluesky);
		$this->network->like($mineOnBluesky['uri'], $mineOnBluesky['cid']);
		$replyWords = 'Answered from Bluesky ' . bin2hex(random_bytes(4));
		$this->network->postText($replyWords, ['parent' => ['uri' => $mineOnBluesky['uri'], 'cid' => $mineOnBluesky['cid']]]);

		Server::get(NotificationPoller::class)->poll();
		$seen = $this->network->await(function () use ($bobHandle, $identity): ?array {
			$cursor = Server::get(AtprotoWatchRequest::class)->getByDid($identity->did, CoreRequestBuilder::TABLE_ATPROTO_NOTIFY_CURSOR);
			if ($cursor !== null) {
				Server::get(NotificationPoller::class)->pollAccount($cursor);
			}
			$types = [];
			foreach ($this->alice->notifications() as $notification) {
				if (($notification['account']['acct'] ?? '') === $bobHandle) {
					$types[] = (string)$notification['type'];
				}
			}

			return in_array('favourite', $types, true) && in_array('mention', $types, true) ? $types : null;
		});
		$this->assertNotNull($seen, 'the like and the reply reached Activities');

		$context = $this->alice->context((string)$mine['id']);
		$descendants = array_map(static fn (array $s): string => (string)($s['content'] ?? ''), $context['descendants'] ?? []);
		$this->assertNotEmpty(array_filter($descendants, static fn (string $c): bool => str_contains($c, $replyWords)), 'the reply is under her post');
	}

	private function tryResolve(string $handle): ?string {
		try {
			return $this->alice->resolve($handle);
		} catch (\Throwable) {
			return null;
		}
	}

	/** The like and boost records are written by the queued job; here it runs now. */
	private function runQueuedJobs(): void {
		$jobs = Server::get(IJobList::class);
		foreach ($jobs->getJobsIterator(AtprotoPublish::class, 50, 0) as $job) {
			$job->start($jobs);
		}
	}
}
