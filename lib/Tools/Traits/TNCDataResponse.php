<?php

declare(strict_types=1);


/**
 * Some tools for myself.
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


namespace OCA\Social\Tools\Traits;

use Exception;
use JsonSerializable;
use OC;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * Trait TNCDataResponse
 *
 * @deprecated - 19
 * @package OCA\Social\Tools\Traits
 */
trait TNCDataResponse {
	/**
	 * @return DataResponse
	 */
	protected function fail(
		Exception $e, array $more = [], int $status = Http::STATUS_INTERNAL_SERVER_ERROR,
		bool $log = true
	): DataResponse {
		$data = array_merge(
			$more,
			[
				'status' => -1,
				'exception' => get_class($e),
				'message' => $e->getMessage()
			]
		);

		if ($log) {
			OC::$server->getLogger()
					   ->log(2, $status . ' - ' . json_encode($data));
		}

		return new DataResponse($data, $status);
	}


	/**
	 * @param array $result
	 * @param array $more
	 *
	 * @return DataResponse
	 */
	protected function success(array $result = [], array $more = []): DataResponse {
		$data = array_merge(
			$more,
			[
				'result' => $result,
				'status' => 1
			]
		);

		return new DataResponse($data, Http::STATUS_OK);
	}
	protected function directSuccess(JsonSerializable $result): DataResponse {
		return new DataResponse($result, Http::STATUS_OK);
	}
}
