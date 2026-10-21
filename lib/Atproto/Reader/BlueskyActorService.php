<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Details;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\VerificationService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bluesky accounts as cached actors: resolved through the public AppView by
 * handle or DID on first need, refreshed the way any remote actor is, and
 * read from the cache like any other the rest of the time.
 */
class BlueskyActorService {
	/** @var array<string, string> DID to validated PDS endpoint for this request */
	private array $pdsEndpoints = [];
	public function __construct(
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PlcClient $plc,
		private ActorMapper $mapper,
		private CacheActorsRequest $cacheActorsRequest,
		private ActorService $actorService,
		private Blocklist $blocklist,
		private LoggerInterface $logger,
		private ?ContainerInterface $container = null,
		private ?CurlService $curlService = null,
	) {
	}

	/**
	 * The cached actor of a Bluesky handle or DID, fetched from the AppView
	 * when it is not here yet or a refresh is asked for.
	 *
	 * @throws CacheActorDoesNotExistException when Bluesky is off or the AppView does not know the account
	 * @throws AtprotoException when the AppView cannot be reached
	 */
	public function resolve(string $handleOrDid, bool $refresh = false): Person {
		if (!$this->config->isEnabled()) {
			throw new CacheActorDoesNotExistException();
		}
		$handleOrDid = strtolower(trim($handleOrDid));
		if (Syntax::isDid($handleOrDid) && $this->blocklist->isBlockedDid($handleOrDid)) {
			throw new CacheActorDoesNotExistException('blocked here: ' . $handleOrDid);
		}
		if (!$refresh) {
			$cached = $this->cached($handleOrDid);
			if ($cached !== null) {
				if ($this->blocklist->isBlockedActor($cached)) {
					throw new CacheActorDoesNotExistException('blocked here: ' . $handleOrDid);
				}

				return $cached;
			}
		}
		try {
			$profile = $this->appView->query('app.bsky.actor.getProfile', ['actor' => $handleOrDid]);
		} catch (AppViewNotFoundException $e) {
			throw new CacheActorDoesNotExistException($e->getMessage());
		}
		$did = (string)($profile['did'] ?? '');
		if (!Syntax::isDid($did)) {
			throw new CacheActorDoesNotExistException('the AppView answered no DID for ' . $handleOrDid);
		}
		$pds = $this->pdsOf($did);
		if ($this->blocklist->isBlockedAccount($did, $pds)) {
			throw new CacheActorDoesNotExistException('blocked here: ' . $did);
		}
		$person = $this->mapper->person($profile, $pds);
		$this->store($person);
		$this->pins()?->keep($person, (string)($profile['pinnedPost']['uri'] ?? ''));

		return $person;
	}

	/**
	 * Resolved when first needed rather than injected: the pins need the
	 * post store, which needs this service. Null without a container, as in
	 * a unit test.
	 */
	private function pins(): ?BlueskyPins {
		$service = $this->container?->get(BlueskyPins::class);

		return $service instanceof BlueskyPins ? $service : null;
	}

	/**
	 * Only what is in the cache: the actor by handle or DID, or null.
	 */
	public function cached(string $handleOrDid): ?Person {
		try {
			if (Syntax::isDid($handleOrDid)) {
				return $this->cacheActorsRequest->getFromId(BlueskyIds::actorId($handleOrDid));
			}

			return $this->cacheActorsRequest->getFromAccount($handleOrDid);
		} catch (CacheActorDoesNotExistException) {
			return null;
		}
	}

	/**
	 * Whether a cached actor is a Bluesky account.
	 */
	public static function isBluesky(Person $actor): bool {
		return BlueskyIds::isActorId($actor->getId());
	}

	/**
	 * Whether the account's posts are kept out: its labels say `!hide` or a
	 * takedown, or Bluesky no longer serves it (`AccountStatus`).
	 */
	public static function isLimited(Person $actor): bool {
		return (bool)($actor->getDetails(ActorMapper::DETAIL)['limited'] ?? false);
	}

	/**
	 * What Bluesky last said of the account when it stopped serving it —
	 * `deactivated`, `suspended` — or '' while it is active.
	 */
	public static function status(Person $actor): string {
		return (string)($actor->getDetails(ActorMapper::DETAIL)['status'] ?? '');
	}

