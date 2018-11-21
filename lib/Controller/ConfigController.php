<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Controller;

use OCA\Activity\Data;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;


class ConfigController extends Controller {

	private $configService;

	public function __construct(string $appName, IRequest $request, ConfigService $configService) {
		parent::__construct($appName, $request);

		$this->configService = $configService;
	}

	/**
	 * @param string $cloudAddress
	 * @return DataResponse
	 */
	public function setCloudAddress(string $cloudAddress): DataResponse {
		try {
			$this->configService->setCloudAddress($cloudAddress);
			return new DataResponse([]);
		} catch (SocialAppConfigException $e) {
			return new DataResponse([
				'message' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		}
	}
}