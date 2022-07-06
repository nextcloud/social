<?php

declare(strict_types=1);


// Nextcloud Social
// SPDX-FileCopyrightText: 2018 <maxence@artificial-owl.com>
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service;

use OCA\Social\Entity\Application;
use Exception;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\DB\ORM\IEntityManager;
use OCP\DB\ORM\IEntityRepository;

class ApplicationService {
	public const TIME_TOKEN_REFRESH = 300; // 5m
//	const TIME_TOKEN_TTL = 21600; // 6h
//	const TIME_AUTH_TTL = 30672000; // 1y

	// looks like there is no token refresh. token must have been updated in the last year.
	public const TIME_TOKEN_TTL = 30672000; // 1y

	use TStringTools;

	private IEntityManager $em;
	/** @var IEntityRepository<Application> */
	private IEntityRepository $applicationRepository;

	public function __construct(IEntityManager $em) {
		$this->em = $em;
		$this->applicationRepository = $em->getRepository(Application::class);
	}


	/**
	 * @throws ClientException
	 */
	public function createApp(Application $application): void {
		if ($application->getAppName() === '') {
			throw new ClientException('missing client_name');
		}

		if (empty($application->getAppRedirectUris())) {
			throw new ClientException('missing redirect_uris');
		}

		$application->setAppClientId($this->token(40));
		$application->setAppClientSecret($this->token(40));

		$this->em->persist($application);
		$this->em->flush();
	}

	public function authClient(Application $client) {
		$client->setAuthCode($this->token(60));
		$this->em->flush();
	}

	public function generateToken(Application $client): void {
		$client->setToken($this->token(80));
		$this->em->flush();
	}

	public function getFromClientId(string $clientId): Application {
		return $this->applicationRepository->findOneBy([
			'appClientId' => $clientId,
		]);
	}

	/**
	 * @throws ClientNotFoundException
	 */
	public function getFromToken(string $token): Application {
		/** @var Application $application */
		$application = $this->applicationRepository->findOneBy(['token' => $token]);

		if ($application->getLastUpdate() + self::TIME_TOKEN_TTL < time()) {
			try {
				$this->em->remove($application);
				$this->em->flush();
			} catch (Exception $e) {
			}

			throw new ClientNotFoundException();
		}

		if ($application->getLastUpdate() + self::TIME_TOKEN_REFRESH > time()) {
			$application->setLastUpdate((new \DateTime('now'))->getTimestamp());
			$this->em->flush();
		}

		return $application;
	}

	/**
	 * @throws ClientException
	 */
	public function confirmData(Application $client, array $data): void {
		if (array_key_exists('redirect_uri', $data)
			&& !in_array($data['redirect_uri'], $client->getAppRedirectUris())) {
			throw new ClientException('unknown redirect_uri');
		}

		if (array_key_exists('client_secret', $data)
			&& $data['client_secret'] !== $client->getAppClientSecret()) {
			throw new ClientException('wrong client_secret');
		}

		if (array_key_exists('app_scopes', $data)) {
			$scopes = $data['app_scopes'];
			if (!is_array($scopes)) {
				$scopes = $client->getScopesFromString($scopes);
			}

			foreach ($scopes as $scope) {
				if (!in_array($scope, $client->getAppScopes())) {
					throw new ClientException('invalid scope');
				}
			}
		}

		if (array_key_exists('auth_scopes', $data)) {
			$scopes = $data['auth_scopes'];
			if (!is_array($scopes)) {
				$scopes = $client->getScopesFromString($scopes);
			}

			foreach ($scopes as $scope) {
				if (!in_array($scope, $client->getAuthScopes())) {
					throw new ClientException('invalid scope');
				}
			}
		}

		if (array_key_exists('code', $data) && $data['code'] !== $client->getAuthCode()) {
			throw new ClientException('unknown code');
		}
	}
}
