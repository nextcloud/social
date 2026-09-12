<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Strike;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\ReportService;
use OCA\Social\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Moderation actions behind the Social section of the admin settings. Every
 * route requires a session and a CSRF token — none of this is part of the
 * client API.
 *
 * `AuthorizedAdminSetting` rather than the admin-by-default a controller has
 * without `NoAdminRequired`: an administrator passes, and so does a group the
 * administrator has handed `AdminSettings` to under Administration
 * privileges, which is what lets somebody moderate without administering the
 * whole server. The page itself is reachable to exactly the same people,
 * because core gates it on the same delegation.
 */
class ModerationController extends Controller {
	/** What one page of the account browser holds. */
	private const ACCOUNTS_PER_PAGE = 40;

	public function __construct(
		IRequest $request,
		private ReportService $reportService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private ModerationService $moderationService,
		private AdminApiService $adminApiService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Silences or suspends an account, or lifts whatever stands against it.
	 *
	 * @param string $actorId the account
	 * @param string $level 'silence', 'suspend', or '' to lift
	 * @param string $comment why, for whoever reads the list later
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/accounts')]
	public function accountModerate(string $actorId, string $level, string $comment = ''): DataResponse {
		$actorId = trim($actorId);
		if ($actorId === '') {
			return new DataResponse(['error' => 'no account given'], Http::STATUS_BAD_REQUEST);
		}

		if ($level === '') {
			$this->moderationService->lift($actorId);

			return new DataResponse(['actor_id' => $actorId, 'level' => '']);
		}

		try {
			return new DataResponse($this->moderationService->decide($actorId, $level, $comment));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * A page of the accounts this instance knows, for the browser on the
	 * settings page.
	 *
	 * The same read the Mastodon admin API answers `GET
	 * /api/v1/admin/accounts` with, narrowed to what a table needs: until
	 * this, only a *reported* account could be acted on from the web, and
	 * everything else needed a moderation client and a token.
	 *
	 * `query` is what a moderator would type — a username, a handle, or an
	 * instance — and is tried as all three, because asking which of them it
	 * was is a question the person already answered by typing it.
	 *
	 * @param string $query username, handle or instance
	 * @param string $origin 'local', 'remote', or '' for both
	 * @param string $status one of AdminApiService's statuses, or '' for any
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function accounts(
		string $query = '',
		string $origin = '',
		string $status = '',
		int $maxId = 0,
	): DataResponse {
		$query = trim($query);
		$local = match ($origin) {
			'local' => true,
			'remote' => false,
			default => null,
		};

		[$username, $domain] = $this->splitQuery($query);

		$page = $this->adminApiService->accountPage(
			$local,
			$username,
			'',
			$domain,
			$status,
			self::ACCOUNTS_PER_PAGE,
			$maxId,
		);

		// one query for the page rather than one an account: the column is a
		// number, and forty of them are not worth forty round trips
		$strikes = $this->moderationService->strikeCounts(array_map(
			static fn (AdminAccount $account): string => $account->getActorId(), $page['accounts']
		));

		return new DataResponse([
			'accounts' => array_map(
				static fn (AdminAccount $account): array => [
					'actor_id' => $account->getActorId(),
					'handle' => $account->getAccount()?->getAccount() ?? '',
					'username' => $account->getUsername(),
					'domain' => $account->getDomain(),
					'local' => $account->isLocal(),
					'level' => $account->getLevel(),
					'strikes' => $strikes[$account->getActorId()] ?? 0,
				],
				$page['accounts']
			),
			'cursors' => $page['cursors'],
		]);
	}

	/**
	 * What was typed, as the two halves the query takes.
	 *
	 * `@bob@noisy.test` and `bob@noisy.test` are an account on an instance,
	 * `noisy.test` is the instance, and a bare `bob` is a username anywhere —
	 * which is what somebody typing each of those means by it.
	 *
	 * @return array{0: string, 1: string} username, instance
	 */
	private function splitQuery(string $query): array {
		$query = ltrim($query, '@');
		if ($query === '') {
			return ['', ''];
		}

		$at = strrpos($query, '@');
		if ($at !== false) {
			return [substr($query, 0, $at), strtolower(substr($query, $at + 1))];
		}

		return str_contains($query, '.') ? ['', strtolower($query)] : [$query, ''];
	}

	/**
	 * What has been decided about one account before now, newest first.
	 *
	 * Reached from the strike count in the browser: a number is what a
	 * moderator scans a page for, and the history is what they need once one
	 * of them is not zero.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function accountHistory(string $actorId): DataResponse {
		$actorId = trim($actorId);
		if ($actorId === '') {
			return new DataResponse(['error' => 'no account given'], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse([
			'strikes' => array_map(
				static fn (Strike $strike): array => [
					'action' => $strike->getAction(),
					'text' => $strike->getText(),
					'moderator' => $strike->getModerator(),
					'report_id' => $strike->getReportId(),
					'creation' => $strike->getCreation(),
				],
				$this->moderationService->history($actorId)
			),
		]);
	}

	/** Takes one post down, whoever wrote it. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/statuses/remove')]
	public function statusRemove(string $streamId): DataResponse {
		$streamId = trim($streamId);
		if ($streamId === '') {
			return new DataResponse(['error' => 'no post given'], Http::STATUS_BAD_REQUEST);
		}

		$this->moderationService->removeStream($streamId);

		return new DataResponse(['stream_id' => $streamId]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/reports/{id}/resolve')]
	public function reportResolve(int $id, bool $resolved = true): DataResponse {
		try {
			return new DataResponse($this->reportService->setResolved($id, $resolved));
		} catch (ReportNotFoundException $e) {
			return new DataResponse(['error' => 'report not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/add')]
	public function fediverseAdd(string $address): DataResponse {
		$address = strtolower(trim($address));
		if ($address === '' || !preg_match('/^[a-z0-9.:\[\]-]+$/', $address)) {
			return new DataResponse(['error' => 'invalid address'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->fediverseService->addAddress($address);

		return new DataResponse(['list' => $this->fediverseService->getListedAddresses()]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/remove')]
	public function fediverseRemove(string $address): DataResponse {
		$this->fediverseService->removeAddress(strtolower(trim($address)));

		return new DataResponse(['list' => $this->fediverseService->getListedAddresses()]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/retention')]
	public function retention(int $days): DataResponse {
		if ($days < 0 || $days > 3650) {
			return new DataResponse(['error' => 'invalid retention period'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_RETENTION_DAYS, (string)$days);

		return new DataResponse(['retentionDays' => $days]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/access')]
	public function fediverseAccess(string $type): DataResponse {
		try {
			$this->fediverseService->setAccessType($type);
		} catch (Exception $e) {
			return new DataResponse(['error' => 'invalid access type'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['accessType' => $this->fediverseService->getAccessType()]);
	}
}
