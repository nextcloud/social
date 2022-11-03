<?php

declare(strict_types=1);

/**
 * @copyright 2022 Maxence Lange <maxence@artificial-owl.com>
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
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

namespace OCA\Social\WellKnown;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\Http\WellKnown\IResponse;

final class XrdResponse implements IResponse {
	private ?string $expires = null;
	private int $httpCode;

	/** @var mixed[] */
	private array $links = [];

	public function __construct(int $httpCode = Http::STATUS_OK) {
		$this->httpCode = $httpCode;
	}

	/**
	 * @param string $expires
	 *
	 * @return $this
	 *
	 * @since 21.0.0
	 */
	public function setExpires(string $expires): self {
		$this->expires = $expires;

		return $this;
	}


	public function setHttpCode(int $httpCode): self {
		$this->httpCode = $httpCode;

		return $this;
	}

	/**
	 * Add a link
	 *
	 * @param string $rel
	 * @param string $template
	 *
	 * @return XrdResponse
	 */
	public function addLink(string $rel, string $template): self {
		$this->links[] = [
			'rel' => $rel,
			'template' => $template
		];

		return $this;
	}


	public function toHttpResponse(): Response {
		$data = [];
		$data[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$data[] = '<XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0">';

		foreach ($this->links as $link) {
			$data[] = '  <Link rel="' . $link['rel'] . '"  template="' . $link['template'] . '"/>';
		}

		$data[] = '</XRD>';

		$response = new TextPlainResponse(implode("\n", $data) . "\n", $this->httpCode);
		$response->addHeader('Content-Type', 'application/xrd+xml'); // overwrite default header

		return $response;
	}
}
