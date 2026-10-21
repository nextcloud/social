<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Sends Web Push requests through Social's pinned-address HTTP safeguards. */
class SafeWebPushClient implements ClientInterface {
	public function __construct(
		private CurlService $curl,
	) {
	}

	#[\Override]
	public function sendRequest(RequestInterface $request): ResponseInterface {
		$uri = (string)$request->getUri();
		$parts = parse_url($uri);
		if (strtoupper($request->getMethod()) !== 'POST'
			|| !is_array($parts)
			|| strtolower($parts['scheme'] ?? '') !== 'https'
			|| empty($parts['host'])
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['fragment'])
			|| strlen($uri) > 2048) {
			throw new WebPushNetworkException('The Web Push request target is invalid', $request);
		}

		$headers = [];
		foreach ($request->getHeaders() as $name => $values) {
			$headers[$name] = implode(', ', $values);
		}

		$contentType = null;
		$statusCode = null;
		try {
			$body = $this->curl->doRequest(
				'post',
				$uri,
				[
					'headers' => $headers,
					'body' => (string)$request->getBody(),
					'timeout' => 10,
					'max_redirects' => 0,
					'federation' => false,
					'json_headers' => false,
					'accept_errors' => true,
				],
				$contentType,
				$statusCode,
			);
		} catch (Throwable $e) {
			throw new WebPushNetworkException('The Web Push endpoint could not be reached', $request, $e);
		}

		return new Response($statusCode ?? 502, $contentType === null ? [] : ['Content-Type' => $contentType], $body);
	}
}
