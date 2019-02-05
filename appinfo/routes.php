<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social\AppInfo;


use OCA\Social\Service\CurlService;


return [
	'routes' => [
		['name' => 'Navigation#navigate', 'url' => '/', 'verb' => 'GET'],
		['name' => 'Navigation#test', 'url' => '/test', 'verb' => 'GET'],

		[
			'name'         => 'Navigation#timeline', 'url' => '/timeline/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']
		],
		['name' => 'Navigation#documentGet', 'url' => '/document/get', 'verb' => 'GET'],
		['name' => 'Navigation#documentGetPublic', 'url' => '/document/public', 'verb' => 'GET'],

		['name' => 'ActivityPub#actor', 'url' => '/users/{username}', 'verb' => 'GET'],
		['name' => 'ActivityPub#actorAlias', 'url' => '/@{username}/', 'verb' => 'GET'],
		['name' => 'ActivityPub#inbox', 'url' => '/@{username}/inbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#sharedInbox', 'url' => '/inbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#outbox', 'url' => '/@{username}/outbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#followers', 'url' => '/@{username}/followers', 'verb' => 'GET'],
		['name' => 'ActivityPub#following', 'url' => '/@{username}/following', 'verb' => 'GET'],

		['name' => 'SocialPub#displayPost', 'url' => '/@{username}/{postId}', 'verb' => 'GET'],

		['name' => 'Local#streamHome', 'url' => '/api/v1/stream/home', 'verb' => 'GET'],
		['name' => 'Local#streamNotifications', 'url' => '/api/v1/stream/notifications', 'verb' => 'GET'],
		['name' => 'Local#streamTimeline', 'url' => '/api/v1/stream/timeline', 'verb' => 'GET'],
		['name' => 'Local#streamTag', 'url' => '/api/v1/stream/tag/{hashtag}/', 'verb' => 'GET'],
		['name' => 'Local#streamFederated', 'url' => '/api/v1/stream/federated', 'verb' => 'GET'],
		['name' => 'Local#streamDirect', 'url' => '/api/v1/stream/direct', 'verb' => 'GET'],
		['name' => 'Local#streamAccount', 'url' => '/api/v1/account/{username}/stream', 'verb' => 'GET'],

		['name' => 'Local#postCreate', 'url' => '/api/v1/post', 'verb' => 'POST'],
		['name' => 'Local#postDelete', 'url' => '/api/v1/post', 'verb' => 'DELETE'],

		['name' => 'Local#actionFollow', 'url' => '/api/v1/current/follow', 'verb' => 'PUT'],
		['name' => 'Local#actionUnfollow', 'url' => '/api/v1/current/follow', 'verb' => 'DELETE'],

		['name' => 'Local#currentInfo', 'url' => '/api/v1/current/info', 'verb' => 'GET'],
		['name' => 'Local#currentFollowers', 'url' => '/api/v1/current/followers', 'verb' => 'GET'],
		['name' => 'Local#currentFollowing', 'url' => '/api/v1/current/following', 'verb' => 'GET'],

		['name' => 'Local#accountInfo', 'url' => '/api/v1/account/{username}/info', 'verb' => 'GET'],
		['name' => 'Local#accountFollowers', 'url' => '/api/v1/account/{username}/followers', 'verb' => 'GET'],
		['name' => 'Local#accountFollowing', 'url' => '/api/v1/account/{username}/following', 'verb' => 'GET'],

		['name' => 'Local#globalAccountInfo', 'url' => '/api/v1/global/account/info', 'verb' => 'GET'],
		['name' => 'Local#globalActorInfo', 'url' => '/api/v1/global/actor/info', 'verb' => 'GET'],
		['name' => 'Local#globalActorAvatar', 'url' => '/api/v1/global/actor/avatar', 'verb' => 'GET'],
		['name' => 'Local#globalAccountsSearch', 'url' => '/api/v1/global/accounts/search', 'verb' => 'GET'],

		['name' => 'Local#search', 'url' => '/api/v1/search', 'verb' => 'GET'],

		['name' => 'Local#documentsCache', 'url' => '/api/v1/documents/cache', 'verb' => 'POST'],

		['name' => 'Queue#asyncWithToken', 'url' => CurlService::ASYNC_TOKEN, 'verb' => 'POST'],

		[
			'name' => 'Config#setCloudAddress', 'url' => '/api/v1/config/cloudAddress',
			'verb' => 'POST'
		],

	]
];
