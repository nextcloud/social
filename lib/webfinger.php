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
use OC;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCP\AppFramework\QueryException;

require_once(__DIR__ . '/../vendor/autoload.php');

if (!array_key_exists('resource', $_GET)) {
	echo 'missing resource';
	exit();
}

$subject = $_GET['resource'];

$urlGenerator = OC::$server->getURLGenerator();
$type = '';

if (strpos($subject, 'acct:') === 0) {
	list($type, $account) = explode(':', $subject, 2);
	$type .= ':';
} else {
	$account = $subject;
}

list($username, $instance) = explode('@', $account);
try {
	/** @var CacheActorService $cacheActorService */
	$cacheActorService = OC::$server->query(CacheActorService::class);
	/** @var FediverseService $fediverseService */
	$fediverseService = OC::$server->query(FediverseService::class);
	/** @var ConfigService $configService */
	$configService = OC::$server->query(ConfigService::class);
} catch (QueryException $e) {
	OC::$server->getLogger()
			   ->log(1, 'QueryException - ' . $e->getMessage());
	http_response_code(404);
	exit;
}

try {
	$fediverseService->jailed();

	$cacheActorService->getFromLocalAccount($username);
} catch (Exception $e) {
	if ($type !== '') {
		OC::$server->getLogger()
				   ->log(1, 'Exception on webfinger/fromAccount - ' . $e->getMessage());
		http_response_code(404);
		exit;
	}

	try {
		$fromId = $cacheActorService->getFromId($subject);
		$instance = $configService->getSocialAddress();
		$username = $fromId->getPreferredUsername();
	} catch (Exception $e) {
		OC::$server->getLogger()
				   ->log(1, 'Exception on webfinger/fromId - ' . $e->getMessage());
		http_response_code(404);
		exit;
	}
}

try {
	$href = $configService->getSocialUrl() . '@' . $username;
} catch (SocialAppConfigException $e) {
	http_response_code(404);
	exit;
}

if (substr($href, -1) === '/') {
	$href = substr($href, 0, -1);
}

$finger = [
	'subject' => $type . $username . '@' . $instance,
	'links'   => [
		[
			'rel'  => 'self',
			'type' => 'application/activity+json',
			'href' => $href
		],
		[
			'rel'      => 'http://ostatus.org/schema/1.0/subscribe',
			'template' => urldecode(
				$href = $urlGenerator->linkToRouteAbsolute('social.OStatus.subscribe') . '?uri={uri}'
			)
		]
	]
];

header('Content-type: application/json');

echo json_encode($finger);

