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
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;

require_once(__DIR__ . '/../vendor/autoload.php');

try {
	$fediverseService = OC::$server->query(FediverseService::class);
	/** @var ConfigService $configService */
	$configService = OC::$server->query(ConfigService::class);
	$fediverseService->jailed();

} catch (Exception $e) {
	OC::$server->getLogger()
			   ->log(1, 'Exception on hostmeta - ' . $e->getMessage());
	http_response_code(404);
	exit;
}

header('Content-type: application/xrd+xml');

try {
	$url = $configService->getCloudUrl(true) . '/.well-known/webfinger?resource={uri}';
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0">' . "\n";
	echo '  <Link rel="lrdd" type="application/xrd+xml" template="' . $url . '"/>' . "\n";
	echo '</XRD>' . "\n";
} catch (Exceptions\SocialAppConfigException $e) {
}
