<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

class WebPushNetworkException extends RuntimeException implements NetworkExceptionInterface {
	public function __construct(
		string $message,
		private RequestInterface $request,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	#[\Override]
	public function getRequest(): RequestInterface {
		return $this->request;
	}
}
