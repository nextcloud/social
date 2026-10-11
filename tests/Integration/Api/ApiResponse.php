<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Tests\Api\Schema\SchemaValidator;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;

/**
 * One answer of the API, as a client receives it.
 */
final class ApiResponse {
	public readonly int $status;
	/** the body as a client decodes it: JSON objects are `stdClass`, arrays are lists */
	public readonly mixed $body;
	/** @var array<string, string> */
	public readonly array $headers;

	public function __construct(Response $response) {
		$this->status = $response->getStatus();
		$this->headers = $response->getHeaders();

		$data = match (true) {
			$response instanceof DataResponse => $response->getData(),
			$response instanceof JSONResponse => $response->getData(),
			default => null,
		};
		$this->body = SchemaValidator::wire($data);
	}

	/**
	 * The `Link` header, by relation.
	 *
	 * @return array<string, array{url: string, query: array<string, string>}>
	 */
	public function links(): array {
		$links = [];
		foreach (explode(',', $this->headers['Link'] ?? '') as $part) {
			if (preg_match('/<([^>]+)>\s*;\s*rel="([^"]+)"/', $part, $m) !== 1) {
				continue;
			}

			$query = [];
			parse_str((string)parse_url($m[1], PHP_URL_QUERY), $query);
			$links[$m[2]] = ['url' => $m[1], 'query' => $query];
		}

		return $links;
	}
}