	private function pdsOf(string $did): string {
		if (!Syntax::isDidPlc($did) && !str_starts_with($did, 'did:web:')) {
			return '';
		}
		if (isset($this->pdsEndpoints[$did])) {
			return $this->pdsEndpoints[$did];
		}
		try {
			$document = Syntax::isDidPlc($did) ? $this->plc->document($did) : $this->webDidDocument($did);
		} catch (Throwable $e) {
			$this->logger->notice('DID document not read', ['did' => $did, 'exception' => $e]);

			return '';
		}
		foreach (($document['service'] ?? []) as $service) {
			if (is_array($service) && ($service['id'] ?? '') === '#atproto_pds') {
				$endpoint = self::validatedPdsEndpoint((string)($service['serviceEndpoint'] ?? ''));
				$this->pdsEndpoints[$did] = $endpoint;

				return $endpoint;
			}
		}
		$this->pdsEndpoints[$did] = '';

		return '';
	}

	/** Reads the HTTPS DID document of a did:web account through Social's guarded HTTP client. */
	private function webDidDocument(string $did): ?array {
		if ($this->curlService === null) {
			return null;
		}
		$parts = explode(':', substr($did, strlen('did:web:')));
		$host = rawurldecode((string)array_shift($parts));
		$hostParts = parse_url('https://' . $host);
		$hostName = is_array($hostParts) ? strtolower($hostParts['host'] ?? '') : '';
		if (!is_array($hostParts) || $hostName === ''
			|| isset($hostParts['user']) || isset($hostParts['pass']) || isset($hostParts['path'])
			|| isset($hostParts['query']) || isset($hostParts['fragment'])
			|| filter_var(trim($hostName, '[]'), FILTER_VALIDATE_IP) !== false
			|| preg_match('/(?:^|\.)(?:localhost|local)$/i', $hostName) === 1) {
			return null;
		}
		$path = [];
		foreach ($parts as $part) {
			$part = rawurldecode($part);
			if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '/') || str_contains($part, '\\')) {
				return null;
			}
			$path[] = rawurlencode($part);
		}
		$origin = 'https://' . $hostName . (isset($hostParts['port']) ? ':' . $hostParts['port'] : '');
		$url = $origin . ($path === []
			? '/.well-known/did.json'
			: '/' . implode('/', $path) . '/did.json');
		$status = 0;
		try {
			$body = $this->curlService->doRequest('get', $url, [
				'timeout' => 5,
				'json_headers' => false,
				'headers' => ['Accept' => 'application/did+json, application/json'],
			], $contentType, $status);
		} catch (Throwable $e) {
			$this->logger->debug('did:web document not read', ['did' => $did, 'exception' => $e]);

			return null;
		}
		$document = $status >= 200 && $status < 300 ? json_decode($body, true) : null;

		return is_array($document) && ($document['id'] ?? '') === $did ? $document : null;
	}

	/** The public PDS endpoint for a DID, or '' when it cannot be resolved safely. */
	public function pdsEndpoint(string $did): string {
		return $this->pdsOf($did);
	}

	private static function validatedPdsEndpoint(string $endpoint): string {
		$parts = parse_url($endpoint);
		if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
			|| !is_string($parts['host'] ?? null) || $parts['host'] === ''
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
			|| !in_array($parts['path'] ?? '', ['', '/'], true)) {
			return '';
		}
		$host = strtolower($parts['host']);
		if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false
			|| preg_match('/(?:^|\.)localhost$/', $host) === 1 || preg_match('/(?:^|\.)local$/', $host) === 1) {
			return '';
		}
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';

		return 'https://' . $host . $port;
	}

	/**
	 * Keeps a mapped account: saved when new, updated when known, counts set.
	 */
	public function store(Person $person): void {
		try {
			$this->cacheActorsRequest->getFromId($person->getId());
			$this->actorService->update($person);
		} catch (CacheActorDoesNotExistException) {
			$this->actorService->save($person);
		}
		$this->cacheActorsRequest->setCounts($person->getId(), $person->getDetails(Details::COUNT));
		$this->reissueVerification($person);
	}

	/**
	 * The instance's verification of the account written again when the
	 * AppView now gives another handle or display name
	 * (`VerificationService::refresh()`); resolved lazily, as that needs
	 * services that need this one.
	 */
	private function reissueVerification(Person $person): void {
		try {
			$verifications = $this->container?->get(VerificationService::class);
			if ($verifications instanceof VerificationService) {
				$verifications->refresh($person);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Verification not issued again', ['actor' => $person->getId(), 'exception' => $e]);
		}
	}
}
