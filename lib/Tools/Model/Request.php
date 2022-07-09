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


namespace OCA\Social\Tools\Model;

use OCA\Social\Tools\Traits\TArrayTools;
use JsonSerializable;
use OCP\Http\Client\IClient;

/**
 * Class Request
 *
 * @package OCA\Social\Tools\Model
 */
class Request implements JsonSerializable {
	use TArrayTools;

	public const TYPE_GET = 0;
	public const TYPE_POST = 1;
	public const TYPE_PUT = 2;
	public const TYPE_DELETE = 3;

	public const QS_VAR_DUPLICATE = 1;
	public const QS_VAR_ARRAY = 2;

	private string $protocol = '';
	private array $protocols = ['https'];
	private string $host = '';
	private int $port = 0;
	private ?Uri $url;
	private string $baseUrl = '';
	private int $type = 0;
	private bool $binary = false;
	private bool $verifyPeer = true;
	private bool $httpErrorsAllowed = false;
	private bool $followLocation = true;
	private array $headers = [];
	private array $cookies = [];
	private array $params = [];
	private array $data = [];
	private int $queryStringType = self::QS_VAR_DUPLICATE;
	private int $timeout = 10;
	private string $userAgent = '';
	private int $resultCode = 0;
	private string $contentType = '';
	private IClient $client;

	public function __construct(?Uri $url = null, int $type = 0, bool $binary = false) {
		$this->url = $url;
		$this->type = $type;
		$this->binary = $binary;
	}


	public function setClient(IClient $client): self {
		$this->client = $client;
		return $this;
	}

	public function getClient(): IClient {
		return $this->client;
	}

	public function setProtocol(string $protocol): self {
		$this->protocol = $protocol;
		return $this;
	}

	public function getProtocol(): string {
		return $this->protocol;
	}

	public function getHost(): string {
		return $this->url->getHost();
	}

	public function setHost(string $host): self {
		$this->url->setHost($host);

		return $this;
	}

	public function getPort(): ?int {
		return $this->url->getPort();
	}

	public function setPort(?int $port): self {
		$this->url->setPort($port);

		return $this;
	}

	/**
	 * Set the instance
	 *
	 * @param string $instance The instance for example floss.social, cloud.com:442, 4u3849u3.onion
	 * @return $this
	 */
	public function setInstance(string $instance): self {
		$this->setPort(null);
		if (strpos($instance, ':') === false) {
			$this->setHost($instance);

			return $this;
		}

		[$host, $port] = explode(':', $instance, 2);
		$this->setHost($host);
		if ($port !== '') {
			$this->setPort((int)$port);
		}

		return $this;
	}

	public function getInstance(): string {
		$instance = $this->getHost();
		if ($this->getPort() !== null) {
			$instance .= ':' . $this->getPort();
		}

		return $instance;
	}

	public function parse(string $url): void {
		$this->url = new Uri($url);
	}

	public function setBaseUrl(?string $baseUrl): self {
		if ($baseUrl !== null) {
			$this->baseUrl = $baseUrl;
		}

		return $this;
	}

	public function isBinary(): bool {
		return $this->binary;
	}

	public function setVerifyPeer(bool $verifyPeer): self {
		$this->verifyPeer = $verifyPeer;

		return $this;
	}

	public function isVerifyPeer(): bool {
		return $this->verifyPeer;
	}

	public function setHttpErrorsAllowed(bool $httpErrorsAllowed): self {
		$this->httpErrorsAllowed = $httpErrorsAllowed;

		return $this;
	}

	public function isHttpErrorsAllowed(): bool {
		return $this->httpErrorsAllowed;
	}

	public function setFollowLocation(bool $followLocation): self {
		$this->followLocation = $followLocation;

		return $this;
	}

	public function isFollowLocation(): bool {
		return $this->followLocation;
	}

	public function getParametersUrl(): string {
		$url = $this->getPath();
		$ak = array_keys($this->getParams());
		foreach ($ak as $k) {
			if (!is_string($this->params[$k])) {
				continue;
			}

			$url = str_replace(':' . $k, $this->params[$k], $url);
		}

		return $url;
	}

	public function getPath(): string {
		return $this->baseUrl . $this->url;
	}

	public function getCompleteUrl(): string {
		return (string)$this->url;
	}

