<?php

namespace OCA\Social\Service\ActivityPub;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Status;
use OCP\IRequest;

class TagManager {
	private IRequest $request;

	public function __construct(IRequest $request) {
		$this->request = $request;
	}

	/**
	 * @template T
	 * @param class-string<T> $className
	 * @return ?T
	 */
	public function uriToResource(string $uri, string $className): object {
		if ($this->isLocalUri($uri)) {
			// Find resource but from the DB
			switch ($className) {
				case Account::class:
					return null; // TODO

				case Status::class:
					return null; // TODO

				return null;
			}
		} else {
			// Find remote resource
		}
	}

	public function isLocalUri(?string $uri) {
		if ($uri === null) {
			return false;
		}

		$parsedUrl = parse_url($uri);
		if (!isset($parsedUrl['host'])) {
			return false;
		}
		$host = $parsedUrl['host'];
		if (isset($parsedUrl['port'])) {
			$host = $host . ':' . $parsedUrl['port'];
		}
		return $host === $this->request->getServerHost();
	}
}
