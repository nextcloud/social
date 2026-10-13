<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCP\IConfig;
use Throwable;

/**
 * XRPC calls to another PDS, the one an account moves to or from: JSON in
 * and out, or bytes in (a repository, a blob). The other PDS is the person's
 * choice and gets the same care as any address a user types: https, and
 * every request goes out through `CurlService`, with its refusal of local
 * addresses — unless the server is one set up to talk to local ones, as a
 * development network is.
 */
class PdsClient {
	public function __construct(
		private CurlService $curl,
		private IConfig $systemConfig,
	) {
	}

	/**
	 * A PDS address as typed, made its origin.
	 *
	 * @throws AtprotoException when it is not one this server will talk to
	 */
	public function origin(string $typed): string {
		$typed = trim($typed);
		if (!preg_match('#^[a-z]+://#i', $typed)) {
			$typed = 'https://' . $typed;
		}
		$url = parse_url($typed);
		$scheme = strtolower($url['scheme'] ?? '');
		$host = strtolower($url['host'] ?? '');
		$local = $this->systemConfig->getSystemValueBool('allow_local_remote_servers', false);
		if ($host === '' || isset($url['user']) || !($scheme === 'https' || ($scheme === 'http' && $local))) {
			throw new AtprotoException('That is not the https address of a PDS');
		}

		return $scheme . '://' . $host . (isset($url['port']) ? ':' . $url['port'] : '');
	}

	/**
	 * @param array<string, scalar> $query
	 * @param array|null $json the JSON body of a procedure
	 * @param resource|string|null $bytes a raw body, with its type
	 * @return array{status: int, body: array}
	 * @throws AtprotoException when the PDS cannot be reached
	 */
	public function call(string $pds, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
		$headers = ['Accept' => 'application/json'];
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$options = ['headers' => $headers, 'timeout' => 600, 'json_headers' => false, 'accept_errors' => true];
		if ($json !== null) {
			$options['headers']['Content-Type'] = 'application/json';
			// a procedure's input is an object, and an empty PHP array encodes as []
			$options['body'] = (string)json_encode($json === [] ? (object)[] : $json, JSON_UNESCAPED_SLASHES);
		} elseif ($bytes !== null) {
			$options['headers']['Content-Type'] = $type;
			$options['body'] = $bytes;
		}
		$url = $pds . '/xrpc/' . $method . ($query === [] ? '' : '?' . http_build_query($query));
		$status = 0;
		try {
			$body = json_decode($this->curl->doRequest($verb === 'GET' ? 'get' : 'post', $url, $options, $contentType, $status), true);
		} catch (Throwable $e) {
			throw new AtprotoException('The PDS at ' . $pds . ' could not be reached: ' . $e->getMessage(), 0, $e);
		}

		return ['status' => (int)$status, 'body' => is_array($body) ? $body : []];
	}

	/**
	 * A query whose answer is bytes — a repository, a blob — with its type.
	 *
	 * @return array{status: int, bytes: string, type: string}
	 * @throws AtprotoException when the PDS cannot be reached
	 */
	public function bytes(string $pds, string $method, array $query, string $token = ''): array {
		$headers = $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];
		try {
			// a stream rather than a document: a repository is not held to the
			// download ceiling
			$answer = $this->curl->openStream($pds . '/xrpc/' . $method . '?' . http_build_query($query), $headers, '', 600);
		} catch (RequestContentException $e) {
			return ['status' => $e->getCode(), 'bytes' => '', 'type' => ''];
		} catch (Throwable $e) {
			throw new AtprotoException('The PDS at ' . $pds . ' could not be reached: ' . $e->getMessage(), 0, $e);
		}
		$bytes = (string)stream_get_contents($answer['stream']);
		fclose($answer['stream']);
		$type = '';
		foreach ($answer['headers'] as $name => $values) {
			if (strtolower($name) === 'content-type') {
				$type = (string)($values[0] ?? '');
			}
		}

		return ['status' => $answer['status'], 'bytes' => $bytes, 'type' => $type];
	}

	/**
	 * The same, for a call that has to succeed.
	 *
	 * @return array the answer
	 * @throws AtprotoException with the PDS's own error
	 */
	public function expect(string $pds, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
		$answer = $this->call($pds, $method, $verb, $query, $json, $bytes, $type, $token);
		if ($answer['status'] !== 200) {
			$error = trim((string)($answer['body']['error'] ?? '') . ': ' . (string)($answer['body']['message'] ?? ''), ': ');
			throw new AtprotoException($method . ' answered ' . $answer['status'] . ($error !== '' ? ' (' . $error . ')' : ''));
		}

		return $answer['body'];
	}
}
