<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

use Exception;
use JsonSerializable;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use Psr\Log\LoggerInterface;

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
		bool $log = true,
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
			\OCP\Server::get(LoggerInterface::class)->warning($status . ' - ' . json_encode($data));
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
