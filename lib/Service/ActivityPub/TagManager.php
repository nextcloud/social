<?php

namespace OCA\Social\Service\ActivityPub;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Status;
use OCA\Social\InstanceUtils;
use OCP\IRequest;

final class TagManager {
	private IRequest $request;
	static private ?TagManager $instance = null;

	public static function getInstance(): self {
		if (self::$instance === null) {
			self::$instance = new TagManager(\OCP\Server::get(IRequest::class));
		}

		return self::$instance;
	}

	private function __construct(IRequest $request) {
		$this->request = $request;
	}

	private function __clone() {
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

	public function uriFor(object $target): string {
		if ($target->getUri()) {
			return $target->getUri();
		}

		$instanceUtils = \OCP\Server::get(InstanceUtils::class);

		if ($target instanceof Status) {
			if ($target->isReblog()) {
				// todo
			}
			return $instanceUtils->getLocalInstanceUrl() . '/users/' . $target->getAccount()->getUserName() . '/statues/' . $target->getId();
		}
	}

	public function urlFor(object $target): string {
		if ($target->getUrl()) {
			return $target->getUrl();
		}

		$instanceUtils = \OCP\Server::get(InstanceUtils::class);

		if ($target instanceof Status) {
			if ($target->isReblog()) {
				// todo
			}
			return $instanceUtils->getLocalInstanceUrl() . '/@' . $target->account->getUserName() . '/' . $target->getId();
		}
	}

	public function __wakeup() {
		throw new \Exception("Cannot unserialize singleton");
	}
}
