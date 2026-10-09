<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\AdminApiController;
use OCA\Social\Controller\AnnouncementController;
use OCA\Social\Controller\ApiController;
use OCA\Social\Controller\ConversationController;
use OCA\Social\Controller\DiscoveryController;
use OCA\Social\Controller\FilterController;
use OCA\Social\Controller\HistoryController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\MediaApiController;
use OCA\Social\Controller\NotificationController;
use OCA\Social\Controller\OAuthController;
use OCA\Social\Controller\ScheduledStatusApiController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Controller\TagController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\AnnouncementService;
use OCA\Social\Service\HashtagService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

/**
 * Every Mastodon entity this app serves, as the API actually answers it, held
 * against a schema of its documented *types* (tests/Api/Schema/mastodon).
 *
 * `ApiContractTest` pins which keys exist. This pins what is in them: ids are
 * strings, dates are `…T…​.000Z`, counts are integers, a nullable key is sent
 * as `null` rather than left out, an enum holds one of its values, and every
 * nested entity — the account on a status, the status inside a reblog, the
 * filter inside a FilterResult — is checked the same way. A client written in
 * Swift or Kotlin decodes into fixed types, and one wrong type there loses the
 * entity and usually the whole page it arrived on, with nothing on the server
 * side to say so.
 *
 * Everything is produced through the routes a client calls, from data written
 * through the same routes: three local accounts that follow, post, reply,
 * boost, favourite, vote, filter and so on.
 */
class MastodonEntitySchemaTest extends ApiTestCase {
	/** @var int[] announcements this test made, which belong to the instance and not to an account */
	private array $announcements = [];

	protected function tearDown(): void {
		foreach ($this->announcements as $id) {
			Server::get(AnnouncementService::class)->delete($id);
		}
		$this->announcements = [];
		parent::tearDown();
	}

	/** A public post by alice with a hashtag, a mention of bob, a link and a language. */
	private function aliceSaysHello(): \stdClass {
		return $this->publish('alice', [
			'status' => 'Hello #apictrtag @' . self::PREFIX . 'bob, see https://example.org/page',
			'visibility' => 'public',
			'language' => 'en',
		]);
	}

	public function testAPublishedStatusIsAStatus(): void {
		$this->account('bob');

		$this->assertEntity('Status', $this->aliceSaysHello(), 'POST /api/v1/statuses');
		$this->assertEntity('Status', $this->publish('alice', [
			'status' => 'behind a warning',
			'spoiler_text' => 'cw',
			'sensitive' => 'true',
			'visibility' => 'unlisted',
		]), 'with a content warning');
		$this->assertEntity('Status', $this->publish('alice', [
			'status' => 'for followers', 'visibility' => 'private',
		]), 'followers only');
	}

	public function testAStatusReadBackIsAStatus(): void {
		$this->account('bob');
		$hello = $this->aliceSaysHello();

		$response = $this->call(StatusApiController::class, 'statusGet', ['nid' => $hello->id], [], 'bob');

		$this->assertSame(200, $response->status);
		$this->assertEntity('Status', $response->body, 'GET /api/v1/statuses/{id}');
	}

	public function testAReplyAndItsThreadAreAStatusAndAContext(): void {
		$hello = $this->aliceSaysHello();
		$reply = $this->publish('bob', ['status' => '@' . self::PREFIX . 'alice hello back', 'in_reply_to_id' => $hello->id]);

		$this->assertEntity('Status', $reply);
		$this->assertSame($hello->id, $reply->in_reply_to_id);
		$this->assertSame((string)$this->account('alice')->getNid(), $reply->in_reply_to_account_id);

		$context = $this->call(StatusApiController::class, 'statusContext', ['nid' => $hello->id], [], 'carol');
		$this->assertEntity('Context', $context->body, 'GET /api/v1/statuses/{id}/context');
		$this->assertCount(1, $context->body->descendants);

		$ancestors = $this->call(StatusApiController::class, 'statusContext', ['nid' => $reply->id], [], 'carol');
		$this->assertEntity('Context', $ancestors->body);
		$this->assertCount(1, $ancestors->body->ancestors);
	}

