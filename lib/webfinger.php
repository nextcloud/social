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

namespace OCA\Social;


use Exception;
use OCA\Social\Service\CacheActorService;

require_once(__DIR__ . '/../appinfo/autoload.php');


if (!array_key_exists('resource', $_GET)) {
	echo 'missing resource';
	exit();
}

$subject = $_GET['resource'];

$urlGenerator = \OC::$server->getURLGenerator();

list($type, $account) = explode(':', $subject, 2);
if ($type !== 'acct') {
	echo 'no acct';
	exit();
}


$username = substr($account, 0, strrpos($account, '@'));

try {
	$cacheActorService = \OC::$server->query(CacheActorService::class);
	$cacheActorService->getFromLocalAccount($username);
} catch (Exception $e) {
	http_response_code(404);
	exit;
}

$href =
	$urlGenerator->linkToRouteAbsolute('social.ActivityPub.actorAlias', ['username' => $username]);

if (substr($href, -1) === '/') {
	$href = substr($href, 0, -1);
}

$finger = [
	'subject' => $subject,
	'links'   => [
		[
			'rel'  => 'self',
			'type' => 'application/activity+json',
			'href' => $href
		]
	]
];


header('Content-type: application/json');

echo json_encode($finger);

