<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Tests\Api\Schema\SchemaValidator;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;

/**
 * One answer of the API, as a client receives it.
 */
final class ApiResponse {
	public readonly int $status;
	/** the body as a client decodes it: JSON objects are `stdClass`, arrays are lists */
	public readonly mixed $body;
	/** @var array<string, string> */
	public readonly array $headers;
	/** the body as it goes onto the wire, for a JSON, RSS or other data response */
	public readonly string $raw;
	/** the template a page is drawn from, or '' for anything that is not a page */
	public readonly string $template;

	public function __construct(Response $response) {
		$this->status = $response->getStatus();
		$this->headers = $response->getHeaders();

		$data = match (true) {
			$response instanceof DataResponse => $response->getData(),
			$response instanceof JSONResponse => $response->getData(),
			default => null,
		};
		$this->body = SchemaValidator::wire($data);
		$this->raw = match (true) {
			$response instanceof DataResponse => (string)json_encode($data),
			$response instanceof JSONResponse, $response instanceof DataDisplayResponse => $response->render(),
			default => '',
		};
		$this->template = ($response instanceof TemplateResponse) ? $response->getTemplateName() : '';
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