	/**
	 * A boost reaches a follower's home as a status of its own whose `reblog`
	 * is the original: the nested status is checked like any other.
	 */
	public function testABoostOnHomeCarriesTheOriginalAsAStatus(): void {
		$hello = $this->aliceSaysHello();
		$this->follow('carol', 'bob');
		$boost = $this->act('bob', $hello->id, 'reblog');
		$this->assertEntity('Status', $boost, 'the answer to a reblog');

		$home = $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'home'], [], 'carol');

		$this->assertEntities('Status', $home->body, 'carol\'s home');
		$reblogs = array_values(array_filter($home->body, static fn (\stdClass $status): bool => $status->reblog !== null));
		$this->assertCount(1, $reblogs, 'the boost is on the home of somebody who follows the booster');
		$this->assertSame($hello->id, $reblogs[0]->reblog->id);
		$this->assertNull($reblogs[0]->reblog->reblog, 'a reblog never nests a second one');
	}

	public function testAPollAndItsStatusAreTyped(): void {
		$poll = $this->publish('alice', [
			'status' => 'cats or dogs?',
			'poll' => ['options' => ['cats', 'dogs'], 'expires_in' => 3600, 'multiple' => 'false'],
		]);
		$this->assertEntity('Status', $poll, 'a status with a poll');
		$this->assertNotNull($poll->poll);

		$vote = $this->call(StatusApiController::class, 'pollVote', ['nid' => $poll->id], ['choices' => ['1']], 'bob', 'POST');
		$this->assertSame(200, $vote->status, json_encode($vote->body) ?: '');
		$this->assertEntity('Poll', $vote->body, 'POST /api/v1/polls/{id}/votes');
		$this->assertSame([1], $vote->body->own_votes);

		$read = $this->call(StatusApiController::class, 'pollGet', ['nid' => $poll->id], [], 'bob');
		$this->assertSame(200, $read->status, json_encode($read->body) ?: '');
		$this->assertEntity('Poll', $read->body, 'GET /api/v1/polls/{id}');
		$this->assertTrue($read->body->voted);
		$this->assertSame(1, $read->body->votes_count);
	}

	/** Every timeline a client reads is a list of statuses. */
	public function testEveryTimelineIsAListOfStatuses(): void {
		$hello = $this->aliceSaysHello();
		$this->follow('bob', 'alice');
		$this->act('bob', $hello->id, 'favourite');
		$this->act('bob', $hello->id, 'bookmark');

		$routes = [
			'home' => [TimelineApiController::class, 'timelines', ['timeline' => 'home']],
			'public' => [TimelineApiController::class, 'timelines', ['timeline' => 'public']],
			'hashtag' => [TimelineApiController::class, 'tag', ['hashtag' => 'apictrtag']],
			'favourites' => [TimelineApiController::class, 'favourites', []],
			'bookmarks' => [TimelineApiController::class, 'bookmarks', []],
			'account statuses' => [AccountApiController::class, 'accountStatuses', ['account' => (string)$this->account('alice')->getNid()]],
		];
		foreach ($routes as $name => [$controller, $method, $route]) {
			$response = $this->call($controller, $method, $route, [], 'bob');
			$this->assertSame(200, $response->status, $name);
			$this->assertNotSame([], $response->body, $name . ' holds the post');
			$this->assertEntities('Status', $response->body, $name);
		}
	}

	public function testAnUploadIsAMediaAttachmentAndSoIsItsUseOnAStatus(): void {
		$file = tempnam(sys_get_temp_dir(), 'apictr');
		$image = imagecreatetruecolor(64, 48);
		imagefilledrectangle($image, 0, 0, 31, 47, (int)imagecolorallocate($image, 200, 40, 40));
		imagepng($image, $file);

		$upload = $this->call(MediaApiController::class, 'mediaNew', [], [
			'description' => 'a red square on black',
			'focus' => '0.5,-0.25',
		], 'alice', 'POST', '/apps/social/api/v1/media', [
			'file' => ['name' => 'square.png', 'type' => 'image/png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)],
		]);
		@unlink($file);

		$this->assertSame(200, $upload->status, json_encode($upload->body) ?: '');
		$this->assertEntity('MediaAttachment', $upload->body, 'POST /api/v1/media');
		$this->assertSame('image', $upload->body->type);
		$this->assertSame(0.5, $upload->body->meta->focus->x);
		$this->assertSame(-0.25, $upload->body->meta->focus->y);
		$this->assertSame(64, $upload->body->meta->original->width);

		$read = $this->call(MediaApiController::class, 'mediaGet', ['nid' => $upload->body->id], [], 'alice');
		$this->assertEntity('MediaAttachment', $read->body, 'GET /api/v1/media/{id}');

		$status = $this->publish('alice', ['status' => 'a picture', 'media_ids' => [$upload->body->id]]);
		$this->assertEntity('Status', $status, 'a status with an attachment');
		$this->assertCount(1, $status->media_attachments);
	}

	public function testTheAccountRoutesAnswerAccounts(): void {
		$alice = $this->account('alice');
		$this->aliceSaysHello();
		$this->follow('bob', 'alice');
		$this->follow('carol', 'alice');
		$this->follow('bob', 'carol');

		$credentials = $this->call(AccountApiController::class, 'verifyCredentials', [], [], 'alice');
		$this->assertEntity('CredentialAccount', $credentials->body, 'GET /api/v1/accounts/verify_credentials');

		$account = $this->call(AccountApiController::class, 'accountGet', ['id' => (string)$alice->getNid()], [], 'bob');
		$this->assertEntity('Account', $account->body, 'GET /api/v1/accounts/{id}');
		$this->assertObjectNotHasProperty('source', $account->body, 'source is the account\'s own business');

		$lookup = $this->call(AccountApiController::class, 'accountLookup', [], ['acct' => self::PREFIX . 'alice'], 'bob');
		$this->assertEntity('Account', $lookup->body, 'GET /api/v1/accounts/lookup');

		$followers = $this->call(AccountApiController::class, 'accountFollowers', ['account' => (string)$alice->getNid()], [], 'bob');
		$this->assertCount(2, $followers->body);
		$this->assertEntities('Account', $followers->body, 'followers');

		$following = $this->call(AccountApiController::class, 'accountFollowing', ['account' => (string)$this->account('bob')->getNid()], [], 'bob');
		$this->assertCount(2, $following->body);
		$this->assertEntities('Account', $following->body, 'following');

		$search = $this->call(AccountApiController::class, 'accountsSearch', [], ['q' => self::PREFIX], 'bob');
		$this->assertEntities('Account', $search->body, 'GET /api/v1/accounts/search');
	}

	public function testRelationshipsAndFamiliarFollowersAreTyped(): void {
		$this->follow('bob', 'alice');
		$this->follow('bob', 'carol');
		$this->follow('carol', 'alice');

		$followed = $this->call(AccountApiController::class, 'accountFollow', ['id' => (string)$this->account('carol')->getNid()], [], 'alice', 'POST');
		$this->assertEntity('Relationship', $followed->body, 'the answer to a follow');

		$relationships = $this->call(AccountApiController::class, 'relationships', [], [
			'id' => [(string)$this->account('alice')->getNid(), (string)$this->account('carol')->getNid()],
		], 'bob');
		$this->assertCount(2, $relationships->body);
		$this->assertEntities('Relationship', $relationships->body, 'GET /api/v1/accounts/relationships');

		$familiar = $this->call(AccountApiController::class, 'familiarFollowers', [], [
			'id' => [(string)$this->account('alice')->getNid()],
		], 'bob');
		$this->assertEntities('FamiliarFollowers', $familiar->body, 'GET /api/v1/accounts/familiar_followers');
		$this->assertCount(1, $familiar->body[0]->accounts, 'carol follows alice and is followed by bob');

		$this->assertEntity('Relationship', $this->call(AccountApiController::class, 'accountMute', ['id' => (string)$this->account('carol')->getNid()], [], 'alice', 'POST')->body);
		$this->assertEntity('Relationship', $this->call(AccountApiController::class, 'accountBlock', ['id' => (string)$this->account('bob')->getNid()], [], 'alice', 'POST')->body);
		$this->assertEntities('Account', $this->call(AccountApiController::class, 'mutes', [], [], 'alice')->body, 'GET /api/v1/mutes');
		$this->assertEntities('Account', $this->call(AccountApiController::class, 'blocks', [], [], 'alice')->body, 'GET /api/v1/blocks');
	}

	/**
	 * Every kind of notification the routes produce between two local
	 * accounts, in both of Mastodon's list shapes.
	 */
	public function testNotificationsInBothShapesAreTyped(): void {
		$this->acceptEveryNotification('alice');
		$hello = $this->aliceSaysHello();
		$this->follow('bob', 'alice');
		$this->act('bob', $hello->id, 'favourite');
		$this->act('bob', $hello->id, 'reblog');
		$this->publish('bob', ['status' => '@' . self::PREFIX . 'alice hi', 'in_reply_to_id' => $hello->id]);

		$v1 = $this->call(TimelineApiController::class, 'notifications', [], [], 'alice');
		$this->assertEntities('Notification', $v1->body, 'GET /api/v1/notifications');
		$types = array_map(static fn (\stdClass $notification): string => $notification->type, $v1->body);
		sort($types);
		$this->assertSame(['favourite', 'follow', 'mention', 'reblog'], $types);

		$one = $this->call(NotificationController::class, 'get', ['id' => $v1->body[0]->id], [], 'alice');
		$this->assertEntity('Notification', $one->body, 'GET /api/v1/notifications/{id}');

		$v2 = $this->call(NotificationController::class, 'indexV2', [], [], 'alice');
		$this->assertEntity('GroupedNotificationsResults', $v2->body, 'GET /api/v2/notifications');
		$this->assertNotSame([], $v2->body->notification_groups);

		$group = $this->call(NotificationController::class, 'group', ['group_key' => $v2->body->notification_groups[0]->group_key], [], 'alice');
		$this->assertEntity('GroupedNotificationsResults', $group->body, 'GET /api/v2/notifications/{group_key}');

		$policy = $this->call(NotificationController::class, 'policy', [], [], 'alice');
		$this->assertEntity('NotificationPolicy', $policy->body, 'GET /api/v2/notifications/policy');
	}

	public function testBothInstanceDocumentsAreTyped(): void {
		$v1 = $this->call(InstanceApiController::class, 'instance', [], [], null);
		$this->assertEntity('V1Instance', $v1->body, 'GET /api/v1/instance');

		$v2 = $this->call(InstanceApiController::class, 'instanceV2', [], [], null);
		$this->assertEntity('Instance', $v2->body, 'GET /api/v2/instance');

		$this->assertEntities('Rule', $this->call(InstanceApiController::class, 'instanceRules', [], [], null)->body);
		$this->assertEntities('CustomEmoji', $this->call(InstanceApiController::class, 'customEmojis', [], [], null)->body);
	}

	/**
	 * A filter, its keywords and statuses, and the FilterResult a matching
	 * status then carries on a timeline.
	 */
	public function testFiltersAndTheResultOnAMatchingStatusAreTyped(): void {
		$hello = $this->aliceSaysHello();
		$created = $this->call(FilterController::class, 'create', [], [
			'title' => 'no greetings',
			'context' => ['home', 'public'],
			'filter_action' => 'warn',
			'keywords_attributes' => [['keyword' => 'hello', 'whole_word' => 'true']],
		], 'carol', 'POST');
		$this->assertSame(200, $created->status, json_encode($created->body) ?: '');
		$this->assertEntity('Filter', $created->body, 'POST /api/v2/filters');

		$status = $this->call(FilterController::class, 'addStatus', ['id' => (int)$created->body->id], ['status_id' => $hello->id], 'carol', 'POST');
		$this->assertEntity('FilterStatus', $status->body, 'POST /api/v2/filters/{id}/statuses');

		$this->assertEntities('Filter', $this->call(FilterController::class, 'index', [], [], 'carol')->body, 'GET /api/v2/filters');
		$this->assertEntities('FilterKeyword', $this->call(FilterController::class, 'keywords', ['id' => (int)$created->body->id], [], 'carol')->body);
		$this->assertEntities('FilterStatus', $this->call(FilterController::class, 'statuses', ['id' => (int)$created->body->id], [], 'carol')->body);

		$public = $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'public'], [], 'carol');
		$this->assertEntities('Status', $public->body);
		$matched = array_values(array_filter($public->body, static fn (\stdClass $one): bool => $one->id === $hello->id));
		$this->assertCount(1, $matched);
		$this->assertCount(1, $matched[0]->filtered, 'the warning filter matched');
		$this->assertSame(['Hello'], $matched[0]->filtered[0]->keyword_matches, 'the text the keyword found, as Mastodon reports it');
		$this->assertSame([$hello->id], $matched[0]->filtered[0]->status_matches);
	}

	public function testMarkersAreAnObjectOfMarkers(): void {
		$hello = $this->aliceSaysHello();

		$empty = $this->call(TimelineApiController::class, 'markersGet', [], [], 'alice');
		$this->assertEntity('Markers', $empty->body, 'no marker yet is an empty object, not a list');

		$set = $this->call(TimelineApiController::class, 'markersSet', [], ['home' => ['last_read_id' => $hello->id]], 'alice', 'POST');
		$this->assertEntity('Markers', $set->body, 'POST /api/v1/markers');

		$read = $this->call(TimelineApiController::class, 'markersGet', [], ['timeline' => ['home']], 'alice');
		$this->assertEntity('Markers', $read->body, 'GET /api/v1/markers');
		$this->assertSame($hello->id, $read->body->home->last_read_id);
	}

	public function testAnnouncementsAreTyped(): void {
		$announcement = Server::get(AnnouncementService::class)->create(
			'<p>maintenance tonight</p>', gmdate('Y-m-d\TH:i:s\Z', time() - 60), gmdate('Y-m-d\TH:i:s\Z', time() + 3600)
		);
		$this->announcements[] = $announcement->getId();
		$react = $this->call(AnnouncementController::class, 'react', ['id' => $announcement->getId(), 'name' => '👍'], [], 'alice', 'PUT');
		$this->assertSame(200, $react->status, json_encode($react->body) ?: '');

		$list = $this->call(AnnouncementController::class, 'index', [], [], 'alice');
		$this->assertEntities('Announcement', $list->body, 'GET /api/v1/announcements');
		$mine = array_values(array_filter($list->body, static fn (\stdClass $one): bool => $one->id === (string)$announcement->getId()));
		$this->assertCount(1, $mine);
		$this->assertSame(1, $mine[0]->reactions[0]->count);
	}

	public function testSearchIsTyped(): void {
		$this->aliceSaysHello();

		$search = $this->call(ApiController::class, 'searchV2', [], ['q' => 'apictrtag'], 'bob');
		$this->assertEntity('Search', $search->body, 'GET /api/v2/search, hashtags');
		$this->assertNotSame([], $search->body->statuses);

		$accounts = $this->call(ApiController::class, 'searchV2', [], ['q' => self::PREFIX . 'alice', 'type' => 'accounts'], 'bob');
		$this->assertEntity('Search', $accounts->body, 'GET /api/v2/search, accounts');
		$this->assertNotSame([], $accounts->body->accounts);
	}

	public function testAnEditedStatusHasATypedHistoryAndSource(): void {
		$this->account('bob');
		$hello = $this->aliceSaysHello();
		$edited = $this->call(StatusApiController::class, 'statusUpdate', ['nid' => $hello->id], [
			'status' => 'Hello again #apictrtag', 'spoiler_text' => 'edited',
		], 'alice', 'PUT');
		$this->assertSame(200, $edited->status, json_encode($edited->body) ?: '');
		$this->assertEntity('Status', $edited->body, 'PUT /api/v1/statuses/{id}');
		$this->assertNotNull($edited->body->edited_at);

		$history = $this->call(HistoryController::class, 'history', ['nid' => $hello->id], [], 'bob');
		$this->assertCount(2, $history->body);
		$this->assertEntities('StatusEdit', $history->body, 'GET /api/v1/statuses/{id}/history');

		$source = $this->call(StatusApiController::class, 'statusSource', ['nid' => $hello->id], [], 'alice');
		$this->assertEntity('StatusSource', $source->body, 'GET /api/v1/statuses/{id}/source');
		$this->assertSame('Hello again #apictrtag', $source->body->text);
	}

	public function testListsConversationsAndScheduledStatusesAreTyped(): void {
		$this->follow('alice', 'bob');
		$list = $this->call(ListController::class, 'create', [], ['title' => 'friends', 'replies_policy' => 'followed'], 'alice', 'POST');
		$this->assertEntity('List', $list->body, 'POST /api/v1/lists');
		$this->assertEntities('List', $this->call(ListController::class, 'index', [], [], 'alice')->body, 'GET /api/v1/lists');
		$this->call(ListController::class, 'addAccounts', ['id' => (int)$list->body->id], ['account_ids' => [(string)$this->account('bob')->getNid()]], 'alice', 'POST');
		$this->assertEntities('Account', $this->call(ListController::class, 'accounts', ['id' => (int)$list->body->id], [], 'alice')->body);

		$this->publish('bob', ['status' => '@' . self::PREFIX . 'alice just us', 'visibility' => 'direct']);
		$conversations = $this->call(ConversationController::class, 'index', [], [], 'alice');
		$this->assertCount(1, $conversations->body);
		$this->assertEntities('Conversation', $conversations->body, 'GET /api/v1/conversations');

		$scheduled = $this->publish('alice', [
			'status' => 'later', 'scheduled_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
		]);
		$this->assertEntity('ScheduledStatus', $scheduled, 'POST /api/v1/statuses with scheduled_at');
		$this->assertEntities('ScheduledStatus', $this->call(ScheduledStatusApiController::class, 'scheduledStatuses', [], [], 'alice')->body);
		$this->assertEntity('ScheduledStatus', $this->call(ScheduledStatusApiController::class, 'scheduledStatusGet', ['id' => (int)$scheduled->id], [], 'alice')->body);
	}

	public function testTagsFeaturedTagsAndPreferencesAreTyped(): void {
		$this->aliceSaysHello();
		// what the cron does every few minutes: count the uses a Tag's history reports
		Server::get(HashtagService::class)->manageHashtags();

		$tag = $this->call(TagController::class, 'get', ['hashtag' => 'apictrtag'], [], 'alice');
		$this->assertEntity('Tag', $tag->body, 'GET /api/v1/tags/{name}');
		$this->assertNotSame([], $tag->body->history, 'a counted hashtag has a history bucket');
		$this->assertEntity('Tag', $this->call(TagController::class, 'follow', ['hashtag' => 'apictrtag'], [], 'alice', 'POST')->body, 'POST /api/v1/tags/{name}/follow');
		$this->assertEntities('Tag', $this->call(TagController::class, 'followedTags', [], [], 'alice')->body, 'GET /api/v1/followed_tags');

		$featured = $this->call(DiscoveryController::class, 'featureTag', [], ['name' => 'apictrtag'], 'alice', 'POST');
		$this->assertEntity('FeaturedTag', $featured->body, 'POST /api/v1/featured_tags');
		$this->assertEntities('FeaturedTag', $this->call(DiscoveryController::class, 'featuredTags', [], [], 'alice')->body);
		$this->assertEntities('FeaturedTag', $this->call(DiscoveryController::class, 'accountFeaturedTags', ['account' => (string)$this->account('alice')->getNid()], [], 'bob')->body);

		$this->assertEntity('Preferences', $this->call(AccountApiController::class, 'preferences', [], [], 'alice')->body);
	}

	public function testAReportIsTyped(): void {
		$hello = $this->aliceSaysHello();

		$report = $this->call(ApiController::class, 'reportNew', [], [
			'account_id' => (string)$this->account('alice')->getNid(),
			'status_ids' => [$hello->id],
			'comment' => 'not nice',
			'category' => 'other',
		], 'bob', 'POST');

		$this->assertSame(200, $report->status, json_encode($report->body) ?: '');
		$this->assertEntity('Report', $report->body, 'POST /api/v1/reports');
	}

	public function testSuggestionsAreTyped(): void {
		$this->follow('bob', 'alice');
		$this->follow('alice', 'carol');

		$suggestions = $this->call(DiscoveryController::class, 'suggestions', [], [], 'bob');
		$this->assertSame(200, $suggestions->status);
		$this->assertNotSame([], $suggestions->body, 'carol, followed by somebody bob follows');
		$this->assertEntities('Suggestion', $suggestions->body, 'GET /api/v2/suggestions');
	}

	/**
	 * Registering an app, and the token it is given once somebody has said
	 * yes on the consent page: the two entities a client reads before it has
	 * read anything else, and which it cannot sign in without decoding.
	 */
	public function testApplicationsAndTokensAreTyped(): void {
		$this->account('alice');
		$registered = $this->call(OAuthController::class, 'apps', [], [
			'client_name' => 'apictr-registered',
			'redirect_uris' => 'urn:ietf:wg:oauth:2.0:oob',
			'scopes' => 'read write',
			'website' => 'https://client.example',
		], null, 'POST');
		$this->assertSame(200, $registered->status, json_encode($registered->body) ?: '');
		$clientId = (string)$registered->body->client_id;

		try {
			$this->assertEntity('Application', $registered->body, 'POST /api/v1/apps');
			$this->assertObjectHasProperty('client_secret', $registered->body);
			$this->assertSame(['read', 'write'], $registered->body->scopes);

			// what the consent page records when alice says yes
			$code = 'apictr-code-' . bin2hex(random_bytes(6));
			Server::get(ClientAuthRequest::class)->authorize(
				Server::get(ClientRequest::class)->getFromClientId($clientId)->getId(),
				self::PREFIX . 'alice', self::PREFIX . 'alice', ['read', 'write'], $code,
				redirectUri: 'urn:ietf:wg:oauth:2.0:oob'
			);

			$token = $this->call(OAuthController::class, 'token', [], [
				'grant_type' => 'authorization_code',
				'client_id' => $clientId,
				'client_secret' => (string)$registered->body->client_secret,
				'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
				'code' => $code,
			], null, 'POST');
			$this->assertSame(200, $token->status, json_encode($token->body) ?: '');
			$this->assertEntity('Token', $token->body, 'POST /oauth/token');
			$this->assertSame('read write', $token->body->scope);
		} finally {
			Server::get(ClientRequest::class)->deleteApp($clientId);
		}

		$verified = $this->call(ApiController::class, 'appsCredentials', [], [], 'alice');
		$this->assertEntity('Application', $verified->body, 'GET /api/v1/apps/verify_credentials');
	}

	/**
	 * The link preview a post gets once the queue has fetched the page —
	 * stored as the queue stores it, since a test cannot fetch a page.
	 */
	public function testALinkPreviewIsAPreviewCard(): void {
		$this->account('bob');
		$hello = $this->aliceSaysHello();
		$card = new StreamCard($hello->uri, 'https://example.org/page');
		$card->setTitle('A page')
			->setDescription('What the page says about itself')
			->setImage('https://example.org/page.png')
			->setProviderName('Example');
		$cards = Server::get(StreamCardsRequest::class);
		$cards->save($card);

		try {
			$status = $this->call(StatusApiController::class, 'statusGet', ['nid' => $hello->id], [], 'bob');
			$this->assertEntity('Status', $status->body);
			$this->assertNotNull($status->body->card);
			$this->assertEntity('PreviewCard', $status->body->card, 'the card on a status');

			$alone = $this->call(StatusApiController::class, 'statusCard', ['nid' => $hello->id], [], 'bob');
			$this->assertEntity('PreviewCard', $alone->body, 'GET /api/v1/statuses/{id}/card');
		} finally {
			$cards->deleteByStreamId($hello->uri);
		}
	}

	/**
	 * A quote of a post here, which this instance approves at once: the
	 * quoting status carries the quoted one, checked like any status.
	 */
	public function testAQuoteCarriesTheQuotedStatus(): void {
		$hello = $this->aliceSaysHello();

		$quote = $this->publish('bob', ['status' => 'look at this', 'quote_id' => $hello->id]);

		$this->assertEntity('Status', $quote, 'a status that quotes another');
		$this->assertNotNull($quote->quote);
		$this->assertSame('accepted', $quote->quote->state);
		$this->assertSame($hello->id, $quote->quote->quoted_status->id);
	}

	/** Somebody who boosted a post is told when it changes. */
	public function testAnUpdateNotificationIsTyped(): void {
		$this->acceptEveryNotification('bob');
		$hello = $this->aliceSaysHello();
		$this->act('bob', $hello->id, 'reblog');
		$this->call(StatusApiController::class, 'statusUpdate', ['nid' => $hello->id], ['status' => 'Hello, edited'], 'alice', 'PUT');

		$notifications = $this->call(TimelineApiController::class, 'notifications', [], ['types' => ['update']], 'bob');

		$this->assertCount(1, $notifications->body);
		$this->assertEntities('Notification', $notifications->body);
		$this->assertSame('update', $notifications->body[0]->type);
	}

	public function testTheAdminAccountAndReportEntitiesAreTyped(): void {
		$alice = $this->account('alice');
		$this->account('carol');
		$hello = $this->aliceSaysHello();
		$admins = Server::get(IGroupManager::class)->get('admin');
		$this->assertNotNull($admins);
		$user = Server::get(IUserManager::class)->get(self::PREFIX . 'carol');
		$this->assertNotNull($user);
		$admins->addUser($user);

		try {
			$report = $this->call(ApiController::class, 'reportNew', [], [
				'account_id' => (string)$alice->getNid(), 'status_ids' => [$hello->id], 'comment' => 'spam', 'category' => 'spam',
			], 'bob', 'POST');
			$this->assertSame(200, $report->status, json_encode($report->body) ?: '');

			$accounts = $this->call(AdminApiController::class, 'accounts', [], ['username' => self::PREFIX], 'carol');
			$this->assertSame(200, $accounts->status, json_encode($accounts->body) ?: '');
			$this->assertNotSame([], $accounts->body);
			$this->assertEntities('AdminAccount', $accounts->body, 'GET /api/v2/admin/accounts');

			$one = $this->call(AdminApiController::class, 'account', ['id' => (string)$alice->getNid()], [], 'carol');
			$this->assertEntity('AdminAccount', $one->body, 'GET /api/v1/admin/accounts/{id}');

			$reports = $this->call(AdminApiController::class, 'reports', [], [], 'carol');
			$this->assertSame(200, $reports->status, json_encode($reports->body) ?: '');
			$mine = array_values(array_filter($reports->body, static fn (\stdClass $one): bool => $one->id === $report->body->id));
			$this->assertCount(1, $mine);
			$this->assertEntities('AdminReport', $mine, 'GET /api/v1/admin/reports');

			$read = $this->call(AdminApiController::class, 'report', ['id' => (int)$report->body->id], [], 'carol');
			$this->assertEntity('AdminReport', $read->body, 'GET /api/v1/admin/reports/{id}');
		} finally {
			$admins->removeUser($user);
		}
	}
}
