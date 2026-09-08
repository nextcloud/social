<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AppInfo;

return [
	'routes' => [
		['name' => 'Navigation#navigate', 'url' => '/', 'verb' => 'GET'],
		['name' => 'Config#local', 'url' => '/local/', 'verb' => 'GET'],
		['name' => 'Config#remote', 'url' => '/test/{account}/', 'verb' => 'GET'],
		[
			'name' => 'Navigation#timeline', 'url' => '/timeline/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '.+'],
			'defaults' => ['path' => '']
		],
		// the client-side router owns these paths; the server has to answer them
		// too, or reloading or bookmarking one of those pages is a 404
		// 'postfix' keeps the route names unique: routes are keyed by name, so
		// re-using 'Navigation#navigate' without it drops all but the last one
		['name' => 'Navigation#navigate', 'url' => '/follow_requests', 'verb' => 'GET', 'postfix' => 'followrequests'],
		['name' => 'Navigation#navigate', 'url' => '/blocked', 'verb' => 'GET', 'postfix' => 'blocked'],
		['name' => 'Navigation#documentGet', 'url' => '/document/get', 'verb' => 'GET'],
		['name' => 'Navigation#documentGetPublic', 'url' => '/document/public', 'verb' => 'GET'],
		['name' => 'Navigation#resizedGet', 'url' => '/document/get/resized', 'verb' => 'GET'],
		['name' => 'Navigation#resizedGetPublic', 'url' => '/document/public/resized', 'verb' => 'GET'],

		['name' => 'ActivityPub#actor', 'url' => '/users/{username}', 'verb' => 'GET'],
		['name' => 'ActivityPub#actorAlias', 'url' => '/@{username}/', 'verb' => 'GET'],
		['name' => 'ActivityPub#inbox', 'url' => '/@{username}/inbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#getInbox', 'url' => '/@{username}/inbox', 'verb' => 'GET'],
		['name' => 'ActivityPub#sharedInbox', 'url' => '/inbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#outbox', 'url' => '/@{username}/outbox', 'verb' => 'GET'],
		['name' => 'ActivityPub#outbox', 'url' => '/@{username}/outbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#followers', 'url' => '/@{username}/followers', 'verb' => 'GET'],
		['name' => 'ActivityPub#following', 'url' => '/@{username}/following', 'verb' => 'GET'],
		['name' => 'ActivityPub#featured', 'url' => '/@{username}/collections/featured', 'verb' => 'GET'],
		['name' => 'ActivityPub#displayPost', 'url' => '/@{username}/{token}', 'verb' => 'GET'],

		['name' => 'OStatus#subscribe', 'url' => '/ostatus/follow/', 'verb' => 'GET'],
		['name' => 'OStatus#followRemote', 'url' => '/api/v1/ostatus/followRemote/{local}', 'verb' => 'GET'],
		['name' => 'OStatus#getLink', 'url' => '/api/v1/ostatus/link/{local}/{account}', 'verb' => 'GET'],

		['name' => 'OAuth#nodeinfo2', 'url' => '/.well-known/nodeinfo/2.0', 'verb' => 'GET'],
		['name' => 'OAuth#apps', 'url' => '/api/v1/apps', 'verb' => 'POST'],
		['name' => 'OAuth#authorize', 'url' => '/oauth/authorize', 'verb' => 'GET'],
		['name' => 'OAuth#authorizing', 'url' => '/oauth/authorize', 'verb' => 'POST'],
		['name' => 'OAuth#token', 'url' => '/oauth/token', 'verb' => 'POST'],
		['name' => 'OAuth#revoke', 'url' => '/oauth/revoke', 'verb' => 'POST'],

		['name' => 'Api#appsCredentials', 'url' => '/api/v1/apps/verify_credentials', 'verb' => 'GET'],
		['name' => 'Api#verifyCredentials', 'url' => '/api/v1/accounts/verify_credentials', 'verb' => 'GET'],
		['name' => 'Api#updateCredentials', 'url' => '/api/v1/accounts/update_credentials', 'verb' => 'PATCH'],
		['name' => 'Api#followRequests', 'url' => '/api/v1/follow_requests', 'verb' => 'GET'],
		['name' => 'Api#followRequestAuthorize', 'url' => '/api/v1/follow_requests/{id}/authorize', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#followRequestReject', 'url' => '/api/v1/follow_requests/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#reportNew', 'url' => '/api/v1/reports', 'verb' => 'POST'],
		['name' => 'Api#pollGet', 'url' => '/api/v1/polls/{nid}', 'verb' => 'GET'],
		['name' => 'Api#pollVote', 'url' => '/api/v1/polls/{nid}/votes', 'verb' => 'POST'],
		['name' => 'Api#instance', 'url' => '/api/v1/instance/', 'verb' => 'GET'],
		['name' => 'Api#customEmojis', 'url' => '/api/v1/custom_emojis', 'verb' => 'GET'],
		['name' => 'Api#trendTags', 'url' => '/api/v1/trends/tags', 'verb' => 'GET'],
		['name' => 'Api#savedSearches', 'url' => '/api/saved_searches/list.json', 'verb' => 'GET'],
		['name' => 'Api#searchV2', 'url' => '/api/v2/search', 'verb' => 'GET'],
		['name' => 'Api#timelines', 'url' => '/api/v1/timelines/{timeline}/', 'verb' => 'GET'],
		['name' => 'Api#favourites', 'url' => '/api/v1/favourites/', 'verb' => 'GET'],
		['name' => 'Api#bookmarks', 'url' => '/api/v1/bookmarks', 'verb' => 'GET'],
		['name' => 'Api#notifications', 'url' => '/api/v1/notifications', 'verb' => 'GET'],
		['name' => 'Api#notificationsUnreadCount', 'url' => '/api/v1/notifications/unread_count', 'verb' => 'GET'],
		['name' => 'Api#markersGet', 'url' => '/api/v1/markers', 'verb' => 'GET'],
		['name' => 'Api#markersSet', 'url' => '/api/v1/markers', 'verb' => 'POST'],
		['name' => 'Api#tag', 'url' => '/api/v1/timelines/tag/{hashtag}', 'verb' => 'GET'],
		['name' => 'Api#mediaNew', 'url' => '/api/v1/media', 'verb' => 'POST'],
		['name' => 'Api#mediaNewV2', 'url' => '/api/v2/media', 'verb' => 'POST'],
		['name' => 'Api#mediaGet', 'url' => '/api/v1/media/{nid}', 'verb' => 'GET'],
		['name' => 'Api#mediaUpdate', 'url' => '/api/v1/media/{nid}', 'verb' => 'PUT'],
		['name' => 'Api#mediaOpen', 'url' => '/media/{uuid}', 'verb' => 'GET'],
		['name' => 'Api#statusNew', 'url' => '/api/v1/statuses', 'verb' => 'POST'],
		['name' => 'Api#statusUpdate', 'url' => '/api/v1/statuses/{nid}', 'verb' => 'PUT'],
		['name' => 'Api#statusGet', 'url' => '/api/v1/statuses/{nid}', 'verb' => 'GET'],
		['name' => 'Api#statusContext', 'url' => '/api/v1/statuses/{nid}/context', 'verb' => 'GET'],
		['name' => 'Api#statusAction', 'url' => '/api/v1/statuses/{nid}/{act}', 'verb' => 'POST'],
		['name' => 'Api#relationships', 'url' => '/api/v1/accounts/relationships', 'verb' => 'GET'],
		['name' => 'Api#accountStatuses', 'url' => '/api/v1/accounts/{account}/statuses', 'verb' => 'GET', 'requirements' => ['account' => '.+']],
		['name' => 'Api#accountFollow', 'url' => '/api/v1/accounts/{id}/follow', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountUnfollow', 'url' => '/api/v1/accounts/{id}/unfollow', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountBlock', 'url' => '/api/v1/accounts/{id}/block', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountUnblock', 'url' => '/api/v1/accounts/{id}/unblock', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountMute', 'url' => '/api/v1/accounts/{id}/mute', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountUnmute', 'url' => '/api/v1/accounts/{id}/unmute', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#blocks', 'url' => '/api/v1/blocks', 'verb' => 'GET'],
		['name' => 'Api#mutes', 'url' => '/api/v1/mutes', 'verb' => 'GET'],
		['name' => 'Api#accountFollowers', 'url' => '/api/v1/accounts/{account}/followers', 'verb' => 'GET', 'requirements' => ['account' => '.+']],
		['name' => 'Api#accountFollowing', 'url' => '/api/v1/accounts/{account}/following', 'verb' => 'GET', 'requirements' => ['account' => '.+']],

		['name' => 'Local#streamHome', 'url' => '/api/v1/stream/home', 'verb' => 'GET'],
		['name' => 'Local#streamNotifications', 'url' => '/api/v1/stream/notifications', 'verb' => 'GET'],
		['name' => 'Local#streamTimeline', 'url' => '/api/v1/stream/timeline', 'verb' => 'GET'],
		['name' => 'Local#streamTag', 'url' => '/api/v1/stream/tag/{hashtag}/', 'verb' => 'GET'],
		['name' => 'Local#streamFederated', 'url' => '/api/v1/stream/federated', 'verb' => 'GET'],
		['name' => 'Local#streamDirect', 'url' => '/api/v1/stream/direct', 'verb' => 'GET'],
		['name' => 'Local#streamLiked', 'url' => '/api/v1/stream/liked', 'verb' => 'GET'],
		['name' => 'Local#streamAccount', 'url' => '/api/v1/account/{username}/stream', 'verb' => 'GET', 'requirements' => ['username' => '.+']],
		['name' => 'Local#postGet', 'url' => '/local/v1/post', 'verb' => 'GET'],
		['name' => 'Local#postReplies', 'url' => '/local/v1/post/replies', 'verb' => 'GET'],
		['name' => 'Local#postCreate', 'url' => '/api/v1/post', 'verb' => 'POST'],
		['name' => 'Local#postDelete', 'url' => '/api/v1/post', 'verb' => 'DELETE'],
		['name' => 'Local#postLike', 'url' => '/api/v1/post/like', 'verb' => 'POST'],
		['name' => 'Local#postUnlike', 'url' => '/api/v1/post/like', 'verb' => 'DELETE'],
		['name' => 'Local#actionFollow', 'url' => '/api/v1/current/follow', 'verb' => 'PUT'],
		['name' => 'Local#actionUnfollow', 'url' => '/api/v1/current/follow', 'verb' => 'DELETE'],
		['name' => 'Local#currentInfo', 'url' => '/api/v1/current/info', 'verb' => 'GET'],
		['name' => 'Local#accountFields', 'url' => '/api/v1/account/fields', 'verb' => 'PUT'],
		['name' => 'Local#currentFollowers', 'url' => '/api/v1/current/followers', 'verb' => 'GET'],
		['name' => 'Local#currentFollowing', 'url' => '/api/v1/current/following', 'verb' => 'GET'],
		['name' => 'Local#accountInfo', 'url' => '/api/v1/account/{username}/info', 'verb' => 'GET'],
		['name' => 'Local#globalAccountInfo', 'url' => '/api/v1/global/account/info', 'verb' => 'GET'],
		['name' => 'Local#globalActorInfo', 'url' => '/api/v1/global/actor/info', 'verb' => 'GET'],
		['name' => 'Local#globalActorAvatar', 'url' => '/api/v1/global/actor/avatar', 'verb' => 'GET'],
		['name' => 'Local#globalActorHeader', 'url' => '/api/v1/global/actor/header', 'verb' => 'GET'],
		['name' => 'Local#uploadBanner', 'url' => '/api/v1/banner', 'verb' => 'POST'],
		['name' => 'Local#uploadBannerByUrl', 'url' => '/api/v1/banner/url', 'verb' => 'POST'],
		['name' => 'Local#globalAccountsSearch', 'url' => '/api/v1/global/accounts/search', 'verb' => 'GET'],
		['name' => 'Local#globalTagsSearch', 'url' => '/api/v1/global/tags/search', 'verb' => 'GET'],
		['name' => 'Local#search', 'url' => '/api/v1/search', 'verb' => 'GET'],

		['name' => 'Queue#asyncForRequest', 'url' => '/async/request/{token}', 'verb' => 'POST'],

		['name' => 'Config#setCloudAddress', 'url' => '/api/v1/config/cloudAddress', 'verb' => 'POST'],

		// admin-only moderation actions (session + CSRF, never part of the client API)
		['name' => 'Moderation#reportResolve', 'url' => '/moderation/reports/{id}/resolve', 'verb' => 'POST'],
		['name' => 'Moderation#fediverseAdd', 'url' => '/moderation/fediverse/add', 'verb' => 'POST'],
		['name' => 'Moderation#fediverseRemove', 'url' => '/moderation/fediverse/remove', 'verb' => 'POST'],
		['name' => 'Moderation#fediverseAccess', 'url' => '/moderation/fediverse/access', 'verb' => 'POST'],
		['name' => 'Moderation#retention', 'url' => '/moderation/retention', 'verb' => 'POST']
	]
];
