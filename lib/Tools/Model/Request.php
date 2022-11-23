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


	/** @var string */
	private $protocol = '';

	/** @var array */
	private $protocols = ['https'];

	/** @var string */
	private $host = '';

	/** @var int */
	private $port = 0;

	/** @var string */
	private $url = '';

	/** @var string */
	private $baseUrl = '';

	/** @var int */
	private $type = 0;

	/** @var bool */
	private $binary = false;

	/** @var bool */
	private $verifyPeer = true;

	/** @var bool */
	private $httpErrorsAllowed = false;

	/** @var bool */
	private $followLocation = true;

	/** @var array */
	private $headers = [];

	/** @var array */
	private $cookies = [];

	/** @var array */
	private $params = [];

	/** @var array */
	private $data = [];

	/** @var int */
	private $queryStringType = self::QS_VAR_DUPLICATE;

	/** @var int */
	private $timeout = 10;

	/** @var string */
	private $userAgent = '';

	/** @var int */
	private $resultCode = 0;

	/** @var string */
	private $contentType = '';


	/**
	 * Request constructor.
	 *
	 * @param string $url
	 * @param int $type
	 * @param bool $binary
	 */
	public function __construct(string $url = '', int $type = 0, bool $binary = false) {
		$this->url = $url;
		$this->type = $type;
		$this->binary = $binary;
	}


	/**
	 * @param string $protocol
	 *
	 * @return Request
	 */
	public function setProtocol(string $protocol): Request {
		$this->protocols = [$protocol];

		return $this;
	}

	/**
	 * @param array $protocols
	 *
	 * @return Request
	 */
	public function setProtocols(array $protocols): Request {
		$this->protocols = $protocols;

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getProtocols(): array {
		return $this->protocols;
	}

	/**
	 * @return string
	 */
	public function getUsedProtocol(): string {
		return $this->protocol;
	}

	/**
	 * @param string $protocol
	 *
	 * @return Request
	 */
	public function setUsedProtocol(string $protocol): Request {
		$this->protocol = $protocol;

		return $this;
	}


	/**
	 * @return string
	 * @deprecated - 19 - use getHost();
	 */
	public function getAddress(): string {
		return $this->getHost();
	}

	/**
	 * @param string $address
	 *
	 * @return Request
	 * @deprecated - 19 - use setHost();
	 */
	public function setAddress(string $address): Request {
		$this->setHost($address);

		return $this;
	}

	/**
	 * @return string
	 */
	public function getHost(): string {
		return $this->host;
	}

	/**
	 * @param string $host
	 *
	 * @return Request
	 */
	public function setHost(string $host): Request {
		$this->host = $host;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getPort(): int {
		return $this->port;
	}

	/**
	 * @param int $port
	 *
	 * @return Request
	 */
	public function setPort(int $port): Request {
		$this->port = $port;

		return $this;
	}


	/**
	 * @param string $instance
	 *
	 * @return Request
	 */
	public function setInstance(string $instance): Request {
		if (strpos($instance, ':') === false) {
			$this->setHost($instance);

			return $this;
		}

		list($host, $port) = explode(':', $instance, 2);
		$this->setHost($host);
		if ($port !== '') {
			$this->setPort((int)$port);
		}

		return $this;
	}


	/**
	 * @return string
	 */
	public function getInstance(): string {
		$instance = $this->getHost();
		if ($this->getPort() > 0) {
			$instance .= ':' . $this->getPort();
		}

		return $instance;
	}


	/**
	 * @param string $url
	 *
	 * @deprecated - 19 - use basedOnUrl();
	 */
	public function setAddressFromUrl(string $url) {
		$this->basedOnUrl($url);
	}

	/**
	 * @param string $url
	 */
	public function basedOnUrl(string $url) {
		$protocol = parse_url($url, PHP_URL_SCHEME);
		if ($protocol === null) {
			if (strpos($url, '/') > -1) {
				list($address, $baseUrl) = explode('/', $url, 2);
				$this->setBaseUrl('/' . $baseUrl);
			} else {
				$address = $url;
			}
			if (strpos($address, ':') > -1) {
				list($address, $port) = explode(':', $address, 2);
				$this->setPort((int)$port);
			}
			$this->setHost($address);
		} else {
			$this->setProtocols([$protocol]);
			$this->setUsedProtocol($protocol);
			$this->setHost(parse_url($url, PHP_URL_HOST));
			$this->setBaseUrl(parse_url($url, PHP_URL_PATH));
			if (is_numeric($port = parse_url($url, PHP_URL_PORT))) {
				$this->setPort($port);
			}
		}
	}

	/**
	 * @param string|null $baseUrl
	 *
	 * @return Request
	 */
	public function setBaseUrl(?string $baseUrl): Request {
		if ($baseUrl !== null) {
			$this->baseUrl = $baseUrl;
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isBinary(): bool {
		return $this->binary;
	}


	/**
	 * @param bool $verifyPeer
	 *
	 * @return $this
	 */
	public function setVerifyPeer(bool $verifyPeer): Request {
		$this->verifyPeer = $verifyPeer;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isVerifyPeer(): bool {
		return $this->verifyPeer;
	}


	/**
	 * @param bool $httpErrorsAllowed
	 *
	 * @return Request
	 */
	public function setHttpErrorsAllowed(bool $httpErrorsAllowed): Request {
		$this->httpErrorsAllowed = $httpErrorsAllowed;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isHttpErrorsAllowed(): bool {
		return $this->httpErrorsAllowed;
	}


	/**
	 * @param bool $followLocation
	 *
	 * @return $this
	 */
	public function setFollowLocation(bool $followLocation): Request {
		$this->followLocation = $followLocation;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isFollowLocation(): bool {
		return $this->followLocation;
	}


	/**
	 * @return string
	 * @deprecated - 19 - use getParametersUrl() + addParam()
	 */
	public function getParsedUrl(): string {
		$url = $this->getPath();
		$ak = array_keys($this->getData());
		foreach ($ak as $k) {
			if (!is_string($this->data[$k])) {
				continue;
			}

			$url = str_replace(':' . $k, $this->data[$k], $url);
		}

		return $url;
	}

	/**
	 * @return string
	 */
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


	/**
	 * @return string
	 */
	public function getPath(): string {
		return $this->baseUrl . $this->url;
	}


	/**
	 * @return string
	 * @deprecated - 19 - use getPath()
	 */
	public function getUrl(): string {
		return $this->getPath();
	}


	/**
	 * @return string
	 */
	public function getCompleteUrl(): string {
		$port = ($this->getPort() > 0) ? ':' . $this->getPort() : '';

		return $this->getUsedProtocol() . '://' . $this->getHost() . $port . $this->getParametersUrl();
	}


	/**
	 * @return int
	 */
	public function getType(): int {
		return $this->type;
	}


	public function addHeader($key, $value): Request {
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
		return array_merge(['user-agent' => $this->getUserAgent()], $this->headers);
	}

	/**
	 * @param array $headers
	 *
	 * @return Request
	 */
	public function setHeaders(array $headers): Request {
		$this->headers = $headers;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getCookies(): array {
		return $this->cookies;
	}

	/**
	 * @param array $cookies
	 *
	 * @return Request
	 */
	public function setCookies(array $cookies): Request {
		$this->cookies = $cookies;

		return $this;
	}


	/**
	 * @param int $queryStringType
	 *
	 * @return Request
	 */
	public function setQueryStringType(int $queryStringType): self {
		$this->queryStringType = $queryStringType;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getQueryStringType(): int {
		return $this->queryStringType;
	}


	/**
	 * @return array
	 */
	public function getData(): array {
		return $this->data;
	}


	/**
	 * @param array $data
	 *
	 * @return Request
	 */
	public function setData(array $data): Request {
		$this->data = $data;

		return $this;
	}


	/**
	 * @param string $data
	 *
	 * @return Request
	 */
	public function setDataJson(string $data): Request {
		$this->setData(json_decode($data, true));

		return $this;
	}


	/**
	 * @param JsonSerializable $data
	 *
	 * @return Request
	 */
	public function setDataSerialize(JsonSerializable $data): Request {
		$this->setDataJson(json_encode($data));

		return $this;
	}


	/**
	 * @return array
	 */
	public function getParams(): array {
		return $this->params;
	}

	/**
	 * @param array $params
	 *
	 * @return Request
	 */
	public function setParams(array $params): Request {
		$this->params = $params;

		return $this;
	}


	/**
	 * @param string $k
	 * @param string $v
	 *
	 * @return Request
	 */
	public function addParam(string $k, string $v): Request {
		$this->params[$k] = $v;

		return $this;
	}


	/**
	 * @param string $k
	 * @param int $v
	 *
	 * @return Request
	 */
	public function addParamInt(string $k, int $v): Request {
		$this->params[$k] = $v;

		return $this;
	}


	/**
	 * @param string $k
	 * @param string $v
	 *
	 * @return Request
	 */
	public function addData(string $k, string $v): Request {
		$this->data[$k] = $v;

		return $this;
	}


	/**
	 * @param string $k
	 * @param int $v
	 *
	 * @return Request
	 */
	public function addDataInt(string $k, int $v): Request {
		$this->data[$k] = $v;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getDataBody(): string {
		return json_encode($this->getData());
	}

	/**
	 * @return string
	 * @deprecated - 19 - use getUrlParams();
	 */
	public function getUrlData(): string {
		if ($this->getData() === []) {
			return '';
		}

		return preg_replace(
			'/([(%5B)]{1})[0-9]+([(%5D)]{1})/', '$1$2', http_build_query($this->getData())
		);
	}

	/**
	 * @return string
	 * @deprecated - 21 - use getQueryString();
	 */
	public function getUrlParams(): string {
		if ($this->getParams() === []) {
			return '';
		}

		return preg_replace(
			'/([(%5B)]{1})[0-9]+([(%5D)]{1})/', '$1$2', http_build_query($this->getParams())
		);
	}


	/**
	 * @param int $type
	 *
	 * @return string
	 */
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


	/**
	 * @return int
	 */
	public function getTimeout(): int {
		return $this->timeout;
	}

	/**
	 * @param int $timeout
	 *
	 * @return Request
	 */
	public function setTimeout(int $timeout): Request {
		$this->timeout = $timeout;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getUserAgent(): string {
		return $this->userAgent;
	}

	/**
	 * @param string $userAgent
	 *
	 * @return Request
	 */
	public function setUserAgent(string $userAgent): Request {
		$this->userAgent = $userAgent;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getResultCode(): int {
		return $this->resultCode;
	}

	/**
	 * @param int $resultCode
	 *
	 * @return Request
	 */
	public function setResultCode(int $resultCode): Request {
		$this->resultCode = $resultCode;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getContentType(): string {
		return $this->contentType;
	}

	/**
	 * @param string $contentType
	 *
	 * @return Request
	 */
	public function setContentType(string $contentType): Request {
		$this->contentType = $contentType;

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'protocols' => $this->getProtocols(),
			'used_protocol' => $this->getUsedProtocol(),
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


	/**
	 * @param string $type
	 *
	 * @return int
	 */
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
