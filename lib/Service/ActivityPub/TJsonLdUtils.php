<?php

namespace OCA\Social\Service\ActivityPub;

use OCA\Social\Entity\Account;
use OCA\Social\Tools\Model\NCRequest;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Service responsible for fetching and caching JSON-Ld activity stream
 */
class JsonLdService {
	private IClient $client;
	private ICache $jsonLdCache;

	public function __construct(ICacheFactory $cacheFactory, IClientService $clientService) {
		$this->client = $clientService->newClient();
		$this->jsonLdCache = $cacheFactory->createLocal('social.jsonld');
	}

	public function fetchResource(string $uri, bool $id, ?Account $onBehalfOf = null) {
		if (!$id) {
			$json = $this->fetchResourceWithoutIdValidation($uri, $onBehalfOf);
		}
	}

	private function fetchResourceWithoutIdValidation(string $uri, ?Account $onBehalfOf): array {

		$this->client->get($uri, [
			'header' => [
				'Accept' => 'application/activity+json, application/ld+json',
			],
		]);
	}

	public function onBehalfOf(Account $account, $keyIdFormat, string $signWith): array {
		return [];
	}

	private function buildRequest(?Account $onBehalfOf): SignedRequest {
		$request = new SignedRequest();
		$request->setOnBehalfOf($onBehalfOf);
		$request->addHeader('Accept', 'application/activity+json, application/ld+json');
		return $request;
	}
}
