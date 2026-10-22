<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Psr\Log\LoggerInterface;

/** Reads and validates one page of a remote ActivityPub outbox. */
class RemoteOutboxPageReader {
	public function __construct(
		private CurlService $curlService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{items: array, pageUrl: string, nextUrl: string, success: bool}
	 */
	public function read(Person $actor, string $pageUrl = ''): array {
		$result = ['items' => [], 'pageUrl' => '', 'nextUrl' => '', 'success' => false];
		$outboxUrl = $actor->getOutbox();
		if ($actor->isLocal() || $outboxUrl === '') {
			return $result;
		}

		try {
			$pageUrl = $pageUrl !== '' ? $pageUrl : $outboxUrl;
			if (!$this->isOutboxPageUrl($pageUrl, $outboxUrl)) {
				return $result;
			}

			$this->logger->debug('[RemoteOutboxPageReader] Fetching outbox page', [
				'url' => $pageUrl,
				'actor' => $actor->getId(),
			]);
			$outboxData = $this->curlService->retrieveObject($pageUrl);
			$pageData = $outboxData;

			if (isset($outboxData['first'])) {
				if (is_array($outboxData['first'])) {
					$pageData = $outboxData['first'];
				} elseif (is_string($outboxData['first'])) {
					$firstPage = $this->resolvePageUrl($outboxData['first'], $pageUrl);
					if ($firstPage === '' || !$this->isOutboxPageUrl($firstPage, $outboxUrl)) {
						return $result;
					}
					$this->logger->debug('[RemoteOutboxPageReader] Following first page', ['url' => $firstPage]);
					$pageUrl = $firstPage;
					$pageData = $this->curlService->retrieveObject($pageUrl);
				}
			}

			$items = $pageData['orderedItems'] ?? $pageData['items'] ?? [];
			if (!is_array($items)) {
				$items = [];
			}

			$nextUrl = $this->collectionPageLink($pageData['next'] ?? null);
			if ($nextUrl !== '') {
				$nextUrl = $this->resolvePageUrl($nextUrl, $pageUrl);
				if ($nextUrl === '' || !$this->isOutboxPageUrl($nextUrl, $outboxUrl)) {
					$this->logger->warning('[RemoteOutboxPageReader] Rejected an outbox page outside the actor server', [
						'actor' => $actor->getId(),
					]);
					$nextUrl = '';
				}
			}

			$result = ['items' => $items, 'pageUrl' => $pageUrl, 'nextUrl' => $nextUrl, 'success' => true];
		} catch (Exception $e) {
			$this->logger->warning('[RemoteOutboxPageReader] Failed to fetch outbox', [
				'actor' => $actor->getId(),
				'error' => $e->getMessage(),
			]);
		}

		return $result;
	}

	private function collectionPageLink(mixed $link): string {
		if (is_string($link)) {
			return trim($link);
		}
		if (is_array($link)) {
			$id = $link['id'] ?? $link['@id'] ?? '';
			return is_string($id) ? trim($id) : '';
		}

		return '';
	}

	private function resolvePageUrl(string $link, string $baseUrl): string {
		if (preg_match('#^https?://#i', $link) === 1) {
			return $link;
		}
		$base = parse_url($baseUrl);
		if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
			return '';
		}
		$origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
		if (str_starts_with($link, '//')) {
			return $base['scheme'] . ':' . $link;
		}
		if (str_starts_with($link, '/')) {
			return $origin . $link;
		}
		if (str_starts_with($link, '?')) {
			return $origin . ($base['path'] ?? '/') . $link;
		}

		$path = (string)($base['path'] ?? '/');
		$directory = substr($path, 0, (int)strrpos($path, '/') + 1);
		return $origin . $directory . $link;
	}

	private function isOutboxPageUrl(string $url, string $outboxUrl): bool {
		$page = parse_url($url);
		$outbox = parse_url($outboxUrl);
		if (!is_array($page) || !is_array($outbox)) {
			return false;
		}
		$scheme = strtolower((string)($outbox['scheme'] ?? ''));
		$pageScheme = strtolower((string)($page['scheme'] ?? ''));
		$defaultPort = $scheme === 'https' ? 443 : 80;
		$pagePort = (int)($page['port'] ?? ($pageScheme === 'https' ? 443 : 80));
		$outboxPort = (int)($outbox['port'] ?? $defaultPort);

		return in_array($scheme, ['http', 'https'], true)
			&& $pageScheme === $scheme
			&& strtolower((string)($page['host'] ?? '')) === strtolower((string)($outbox['host'] ?? ''))
			&& $pagePort === $outboxPort;
	}
}
