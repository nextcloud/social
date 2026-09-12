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

		// The instance's own Application actor, at the path Mastodon serves its
		// own at. `InstanceActorService::PATH` builds the same URL, and that is
		// the `keyId` owner every peer dereferences to check a signed fetch.
		['name' => 'ActivityPub#instanceActor', 'url' => '/actor', 'verb' => 'GET'],
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
		// before displayPost, whose {token} is a single segment and so would
		// never match this, but the two belong next to each other
		['name' => 'ActivityPub#displayQuoteAuthorization', 'url' => '/@{username}/{token}/quote_authorizations/{stamp}', 'verb' => 'GET'],
		['name' => 'ActivityPub#replies', 'url' => '/@{username}/{token}/replies', 'verb' => 'GET'],
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
		['name' => 'Api#instanceV2', 'url' => '/api/v2/instance', 'verb' => 'GET'],
		['name' => 'Api#instancePeers', 'url' => '/api/v1/instance/peers', 'verb' => 'GET'],
		['name' => 'Api#instanceActivity', 'url' => '/api/v1/instance/activity', 'verb' => 'GET'],
		['name' => 'Api#preferences', 'url' => '/api/v1/preferences', 'verb' => 'GET'],
		['name' => 'Api#customEmojis', 'url' => '/api/v1/custom_emojis', 'verb' => 'GET'],
		['name' => 'Api#emojiOpen', 'url' => '/emoji/{shortcode}', 'verb' => 'GET'],
		['name' => 'Api#trendTags', 'url' => '/api/v1/trends/tags', 'verb' => 'GET'],
		['name' => 'Discovery#trendStatuses', 'url' => '/api/v1/trends/statuses', 'verb' => 'GET'],
		['name' => 'Discovery#trendLinks', 'url' => '/api/v1/trends/links', 'verb' => 'GET'],

		// Discovery. /featured_tags/suggestions is registered ahead of the
		// {id} lookup so it cannot be read as a featured tag with the id
		// "suggestions"; the lookup is a \d+ for the same reason.
		['name' => 'Discovery#directory', 'url' => '/api/v1/directory', 'verb' => 'GET'],
		['name' => 'Discovery#suggestions', 'url' => '/api/v2/suggestions', 'verb' => 'GET'],
		['name' => 'Discovery#suggestionsV1', 'url' => '/api/v1/suggestions', 'verb' => 'GET'],
		['name' => 'Discovery#featuredTagSuggestions', 'url' => '/api/v1/featured_tags/suggestions', 'verb' => 'GET'],
		['name' => 'Discovery#featuredTags', 'url' => '/api/v1/featured_tags', 'verb' => 'GET'],
		['name' => 'Discovery#featureTag', 'url' => '/api/v1/featured_tags', 'verb' => 'POST'],
		['name' => 'Discovery#unfeatureTag', 'url' => '/api/v1/featured_tags/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Following a hashtag. The two action routes are registered ahead of the
		// lookup so that /api/v1/tags/foo/follow cannot be read as a tag named
		// "foo/follow" if the lookup ever gains a `.+` requirement.
		['name' => 'Tag#followedTags', 'url' => '/api/v1/followed_tags', 'verb' => 'GET'],
		['name' => 'Tag#follow', 'url' => '/api/v1/tags/{hashtag}/follow', 'verb' => 'POST'],
		['name' => 'Tag#unfollow', 'url' => '/api/v1/tags/{hashtag}/unfollow', 'verb' => 'POST'],
		['name' => 'Tag#get', 'url' => '/api/v1/tags/{hashtag}', 'verb' => 'GET'],

		// Lists. The membership routes are registered ahead of the list itself
		// so that /api/v1/lists/4/accounts cannot be read as a list named
		// "4/accounts" if the lookup ever gains a `.+` requirement.
		['name' => 'List#index', 'url' => '/api/v1/lists', 'verb' => 'GET'],
		['name' => 'List#create', 'url' => '/api/v1/lists', 'verb' => 'POST'],
		['name' => 'List#accounts', 'url' => '/api/v1/lists/{id}/accounts', 'verb' => 'GET'],
		['name' => 'List#addAccounts', 'url' => '/api/v1/lists/{id}/accounts', 'verb' => 'POST'],
		['name' => 'List#removeAccounts', 'url' => '/api/v1/lists/{id}/accounts', 'verb' => 'DELETE'],
		['name' => 'List#get', 'url' => '/api/v1/lists/{id}', 'verb' => 'GET'],
		['name' => 'List#update', 'url' => '/api/v1/lists/{id}', 'verb' => 'PUT'],
		['name' => 'List#delete', 'url' => '/api/v1/lists/{id}', 'verb' => 'DELETE'],
		['name' => 'List#timeline', 'url' => '/api/v1/timelines/list/{id}', 'verb' => 'GET'],

		// conversations: the direct timeline grouped by thread, which is the
		// screen a Mastodon client reads direct messages from. The sub-path is
		// declared before the {id} lookup, as the lists routes are, so that
		// /api/v1/conversations/4/read cannot be read as a conversation "4"
		['name' => 'Conversation#index', 'url' => '/api/v1/conversations', 'verb' => 'GET'],
		['name' => 'Conversation#read', 'url' => '/api/v1/conversations/{id}/read', 'verb' => 'POST'],
		['name' => 'Conversation#delete', 'url' => '/api/v1/conversations/{id}', 'verb' => 'DELETE'],

		// Keyword filters, Mastodon's v2 API. The v1 routes are deprecated
		// there and are deliberately not served: a v1 client cannot express
		// `hide`, an expiry, or a keyword id.
		// Mastodon's v1 filters, over the v2 ones: a v1 filter is one keyword.
		// A client that has not moved reads a 404 here as "this server has no
		// filters", which is a different thing from "none configured".
		['name' => 'Filter#indexV1', 'url' => '/api/v1/filters', 'verb' => 'GET'],
		['name' => 'Filter#createV1', 'url' => '/api/v1/filters', 'verb' => 'POST'],
		['name' => 'Filter#getV1', 'url' => '/api/v1/filters/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#updateV1', 'url' => '/api/v1/filters/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#deleteV1', 'url' => '/api/v1/filters/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#index', 'url' => '/api/v2/filters', 'verb' => 'GET'],
		['name' => 'Filter#create', 'url' => '/api/v2/filters', 'verb' => 'POST'],
		['name' => 'Filter#getKeyword', 'url' => '/api/v2/filters/keywords/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#updateKeyword', 'url' => '/api/v2/filters/keywords/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#deleteKeyword', 'url' => '/api/v2/filters/keywords/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#keywords', 'url' => '/api/v2/filters/{id}/keywords', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#addKeyword', 'url' => '/api/v2/filters/{id}/keywords', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#get', 'url' => '/api/v2/filters/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#update', 'url' => '/api/v2/filters/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'Filter#delete', 'url' => '/api/v2/filters/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Mastodon's announcements: what the instance is telling everybody,
		// and the dismissal that makes one read for a single account. The
		// window is a predicate of the read, so nothing has to run for an
		// announcement to start or stop applying.
		['name' => 'Announcement#index', 'url' => '/api/v1/announcements', 'verb' => 'GET'],
		['name' => 'Announcement#dismiss', 'url' => '/api/v1/announcements/{id}/dismiss', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'Api#savedSearches', 'url' => '/api/saved_searches/list.json', 'verb' => 'GET'],
		['name' => 'Api#searchV2', 'url' => '/api/v2/search', 'verb' => 'GET'],
		// Mastodon's own v1 search. This path used to be the app's web-UI search
		// (now /local/v1/search): the shape a client got back was a Nextcloud
		// envelope it could make nothing of.
		['name' => 'Api#search', 'url' => '/api/v1/search', 'verb' => 'GET'],
		['name' => 'Api#timelines', 'url' => '/api/v1/timelines/{timeline}/', 'verb' => 'GET'],
		['name' => 'Api#favourites', 'url' => '/api/v1/favourites/', 'verb' => 'GET'],
		['name' => 'Api#bookmarks', 'url' => '/api/v1/bookmarks', 'verb' => 'GET'],
		['name' => 'Api#notifications', 'url' => '/api/v1/notifications', 'verb' => 'GET'],
		['name' => 'Api#notificationsUnreadCount', 'url' => '/api/v1/notifications/unread_count', 'verb' => 'GET'],
		['name' => 'Notification#clear', 'url' => '/api/v1/notifications/clear', 'verb' => 'POST'],
		['name' => 'Notification#get', 'url' => '/api/v1/notifications/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'Notification#dismiss', 'url' => '/api/v1/notifications/{id}/dismiss', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'Api#markersGet', 'url' => '/api/v1/markers', 'verb' => 'GET'],
		['name' => 'Api#markersSet', 'url' => '/api/v1/markers', 'verb' => 'POST'],
		['name' => 'Api#tag', 'url' => '/api/v1/timelines/tag/{hashtag}', 'verb' => 'GET'],
		['name' => 'Api#mediaNew', 'url' => '/api/v1/media', 'verb' => 'POST'],
		['name' => 'Api#mediaNewV2', 'url' => '/api/v2/media', 'verb' => 'POST'],
		// a Nextcloud extension, not a Mastodon route: attach a file the user
		// already has here rather than making them download and re-upload it
		['name' => 'Api#mediaFromFile', 'url' => '/api/v1/media/from-file', 'verb' => 'POST'],
		['name' => 'Api#mediaGet', 'url' => '/api/v1/media/{nid}', 'verb' => 'GET'],
		['name' => 'Api#mediaUpdate', 'url' => '/api/v1/media/{nid}', 'verb' => 'PUT'],
		['name' => 'Api#mediaOpen', 'url' => '/media/{uuid}', 'verb' => 'GET'],
		['name' => 'Api#statusNew', 'url' => '/api/v1/statuses', 'verb' => 'POST'],
		['name' => 'Api#statusUpdate', 'url' => '/api/v1/statuses/{nid}', 'verb' => 'PUT'],
		['name' => 'Api#statusGet', 'url' => '/api/v1/statuses/{nid}', 'verb' => 'GET'],
		['name' => 'Api#statusDelete', 'url' => '/api/v1/statuses/{nid}', 'verb' => 'DELETE'],
		['name' => 'Api#statusSource', 'url' => '/api/v1/statuses/{nid}/source', 'verb' => 'GET'],
		['name' => 'Api#statusContext', 'url' => '/api/v1/statuses/{nid}/context', 'verb' => 'GET'],
		['name' => 'Api#statusFavouritedBy', 'url' => '/api/v1/statuses/{nid}/favourited_by', 'verb' => 'GET'],
		['name' => 'Api#statusRebloggedBy', 'url' => '/api/v1/statuses/{nid}/reblogged_by', 'verb' => 'GET'],
		['name' => 'History#history', 'url' => '/api/v1/statuses/{nid}/history', 'verb' => 'GET'],
		['name' => 'Api#statusAction', 'url' => '/api/v1/statuses/{nid}/{act}', 'verb' => 'POST'],
		['name' => 'Api#scheduledStatuses', 'url' => '/api/v1/scheduled_statuses', 'verb' => 'GET'],
		['name' => 'Api#scheduledStatusGet', 'url' => '/api/v1/scheduled_statuses/{id}', 'verb' => 'GET'],
		['name' => 'Api#scheduledStatusUpdate', 'url' => '/api/v1/scheduled_statuses/{id}', 'verb' => 'PUT'],
		['name' => 'Api#scheduledStatusDelete', 'url' => '/api/v1/scheduled_statuses/{id}', 'verb' => 'DELETE'],
		['name' => 'Api#relationships', 'url' => '/api/v1/accounts/relationships', 'verb' => 'GET'],
		['name' => 'Api#accountLookup', 'url' => '/api/v1/accounts/lookup', 'verb' => 'GET'],
		['name' => 'Api#accountStatuses', 'url' => '/api/v1/accounts/{account}/statuses', 'verb' => 'GET', 'requirements' => ['account' => '.+']],
		['name' => 'Api#accountFollow', 'url' => '/api/v1/accounts/{id}/follow', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountUnfollow', 'url' => '/api/v1/accounts/{id}/unfollow', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Follower#remove', 'url' => '/api/v1/accounts/{id}/remove_from_followers', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountBlock', 'url' => '/api/v1/accounts/{id}/block', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountUnblock', 'url' => '/api/v1/accounts/{id}/unblock', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountMute', 'url' => '/api/v1/accounts/{id}/mute', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Api#accountUnmute', 'url' => '/api/v1/accounts/{id}/unmute', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Relation#note', 'url' => '/api/v1/accounts/{id}/note', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Relation#pin', 'url' => '/api/v1/accounts/{id}/pin', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Relation#unpin', 'url' => '/api/v1/accounts/{id}/unpin', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'Relation#endorsements', 'url' => '/api/v1/endorsements', 'verb' => 'GET'],
		['name' => 'Relation#domainBlocks', 'url' => '/api/v1/domain_blocks', 'verb' => 'GET'],
		['name' => 'Relation#blockDomain', 'url' => '/api/v1/domain_blocks', 'verb' => 'POST'],
		['name' => 'Relation#unblockDomain', 'url' => '/api/v1/domain_blocks', 'verb' => 'DELETE'],
		['name' => 'Api#blocks', 'url' => '/api/v1/blocks', 'verb' => 'GET'],
		['name' => 'Api#mutes', 'url' => '/api/v1/mutes', 'verb' => 'GET'],
		['name' => 'Api#accountFollowers', 'url' => '/api/v1/accounts/{account}/followers', 'verb' => 'GET', 'requirements' => ['account' => '.+']],
		['name' => 'Discovery#accountFeaturedTags', 'url' => '/api/v1/accounts/{account}/featured_tags', 'verb' => 'GET', 'requirements' => ['account' => '.+']],
		['name' => 'List#accountLists', 'url' => '/api/v1/accounts/{account}/lists', 'verb' => 'GET', 'requirements' => ['account' => '.+']],
		['name' => 'Api#accountFollowing', 'url' => '/api/v1/accounts/{account}/following', 'verb' => 'GET', 'requirements' => ['account' => '.+']],
		['name' => 'Api#familiarFollowers', 'url' => '/api/v1/accounts/familiar_followers', 'verb' => 'GET'],
		['name' => 'Api#accountsSearch', 'url' => '/api/v1/accounts/search', 'verb' => 'GET'],
		// Last of the /accounts routes on purpose, and it has to stay last:
		// {id} accepts slashes (a client may hold an actor URI rather than a
		// numeric id), so it would otherwise swallow 'relationships', 'lookup',
		// 'verify_credentials', 'search' and the {account} sub-routes.
		['name' => 'Api#accountGet', 'url' => '/api/v1/accounts/{id}', 'verb' => 'GET', 'requirements' => ['id' => '.+']],

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
		['name' => 'Local#accountSummary', 'url' => '/api/v1/account/summary', 'verb' => 'PUT'],
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
		['name' => 'Local#search', 'url' => '/local/v1/search', 'verb' => 'GET'],

		['name' => 'Queue#asyncForRequest', 'url' => '/async/request/{token}', 'verb' => 'POST'],

		['name' => 'Config#setCloudAddress', 'url' => '/api/v1/config/cloudAddress', 'verb' => 'POST'],

		// Mastodon's admin API. Every route requires a Nextcloud
		// administrator — asked of the user behind the token or the session,
		// never of the token's scope — see AdminApiController. The action
		// routes are declared before the {id} lookup, as the lists routes
		// are, so that /api/v1/admin/accounts/7/action cannot be read as an
		// account named "7/action"; {id} accepts slashes because a suspended
		// account, whose cached actor the suspension purged, is named by its
		// actor id and no longer by a numeric one.
		['name' => 'AdminApi#accounts', 'url' => '/api/v1/admin/accounts', 'verb' => 'GET'],
		['name' => 'AdminApi#accountAction', 'url' => '/api/v1/admin/accounts/{id}/action', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'AdminApi#accountEnable', 'url' => '/api/v1/admin/accounts/{id}/enable', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'AdminApi#accountUnsilence', 'url' => '/api/v1/admin/accounts/{id}/unsilence', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'AdminApi#accountUnsuspend', 'url' => '/api/v1/admin/accounts/{id}/unsuspend', 'verb' => 'POST', 'requirements' => ['id' => '.+']],
		['name' => 'AdminApi#account', 'url' => '/api/v1/admin/accounts/{id}', 'verb' => 'GET', 'requirements' => ['id' => '.+']],
		['name' => 'AdminApi#reports', 'url' => '/api/v1/admin/reports', 'verb' => 'GET'],
		['name' => 'AdminApi#reportResolve', 'url' => '/api/v1/admin/reports/{id}/resolve', 'verb' => 'POST'],
		['name' => 'AdminApi#reportReopen', 'url' => '/api/v1/admin/reports/{id}/reopen', 'verb' => 'POST'],
		['name' => 'AdminApi#reportAssignToSelf', 'url' => '/api/v1/admin/reports/{id}/assign_to_self', 'verb' => 'POST'],
		['name' => 'AdminApi#reportUnassign', 'url' => '/api/v1/admin/reports/{id}/unassign', 'verb' => 'POST'],
		['name' => 'AdminApi#report', 'url' => '/api/v1/admin/reports/{id}', 'verb' => 'GET'],
		['name' => 'AdminApi#ipBlocks', 'url' => '/api/v1/admin/ip_blocks', 'verb' => 'GET'],
		['name' => 'AdminApi#ipBlockCreate', 'url' => '/api/v1/admin/ip_blocks', 'verb' => 'POST'],
		['name' => 'AdminApi#ipBlock', 'url' => '/api/v1/admin/ip_blocks/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'AdminApi#ipBlockUpdate', 'url' => '/api/v1/admin/ip_blocks/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'AdminApi#ipBlockRemove', 'url' => '/api/v1/admin/ip_blocks/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'AdminApi#emailDomainBlocks', 'url' => '/api/v1/admin/email_domain_blocks', 'verb' => 'GET'],
		['name' => 'AdminApi#emailDomainBlockCreate', 'url' => '/api/v1/admin/email_domain_blocks', 'verb' => 'POST'],
		['name' => 'AdminApi#emailDomainBlock', 'url' => '/api/v1/admin/email_domain_blocks/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'AdminApi#emailDomainBlockRemove', 'url' => '/api/v1/admin/email_domain_blocks/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'AdminApi#domainBlocks', 'url' => '/api/v1/admin/domain_blocks', 'verb' => 'GET'],
		['name' => 'AdminApi#domainBlockCreate', 'url' => '/api/v1/admin/domain_blocks', 'verb' => 'POST'],
		['name' => 'AdminApi#domainBlock', 'url' => '/api/v1/admin/domain_blocks/{id}', 'verb' => 'GET'],
		['name' => 'AdminApi#domainBlockUpdate', 'url' => '/api/v1/admin/domain_blocks/{id}', 'verb' => 'PUT'],
		['name' => 'AdminApi#domainBlockRemove', 'url' => '/api/v1/admin/domain_blocks/{id}', 'verb' => 'DELETE'],

		// admin-only moderation actions (session + CSRF, never part of the client API)
		['name' => 'Moderation#reportResolve', 'url' => '/moderation/reports/{id}/resolve', 'verb' => 'POST'],
		['name' => 'Moderation#fediverseAdd', 'url' => '/moderation/fediverse/add', 'verb' => 'POST'],
		['name' => 'Moderation#fediverseRemove', 'url' => '/moderation/fediverse/remove', 'verb' => 'POST'],
		['name' => 'Moderation#fediverseAccess', 'url' => '/moderation/fediverse/access', 'verb' => 'POST'],
		['name' => 'Moderation#retention', 'url' => '/moderation/retention', 'verb' => 'POST'],
		['name' => 'Moderation#accounts', 'url' => '/moderation/accounts', 'verb' => 'GET'],
		['name' => 'Moderation#accountHistory', 'url' => '/moderation/accounts/history', 'verb' => 'GET'],
		['name' => 'Moderation#accountModerate', 'url' => '/moderation/accounts', 'verb' => 'POST'],
		['name' => 'Moderation#statusRemove', 'url' => '/moderation/statuses/remove', 'verb' => 'POST'],
		['name' => 'Announcement#react', 'url' => '/api/v1/announcements/{id}/reactions/{name}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+', 'name' => '.+']],
		['name' => 'Announcement#unreact', 'url' => '/api/v1/announcements/{id}/reactions/{name}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+', 'name' => '.+']],
		['name' => 'Announcement#adminIndex', 'url' => '/admin/announcements', 'verb' => 'GET'],
		['name' => 'Announcement#adminCreate', 'url' => '/admin/announcements', 'verb' => 'POST'],
		['name' => 'Announcement#adminDelete', 'url' => '/admin/announcements/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']]
	]
];