	public function getType(): int {
		return $this->type;
	}

	public function addHeader($key, $value): self {
		$header = $this->get($key, $this->headers);
		if ($header !== '') {
			$header .= ', ' . $value;
		} else {
			$header = $value;
		}

		$this->headers[$key] = $header;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getHeaders(): array {
		return array_merge(['User-Agent' => $this->getUserAgent()], $this->headers);
	}
	public function setHeaders(array $headers): self {
		$this->headers = $headers;

		return $this;
	}

	public function getCookies(): array {
		return $this->cookies;
	}

	public function setCookies(array $cookies): self {
		$this->cookies = $cookies;

		return $this;
	}

	public function setQueryStringType(int $queryStringType): self {
		$this->queryStringType = $queryStringType;

		return $this;
	}

	public function getQueryStringType(): int {
		return $this->queryStringType;
	}

	public function getData(): array {
		return $this->data;
	}

	public function setData(array $data): self {
		$this->data = $data;

		return $this;
	}

	public function setDataJson(string $data): self {
		$this->setData(json_decode($data, true));

		return $this;
	}

	public function setDataSerialize(JsonSerializable $data): self {
		$this->setDataJson(json_encode($data));

		return $this;
	}

	public function getParams(): array {
		return $this->params;
	}

	public function setParams(array $params): self {
		$this->params = $params;

		return $this;
	}

	public function addParam(string $k, string $v): self {
		$this->params[$k] = $v;

		return $this;
	}

	public function addParamInt(string $k, int $v): self {
		$this->params[$k] = $v;

		return $this;
	}

	public function addData(string $k, string $v): self {
		$this->data[$k] = $v;

		return $this;
	}

	public function addDataInt(string $k, int $v): self {
		$this->data[$k] = $v;

		return $this;
	}

	public function getDataBody(): string {
		return json_encode($this->getData());
	}

	public function getQueryString(): string {
		if (empty($this->getParams())) {
			return '';
		}

		switch ($this->getQueryStringType()) {
			case self::QS_VAR_ARRAY:
				return '?' . http_build_query($this->getParams());

			case self::QS_VAR_DUPLICATE:
			default:
				return '?' . preg_replace(
						'/%5B(?:[0-9]|[1-9][0-9]+)%5D=/', '=', http_build_query($this->getParams())
					);
		}
	}

	public function getTimeout(): int {
		return $this->timeout;
	}

	public function setTimeout(int $timeout): self {
		$this->timeout = $timeout;

		return $this;
	}

	public function getUserAgent(): string {
		return $this->userAgent;
	}

	public function setUserAgent(string $userAgent): self {
		$this->userAgent = $userAgent;

		return $this;
	}

	public function getResultCode(): int {
		return $this->resultCode;
	}

	public function setResultCode(int $resultCode): self {
		$this->resultCode = $resultCode;

		return $this;
	}

	public function getContentType(): string {
		return $this->contentType;
	}

	public function setContentType(string $contentType): self {
		$this->contentType = $contentType;

		return $this;
	}

	public function jsonSerialize(): array {
		return [
			'port' => $this->getPort(),
			'host' => $this->getHost(),
			'url' => $this->getPath(),
			'timeout' => $this->getTimeout(),
			'type' => $this->getType(),
			'cookies' => $this->getCookies(),
			'headers' => $this->getHeaders(),
			'params' => $this->getParams(),
			'data' => $this->getData(),
			'userAgent' => $this->getUserAgent(),
			'followLocation' => $this->isFollowLocation(),
			'verifyPeer' => $this->isVerifyPeer(),
			'binary' => $this->isBinary()
		];
	}

	public static function type(string $type): int {
		switch (strtoupper($type)) {
			case 'GET':
				return self::TYPE_GET;
			case 'POST':
				return self::TYPE_POST;
			case 'PUT':
				return self::TYPE_PUT;
			case 'DELETE':
				return self::TYPE_DELETE;
		}

		return 0;
	}

	public static function method(int $type): string {
		switch ($type) {
			case self::TYPE_GET:
				return 'get';
			case self::TYPE_POST:
				return 'post';
			case self::TYPE_PUT:
				return 'put';
			case self::TYPE_DELETE:
				return 'delete';
		}

		return '';
	}
}
