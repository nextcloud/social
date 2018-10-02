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

//		['name' => 'Account#create', 'url' => '/local/account/{username}', 'verb' => 'POST'],

		['name' => 'ActivityPub#sharedInbox', 'url' => '/inbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#actor', 'url' => '/users/{username}', 'verb' => 'GET'],
		['name' => 'ActivityPub#aliasactor', 'url' => '/@{username}', 'verb' => 'GET'],
		['name' => 'ActivityPub#inbox', 'url' => '/@{username}/inbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#outbox', 'url' => '/@{username}/outbox', 'verb' => 'POST'],
		['name' => 'ActivityPub#followers', 'url' => '/@{username}/followers', 'verb' => 'GET'],
		['name' => 'ActivityPub#following', 'url' => '/@{username}/following', 'verb' => 'GET'],

		['name' => 'SocialPub#displayPost', 'url' => '/@{username}/{postId}', 'verb' => 'GET']
		,
		['name' => 'ActivityPub#test', 'url' => '/inbox/{username}', 'verb' => 'POST'],


	]
];
