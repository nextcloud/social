<?php
/**
 * Create your routes in here. The name is the lowercase name of the controller
 * without the controller part, the stuff after the hash is the method.
 * e.g. page#index -> OCA\MailTest\Controller\PageController->index()
 *
 * The controller class has to be registered in the application.php file since
 * it's instantiated in there
 */
return [
	'routes' => [
		['name' => 'Navigation#navigate', 'url' => '/', 'verb' => 'GET'],
		['name' => 'Navigation#test', 'url' => '/test', 'verb' => 'GET'],

		['name' => 'Navigation#timeline', 'url' => '/timeline/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
		['name' => 'Navigation#account', 'url' => '/account/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
//		['name' => 'Navigation#public', 'url' => '/{username}', 'verb' => 'GET'],


//		['name' => 'Account#create', 'url' => '/local/account/{username}', 'verb' => 'POST'],
		['name' => 'Account#info', 'url' => '/local/account/{username}', 'verb' => 'GET'],


		['name' => 'ActivityPub#actor', 'url' => '/users/{username}', 'verb' => 'GET'],
		['name' => 'ActivityPub#actorAlias', 'url' => '/@{username}', 'verb' => 'GET'],
		['name' => 'ActivityPub#inbox', 'url' => '/@{username}/inbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#sharedInbox', 'url' => '/inbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#outbox', 'url' => '/@{username}/outbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#followers', 'url' => '/@{username}/followers', 'verb' => 'GET'],
		['name' => 'ActivityPub#following', 'url' => '/@{username}/following', 'verb' => 'GET'],
		['name' => 'ActivityPub#test', 'url' => '/inbox/{username}', 'verb' => 'POST'],

		['name' => 'SocialPub#displayPost', 'url' => '/@{username}/{postId}', 'verb' => 'GET'],

		['name' => 'Local#newPost', 'url' => '/api/v1/post', 'verb' => 'POST'],
		['name' => 'Local#timeline', 'url' => '/api/v1/timeline', 'verb' => 'GET'],
		['name' => 'Local#direct', 'url' => '/api/v1/direct', 'verb' => 'PUT'],
		['name' => 'Local#accountSearch', 'url' => '/api/v1/accounts/search', 'verb' => 'GET'],
		['name' => 'Local#actorInfo', 'url' => '/api/v1/actor/info', 'verb' => 'GET']

	]
];
