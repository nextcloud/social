<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Model\Request;

/**
 * Trait TRequest
 *
 * @package OCA\Social\Tools\Traits
 */
trait TRequest {
	private int $maxDownloadSize = 100;
	private bool $maxDownloadSizeReached = false;

	public function setMaxDownloadSize(int $size) {
		$this->maxDownloadSize = $size;
	}


	/**
	 * @param Request $request
	 *
	 * @return array
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	public function retrieveJson(Request $request): array {
		$result = $this->doRequest($request);

		if (strpos($request->getContentType(), 'application/xrd') === 0) {
			$xml = simplexml_load_string($result);
			$result = json_encode($xml, JSON_UNESCAPED_SLASHES);
		}

		$result = json_decode((string)$result, true);
		if (is_array($result)) {
			return $result;
		}

		throw new RequestResultNotJsonException();
	}


	/**
	 * @param Request $request
	 *
	 * @return mixed
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	public function doRequest(Request $request) {
		$this->maxDownloadSizeReached = false;

		$ignoreProtocolOnErrors = [7];
		$result = '';
		foreach ($request->getProtocols() as $protocol) {
			$request->setUsedProtocol($protocol);

			$curl = $this->initRequest($request);

			$this->initRequestGet($request);
			$this->initRequestPost($curl, $request);
			$this->initRequestPut($curl, $request);
			$this->initRequestDelete($curl, $request);

			$this->initRequestHeaders($curl, $request);

			$result = curl_exec($curl);

			if (in_array(curl_errno($curl), $ignoreProtocolOnErrors)) {
				continue;
			}

			if ($this->maxDownloadSizeReached === true) {
				throw new RequestResultSizeException();
			}

			$this->parseRequestResult($curl, $request);
			break;
		}

		return $result;
	}


	/**
	 * @param Request $request
	 *
	 * @return resource
	 */
	private function initRequest(Request $request) {
		$curl = $this->generateCurlRequest($request);

		curl_setopt($curl, CURLOPT_USERAGENT, $request->getUserAgent());
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $request->getTimeout());
		curl_setopt($curl, CURLOPT_TIMEOUT, $request->getTimeout());

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_BINARYTRANSFER, $request->isBinary());

		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $request->isVerifyPeer());
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, $request->isFollowLocation());

		curl_setopt($curl, CURLOPT_BUFFERSIZE, 128);
		curl_setopt($curl, CURLOPT_NOPROGRESS, false);
		curl_setopt(
			/**
			 * @param $downloadSize
			 * @param int $downloaded
			 * @param $uploadSize
			 * @param int $uploaded
			 *
			 * @return int
			 */
			$curl, CURLOPT_PROGRESSFUNCTION,
			function ($downloadSize, int $downloaded, $uploadSize, int $uploaded) {
				if ($downloaded > ($this->maxDownloadSize * (1024 * 1024))) {
					$this->maxDownloadSizeReached = true;

					return 1;
				}

				return 0;
			}
		);

		return $curl;
	}


	/**
	 * @param Request $request
	 *
	 * @return resource
	 */
	private function generateCurlRequest(Request $request) {
		$port = ($request->getPort() > 0) ? ':' . $request->getPort() : '';
		$url =
			$request->getUsedProtocol() . '://' . $request->getHost() . $port . $request->getParsedUrl();
		if ($request->getType() !== Request::TYPE_GET) {
			$curl = curl_init($url);
		} else {
			$curl = curl_init($url . '?' . $request->getUrlData());
		}

		return $curl;
	}


	/**
	 * @param Request $request
	 */
	private function initRequestGet(Request $request) {
		if ($request->getType() !== Request::TYPE_GET) {
			return;
		}
	}


	/**
	 * @param resource $curl
	 * @param Request $request
	 */
	private function initRequestPost($curl, Request $request) {
		if ($request->getType() !== Request::TYPE_POST) {
			return;
		}

		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $request->getDataBody());
	}

	/**
	 * @param resource $curl
	 */
	private function initRequestPut($curl, Request $request) {
		if ($request->getType() !== Request::TYPE_PUT) {
			return;
		}

		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
		curl_setopt($curl, CURLOPT_POSTFIELDS, $request->getDataBody());
	}

	/**
	 * @param resource $curl
	 */
	private function initRequestDelete($curl, Request $request) {
		if ($request->getType() !== Request::TYPE_DELETE) {
			return;
		}

		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
		curl_setopt($curl, CURLOPT_POSTFIELDS, $request->getDataBody());
	}

	/**
	 * @param resource $curl
	 */
	private function initRequestHeaders($curl, Request $request) {
		$headers = $request->getHeaders();

		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	}


	/**
	 * @param resource $curl
	 * @param Request $request
	 *
	 * @throws RequestContentException
	 * @throws RequestServerException
	 * @throws RequestNetworkException
	 */
	private function parseRequestResult($curl, Request $request) {
		$this->parseRequestResultCurl($curl, $request);

		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
		$request->setContentType((!is_string($contentType)) ? '' : $contentType);
		$request->setResultCode($code);

		$this->parseRequestResultCode301($code, $request);
		$this->parseRequestResultCode4xx($code, $request);
		$this->parseRequestResultCode5xx($code, $request);
	}


	/**
	 * @param resource $curl
	 * @param Request $request
	 *
	 * @throws RequestNetworkException
	 */
	private function parseRequestResultCurl($curl, Request $request) {
		$errno = curl_errno($curl);
		if ($errno > 0) {
			throw new RequestNetworkException(
				$errno . ' - ' . curl_error($curl) . ' - ' . json_encode(
					$request, JSON_UNESCAPED_SLASHES
				), $errno
			);
		}
	}


	/**
	 * @param int $code
	 * @param Request $request
	 *
	 * @throws RequestContentException
	 */
	private function parseRequestResultCode301(int $code, Request $request) {
		if ($code === 301) {
			throw new RequestContentException(
				'301 - ' . json_encode($request, JSON_UNESCAPED_SLASHES)
			);
		}
	}


	/**
	 * @param int $code
	 * @param Request $request
	 *
	 * @throws RequestContentException
	 */
	private function parseRequestResultCode4xx(int $code, Request $request) {
		if ($code === 404 || $code === 410) {
			throw new RequestContentException(
				$code . ' - ' . json_encode($request, JSON_UNESCAPED_SLASHES)
			);
		}
	}


	/**
	 * @param int $code
	 * @param Request $request
	 *
	 * @throws RequestServerException
	 */
	private function parseRequestResultCode5xx(int $code, Request $request) {
		if ($code === 500) {
			throw new RequestServerException(
				$code . ' - ' . json_encode($request, JSON_UNESCAPED_SLASHES)
			);
		}
	}
}
