<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AppInfo;

use OCA\Social\Service\CurlService;

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
		['name' => 'ActivityPub#outbox', 'url' => '/@{username}/outbox', 'verb' => 'POST'], // Check if needed
		['name' => 'ActivityPub#followers', 'url' => '/@{username}/followers', 'verb' => 'GET'],
		['name' => 'ActivityPub#following', 'url' => '/@{username}/following', 'verb' => 'GET'],

		['name' => 'ActivityPub#displayPost', 'url' => '/@{username}/{token}', 'verb' => 'GET'],

		// OStatus
		['name' => 'OStatus#subscribe', 'url' => '/ostatus/follow/', 'verb' => 'GET'],
		['name' => 'OStatus#followRemote', 'url' => '/api/v1/ostatus/followRemote/{local}', 'verb' => 'GET'],
		['name' => 'OStatus#getLink', 'url' => '/api/v1/ostatus/link/{local}/{account}', 'verb' => 'GET'],

		// OAuth
		['name' => 'OAuth#nodeinfo2', 'url' => '/.well-known/nodeinfo/2.0', 'verb' => 'GET'],
		['name' => 'OAuth#apps', 'url' => '/api/v1/apps', 'verb' => 'POST'],
		['name' => 'OAuth#authorize', 'url' => '/oauth/authorize', 'verb' => 'GET'],
		['name' => 'OAuth#authorizing', 'url' => '/oauth/authorize', 'verb' => 'POST'],
		['name' => 'OAuth#token', 'url' => '/oauth/token', 'verb' => 'POST'],

		// Api for 3rd party
		['name' => 'Api#appsCredentials', 'url' => '/api/v1/apps/verify_credentials', 'verb' => 'GET'],
		['name' => 'Api#verifyCredentials', 'url' => '/api/v1/accounts/verify_credentials', 'verb' => 'GET'],
		['name' => 'Api#instance', 'url' => '/api/v1/instance/', 'verb' => 'GET'],
		['name' => 'Api#customEmojis', 'url' => '/api/v1/custom_emojis', 'verb' => 'GET'],
		['name' => 'Api#savedSearches', 'url' => '/api/saved_searches/list.json', 'verb' => 'GET'],
		['name' => 'Api#timelines', 'url' => '/api/v1/timelines/{timeline}/', 'verb' => 'GET'],
		['name' => 'Api#favourites', 'url' => '/api/v1/favourites/', 'verb' => 'GET'],
		['name' => 'Api#notifications', 'url' => '/api/v1/notifications', 'verb' => 'GET'],
		['name' => 'Api#tag', 'url' => '/api/v1/timelines/tag/{hashtag}', 'verb' => 'GET'],
		['name' => 'Api#mediaNew', 'url' => '/api/v1/media', 'verb' => 'POST'],
		['name' => 'Api#mediaGet', 'url' => '/api/v1/media/{nid}', 'verb' => 'GET'],
		['name' => 'Api#mediaOpen', 'url' => '/media/{uuid}', 'verb' => 'GET'],

		['name' => 'Api#statusNew', 'url' => '/api/v1/statuses', 'verb' => 'POST'],
		['name' => 'Api#statusGet', 'url' => '/api/v1/statuses/{nid}', 'verb' => 'GET'],
		['name' => 'Api#statusContext', 'url' => '/api/v1/statuses/{nid}/context', 'verb' => 'GET'],
		['name' => 'Api#statusAction', 'url' => '/api/v1/statuses/{nid}/{act}', 'verb' => 'POST'],

		['name' => 'Api#relationships', 'url' => '/api/v1/accounts/relationships', 'verb' => 'GET'],
		['name' => 'Api#accountStatuses', 'url' => '/api/v1/accounts/{account}/statuses', 'verb' => 'GET'],
		['name' => 'Api#accountFollowers', 'url' => '/api/v1/accounts/{account}/followers', 'verb' => 'GET'],
		['name' => 'Api#accountFollowing', 'url' => '/api/v1/accounts/{account}/following', 'verb' => 'GET'],

		// Api for local front-end
		// TODO: front-end should be using the new ApiController
		['name' => 'Local#streamHome', 'url' => '/api/v1/stream/home', 'verb' => 'GET'],
		['name' => 'Local#streamNotifications', 'url' => '/api/v1/stream/notifications', 'verb' => 'GET'],
		['name' => 'Local#streamTimeline', 'url' => '/api/v1/stream/timeline', 'verb' => 'GET'],
		['name' => 'Local#streamTag', 'url' => '/api/v1/stream/tag/{hashtag}/', 'verb' => 'GET'],
		['name' => 'Local#streamFederated', 'url' => '/api/v1/stream/federated', 'verb' => 'GET'],
		['name' => 'Local#streamDirect', 'url' => '/api/v1/stream/direct', 'verb' => 'GET'],
		['name' => 'Local#streamLiked', 'url' => '/api/v1/stream/liked', 'verb' => 'GET'],
		['name' => 'Local#streamAccount', 'url' => '/api/v1/account/{username}/stream', 'verb' => 'GET'],

		['name' => 'Local#postGet', 'url' => '/local/v1/post', 'verb' => 'GET'],
		['name' => 'Local#postReplies', 'url' => '/local/v1/post/replies', 'verb' => 'GET'],

		['name' => 'Local#postCreate', 'url' => '/api/v1/post', 'verb' => 'POST'],
		['name' => 'Local#postDelete', 'url' => '/api/v1/post', 'verb' => 'DELETE'],

		['name' => 'Local#postLike', 'url' => '/api/v1/post/like', 'verb' => 'POST'],
		['name' => 'Local#postUnlike', 'url' => '/api/v1/post/like', 'verb' => 'DELETE'],

		['name' => 'Local#actionFollow', 'url' => '/api/v1/current/follow', 'verb' => 'PUT'],
		['name' => 'Local#actionUnfollow', 'url' => '/api/v1/current/follow', 'verb' => 'DELETE'],

		['name' => 'Local#currentInfo', 'url' => '/api/v1/current/info', 'verb' => 'GET'],
		['name' => 'Local#currentFollowers', 'url' => '/api/v1/current/followers', 'verb' => 'GET'],
		['name' => 'Local#currentFollowing', 'url' => '/api/v1/current/following', 'verb' => 'GET'],

		['name' => 'Local#accountInfo', 'url' => '/api/v1/account/{username}/info', 'verb' => 'GET'],
		//		[
		//			'name' => 'Local#accountFollowers', 'url' => '/api/v1/account/{username}/followers',
		//			'verb' => 'GET'
		//		],
		//		[
		//			'name' => 'Local#accountFollowing', 'url' => '/api/v1/account/{username}/following',
		//			'verb' => 'GET'
		//		],

		['name' => 'Local#globalAccountInfo', 'url' => '/api/v1/global/account/info', 'verb' => 'GET'],
		['name' => 'Local#globalActorInfo', 'url' => '/api/v1/global/actor/info', 'verb' => 'GET'],
		['name' => 'Local#globalActorAvatar', 'url' => '/api/v1/global/actor/avatar', 'verb' => 'GET'],
		['name' => 'Local#globalAccountsSearch', 'url' => '/api/v1/global/accounts/search', 'verb' => 'GET'],
		['name' => 'Local#globalTagsSearch', 'url' => '/api/v1/global/tags/search', 'verb' => 'GET'],

		//		['name' => 'Local#documentsCache', 'url' => '/api/v1/documents/cache', 'verb' => 'POST'],

		['name' => 'Local#search', 'url' => '/api/v1/search', 'verb' => 'GET'],

		['name' => 'Queue#asyncForRequest', 'url' => CurlService::ASYNC_REQUEST_TOKEN, 'verb' => 'POST'],

		['name' => 'Config#setCloudAddress', 'url' => '/api/v1/config/cloudAddress', 'verb' => 'POST']
	]
];
