<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\AccessBlock;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccessBlockService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MetricsService;
use OCA\Social\Service\TrendService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mastodon's admin API: `/api/v1/admin/*`.
 *
 * Moderation existed here before this controller did — the admin panel silences
 * and suspends accounts, resolves reports and keeps the instance access list —
 * but every one of those was a `Moderation#*` route needing a Nextcloud session
 * *and* a CSRF token, which no API client has and none can obtain. A moderator
 * could act from a browser and from nowhere else.
 *
 * ### Who may reach any of this
 *
 * A **Nextcloud administrator**, and nothing else. Every route begins with
 * `initAdmin()`, which resolves the Nextcloud user behind the request — the
 * token's user, or the session's — and then asks `IGroupManager::isAdmin()`
 * about *that user*. Anyone else is a 403, whatever they present.
 *
 * An OAuth scope is not, and cannot be, the check. This app's client
 * registration stores whatever scope string a client asks for: `admin:write`
 * on a token records that some client asked for it during an authorisation,
 * never that the user behind it may moderate anything. A scope check alone
 * would therefore have made every account on the instance an administrator of
 * it. The scope is still required on a bearer token, as Mastodon requires it —
 * it is what keeps an ordinary client's `read` token from reaching the admin
 * API on an administrator's behalf — but it is checked *after* the group, and
 * it can only ever narrow what an administrator may do.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like every other client-API
 * controller here: a bearer token carries no session and no CSRF token, so
 * `#[AdminRequired]` — which is what `ModerationController` relies on — would
 * refuse every real caller before the handler ran. Nothing is public in fact;
 * the administrator check simply happens in the handler rather than in the
 * middleware, and it happens on every single route.
 *
 * Entities are documented in `AdminAccount`, `AdminReport` and
 * `AdminDomainBlock`; each emits every key Mastodon documents, with the empty
 * value of its type wherever this app has nothing behind one.
 */
class AdminApiController extends Controller {
	private string $bearer = '';
	private ?SocialClient $client = null;
	private string $userId = '';

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AdminApiService $adminApiService,
		private AccessBlockService $accessBlockService,
		private MetricsService $metricsService,
		private HashtagService $hashtagService,
		private TrendService $trendService,
		private ClientService $clientService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
	}

	/**
	 * A page of accounts, newest first.
	 *
	 * `email` and `ip` are accepted and match nothing: this instance holds
	 * neither for a fediverse account, so a page filtered by one is empty
	 * rather than unfiltered — a filter that was ignored would have shown a
	 * moderator the whole instance as the answer to a question about one
	 * account.
	 *
	 * @param string $origin `local`, `remote`, or empty for both
	 * @param string $status `active`, `silenced`, `suspended`; `pending` and
	 *                       `disabled` are states this app has not and answer
	 *                       with nothing
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/accounts')]
	public function accounts(
		string $origin = '',
		string $status = '',
		string $username = '',
		string $display_name = '',
		string $by_domain = '',
		string $email = '',
		string $ip = '',
		int $limit = AdminApiService::LIMIT,
		int $max_id = 0,
		int $min_id = 0,
	): DataResponse {
		try {
			$this->initAdmin();

			if (trim($email) !== '' || trim($ip) !== '') {
				return new DataResponse([], Http::STATUS_OK);
			}

			// clamped here as well as in the service: the cursor is offered
			// only when the page came back full, and a client asking for more
			// than the instance will build would never be offered one
			$limit = $this->limit($limit);
			$page = $this->adminApiService->accountPage(
				$this->origin($origin), $username, $display_name, $by_domain,
				$status, $limit, $max_id, $min_id
			);

			return $this->paged($page['accounts'], $limit, $page['cursors']);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One account, by numeric id, actor id or handle. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/accounts/{id}', requirements: ['id' => '.+'])]
	public function account(string $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->account($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The moderator's decision about an account.
	 *
	 * Answers `{}`, as Mastodon does. `report_id` resolves that report at the
	 * same time — a decision taken from a report is the report handled, and
	 * making the client send a second call for it leaves the two able to
	 * disagree.
	 *
	 * @param string $type `silence`, `suspend` or `none`
	 * @param string $text the moderator's note, kept as the comment on the
	 *                     decision
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// `{id}` accepts slashes here and on account(): a suspended account, whose
	// cached actor the suspension purged, is named by its actor id and no
	// longer by a numeric one. That makes account()'s `/api/v1/admin/accounts/{id}`
	// match this url too, and only the verb keeps them apart — so every action
	// route under `/api/v1/admin/accounts/{id}/` has to stay a POST.
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/accounts/{id}/action', requirements: ['id' => '.+'])]
	public function accountAction(
		string $id,
		string $type = '',
		string $text = '',
		int $report_id = 0,
	): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			$account = $this->adminApiService->account($id);
			$this->adminApiService->act($account, $type, $text, $report_id);

			if ($report_id > 0) {
				$this->adminApiService->resolveReport($report_id, $this->userId);
			}

			return new DataResponse((object)[], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's "re-enable a disabled login".
	 *
	 * Nothing here can disable one — a fediverse account has no login of its
	 * own, and the Nextcloud account behind a local one is enabled where
	 * Nextcloud keeps it — so this answers with the account and changes
	 * nothing. It exists because a moderation client calls it unconditionally
	 * when clearing a strike, and a 404 there reads as "no such account".
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/accounts/{id}/enable', requirements: ['id' => '.+'])]
	public function accountEnable(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->account($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/accounts/{id}/unsilence', requirements: ['id' => '.+'])]
	public function accountUnsilence(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->unsilence($this->adminApiService->account($id)), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/accounts/{id}/unsuspend', requirements: ['id' => '.+'])]
	public function accountUnsuspend(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->unsuspend($this->adminApiService->account($id)), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * A page of reports, newest first.
	 *
	 * `resolved` follows Mastodon: absent means unresolved only, which is the
	 * queue a moderator opens the panel to work through.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/reports')]
	public function reports(
		string $resolved = '',
		string $account_id = '',
		string $target_account_id = '',
		int $limit = AdminApiService::LIMIT,
		int $max_id = 0,
		int $min_id = 0,
	): DataResponse {
		try {
			$this->initAdmin();

			$limit = $this->limit($limit);
			$reports = $this->adminApiService->reports(
				$this->bool($resolved) ?? false,
				$account_id,
				$target_account_id,
				$limit,
				$max_id,
				$min_id
			);

			return $this->paged($reports, $limit, array_map(
				static fn ($report): int => $report->getId(), $reports
			));
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/reports/{id}')]
	public function report(int $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->report($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/reports/{id}/resolve')]
	public function reportResolve(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->resolveReport($id, $this->userId), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/reports/{id}/reopen')]
	public function reportReopen(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->reopenReport($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/reports/{id}/assign_to_self')]
	public function reportAssignToSelf(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->assignReport($id, $this->userId), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/reports/{id}/unassign')]
	public function reportUnassign(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->assignReport($id, null), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The instance-wide access list, as domain blocks. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/domain_blocks')]
	public function domainBlocks(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->domainBlocks(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/domain_blocks/{id}')]
	public function domainBlock(string $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->adminApiService->domainBlock($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Blocks a domain.
	 *
	 * `severity` may only be `suspend`: an entry on this list refuses the
	 * domain outright, and a client asking for `silence` would otherwise be
	 * told it had been given something milder than it was.
	 * `reject_media`, `reject_reports`, `obfuscate` and the two comments are
	 * accepted and ignored — the list has no room for any of them, which
	 * `AdminDomainBlock` states field by field.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/domain_blocks')]
	public function domainBlockCreate(string $domain = '', string $severity = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->adminApiService->blockDomain($domain, $severity), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * There is nothing on a block here to change, so this confirms the entry
	 * and refuses any severity but the one it has. A 200 that had quietly
	 * dropped the change would be worse: the moderator would believe the
	 * domain was under a lesser block than it is.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/domain_blocks/{id}')]
	public function domainBlockUpdate(string $id, string $severity = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$this->adminApiService->assertSeverity($severity);

			return new DataResponse($this->adminApiService->domainBlock($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Lifts a block and answers with the entry that was lifted. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/admin/domain_blocks/{id}')]
	public function domainBlockRemove(string $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse($this->adminApiService->unblockDomain($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// IP blocks and email-domain blocks

	/**
	 * The addresses this instance answers nothing from.
	 *
	 * Mastodon's list, with one severity: `no_access`. The other two police a
	 * sign-up this instance has not — an account here is a Nextcloud account,
	 * and the server decides who gets one.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function ipBlocks(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->accessBlockService->ipBlocks(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function ipBlock(int $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->accessBlockService->ipBlock($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Refuses an address or a range everything this app serves.
	 *
	 * `expires_in` is Mastodon's: seconds from now, or absent for a block that
	 * does not lift itself. A severity this instance cannot honour is a
	 * **422** rather than a row nothing will ever read — an admin told their
	 * rule was stored would believe sign-ups from that range were being turned
	 * away.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function ipBlockCreate(
		string $ip = '',
		string $severity = AccessBlock::SEVERITY_NO_ACCESS,
		string $comment = '',
		int $expires_in = 0,
	): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->accessBlockService->blockIp(
					$ip, $severity, $comment, $expires_in > 0 ? time() + $expires_in : 0
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Changes one. The address is not among what can change: a block on a
	 * different range is a different block, and Mastodon's own PUT keeps it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function ipBlockUpdate(
		int $id,
		string $severity = AccessBlock::SEVERITY_NO_ACCESS,
		string $comment = '',
		int $expires_in = 0,
	): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$block = $this->accessBlockService->ipBlock($id);

			return new DataResponse(
				$this->accessBlockService->blockIp(
					$block->getValue(), $severity, $comment,
					$expires_in > 0 ? time() + $expires_in : 0
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function ipBlockRemove(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$this->accessBlockService->unblockIp($id);

			return new DataResponse((object)[], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The email domains this instance does not give fediverse accounts to.
	 *
	 * Mastodon refuses a sign-up at one of these. There is no sign-up here, so
	 * what it refuses is the decision this app does make: whether a Nextcloud
	 * account gets a fediverse identity — the same question one step later.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function emailDomainBlocks(): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse($this->accessBlockService->emailDomainBlocks(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function emailDomainBlock(int $id): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->accessBlockService->emailDomainBlock($id), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function emailDomainBlockCreate(string $domain = ''): DataResponse {
		try {
			$this->initAdmin(['admin:write']);

			return new DataResponse(
				$this->accessBlockService->blockEmailDomain($domain), Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function emailDomainBlockRemove(int $id): DataResponse {
		try {
			$this->initAdmin(['admin:write']);
			$this->accessBlockService->unblockEmailDomain($id);

			return new DataResponse((object)[], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// trends, as a moderation client asks for them

	/**
	 * The same three trend readers the public routes use, behind the admin
	 * gate a moderation client expects them at.
	 *
	 * On Mastodon these carry a moderator's extra field — whether the trend is
	 * allowed or pending review — and this instance reviews nothing: a trend
	 * here is what the counts say. So they answer exactly what
	 * `/api/v1/trends/*` answers, which is the honest thing to do with a route
	 * whose only difference is a review queue that does not exist. They exist
	 * because a moderation client asks for them by this path and a 404 reads
	 * as "this server has no trends".
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function trendTags(int $limit = 10): DataResponse {
		try {
			$this->initAdmin();

			$tags = [];
			foreach ($this->hashtagService->getTrending(max(1, min(100, $limit))) as $hashtag) {
				$tags[] = $this->hashtagService->tagEntity($hashtag['hashtag']);
			}

			return new DataResponse($tags, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function trendStatuses(int $limit = 10, int $offset = 0): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->trendService->trendingStatuses(
					HashtagService::PERIOD_DEFAULT, max(1, min(100, $limit)), max(0, $offset)
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function trendLinks(int $limit = 10, int $offset = 0): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->trendService->trendingLinks(
					HashtagService::PERIOD_DEFAULT, max(1, min(100, $limit)), max(0, $offset)
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// metrics

	/**
	 * One number a day over a window, for each key asked for.
	 *
	 * Mastodon's `Admin::Measure`. A key this instance cannot answer is a
	 * **422** naming the ones it can, rather than a row of zeroes: answering
	 * 0 to "how many accounts signed up through an invite" reads as "none
	 * did", which is a different claim from "this instance has no invites".
	 *
	 * @param string[] $keys
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function measures(
		array $keys = [],
		string $start_at = '',
		string $end_at = '',
		string $instance = '',
		string $id = '',
	): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->metricsService->measures(
					$keys, $this->timestamp($start_at), $this->timestamp($end_at), $instance, $id
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The ranked list behind one number, for each key asked for.
	 *
	 * @param string[] $keys
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function dimensions(
		array $keys = [],
		string $start_at = '',
		string $end_at = '',
		int $limit = 10,
		string $id = '',
	): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->metricsService->dimensions(
					$keys, $this->timestamp($start_at), $this->timestamp($end_at), $limit, $id
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * How much of each month's new local accounts is still posting later.
	 *
	 * Monthly, whatever `frequency` asks for: a cohort is a thing you read
	 * over months, and a daily one on an instance with a handful of sign-ups a
	 * month is a table of zeroes.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function retention(string $start_at = '', string $end_at = ''): DataResponse {
		try {
			$this->initAdmin();

			return new DataResponse(
				$this->metricsService->retention(
					$this->timestamp($start_at), $this->timestamp($end_at)
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * A moment as a client writes one: an ISO date, or seconds since the
	 * epoch, or nothing.
	 *
	 * Zero for anything unreadable, which the service refuses by name — a
	 * window silently rounded to "the epoch until now" is a query nobody asked
	 * for over every row there is.
	 */
	private function timestamp(string $written): int {
		$written = trim($written);
		if ($written === '') {
			return 0;
		}

		if (ctype_digit($written)) {
			return (int)$written;
		}

		return max(0, (int)strtotime($written));
	}

	/**
	 * Establishes that this request is an administrator's, or refuses it.
	 *
	 * The order is the point: the Nextcloud user is resolved first, the group
	 * is asked about that user second, and the token's scope third. A caller
	 * who is not an administrator is refused before any scope is looked at, so
	 * no scope a client can ask for makes any difference to them.
	 *
	 * @param string[] $scopes any one of which satisfies a bearer token
	 *
	 * @throws ClientNotFoundException nobody is behind the request
	 * @throws InsufficientScopeException they are not an administrator of this
	 *                                    instance, or their token was not
	 *                                    granted the admin API
	 */
	private function initAdmin(array $scopes = ['admin:read']): void {
		$userId = $this->currentSession();

		if (!$this->adminApiService->isAdministrator($userId)) {
			// deliberately the same answer whether the user exists, has a
			// Social account, or simply may not moderate: the admin API tells
			// a non-administrator nothing about the instance, not even that
			$this->logger->info('[AdminApiController] admin API refused to a non-administrator', [
				'user' => $userId,
				'route' => (string)$this->request->getParam('_route', ''),
			]);

			throw new InsufficientScopeException(
				'this API is restricted to the administrators of this instance'
			);
		}

		$this->userId = $userId;

		if ($this->client !== null) {
			$this->checkTokenScope($scopes);
		}
	}

	/**
	 * The Nextcloud user behind the request: the bearer token's, or the
	 * session's when there is no token — the same order every other
	 * client-API controller here uses.
	 *
	 * @throws ClientNotFoundException
	 */
	private function currentSession(): string {
		if ($this->bearer !== '') {
			try {
				$this->client = $this->clientService->getFromToken($this->bearer);
			} catch (Exception $e) {
				// a stale or made-up token is ordinary internet noise
				$this->logger->debug('[AdminApiController] unusable bearer token', [
					'exception' => $e->getMessage(),
				]);

				throw new ClientNotFoundException('the access_token was revoked');
			}

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new ClientNotFoundException('userId not defined');
	}

	/**
	 * `admin:read` is satisfied by `admin:read` or by `admin`, and by nothing
	 * else.
	 *
	 * Not by `read`, which is what every ordinary client holds: Mastodon keeps
	 * the admin scopes outside the `read`/`write` tree for exactly that
	 * reason, so a timeline client's token cannot reach the moderation API
	 * even when its owner happens to be an administrator.
	 *
	 * @param string[] $accepted
	 *
	 * @throws InsufficientScopeException
	 */
	private function checkTokenScope(array $accepted): void {
		foreach ($accepted as $scope) {
			$broad = strstr($scope, ':', true);
			$broad = ($broad === false) ? $scope : $broad;

			foreach ($this->client->getAuthScopes() as $granted) {
				if ($granted === $scope || $granted === $broad) {
					return;
				}
			}
		}

		throw new InsufficientScopeException(
			'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
		);
	}

	/**
	 * Mastodon's `origin`: true for local accounts, false for remote, null for
	 * both.
	 *
	 * @throws InvalidResourceException anything else, rather than silently
	 *                                  listing everything
	 */
	private function origin(string $origin): ?bool {
		switch (trim($origin)) {
			case '':
				return null;
			case 'local':
				return true;
			case 'remote':
				return false;
			default:
				throw new InvalidResourceException("'" . $origin . "' is not a valid origin");
		}
	}

	/** What the instance will actually build, which is what a page is. */
	private function limit(int $limit): int {
		return max(1, min(AdminApiService::MAX_LIMIT, $limit));
	}

	/** A tri-state query parameter: absent is null, not false. */
	private function bool(string $value): ?bool {
		$value = strtolower(trim($value));
		if ($value === '') {
			return null;
		}

		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * A page with the `Link` header masto.js reads its cursor from — without
	 * it a client shows the first page and stops.
	 *
	 * @param int[] $ids the cursor ids of the page, in its order; empty when
	 *                   the page has no cursor at all, which is what the
	 *                   `silenced` and `suspended` account lists are
	 */
	private function paged(array $items, int $limit, array $ids): DataResponse {
		$response = new DataResponse($items, Http::STATUS_OK);
		if ($ids === []) {
			return $response;
		}

		$links = [];
		if (count($ids) >= $limit) {
			// a page shorter than the limit is the last one
			$links[] = '<' . $this->pageUrl(['max_id' => (string)min($ids)]) . '>; rel="next"';
		}
		$links[] = '<' . $this->pageUrl(['min_id' => (string)max($ids)]) . '>; rel="prev"';

		$response->addHeader('Link', implode(', ', $links));

		return $response;
	}

	/**
	 * This request's own URL with the cursor replaced, so every filter the
	 * client sent survives into the next page.
	 */
	private function pageUrl(array $cursor): string {
		$uri = $this->request->getRequestUri();
		$path = $uri;
		$query = [];

		$pos = strpos($uri, '?');
		if ($pos !== false) {
			$path = substr($uri, 0, $pos);
			parse_str(substr($uri, $pos + 1), $query);
		}

		unset($query['max_id'], $query['min_id'], $query['since_id'], $query['_route']);

		return $path . '?' . http_build_query(array_merge($query, $cursor));
	}

	/**
	 * A failure as a client can act on it. An unrecognised one is a bug on
	 * this side, so it answers 500 and its message is not sent on — these are
	 * `#[PublicPage]` routes, and echoing getMessage() publishes whatever the
	 * failure happened to name.
	 */
	private function error(Throwable $e): DataResponse {
		if ($e instanceof InsufficientScopeException) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_FORBIDDEN,
				['WWW-Authenticate' => 'Bearer error="insufficient_scope"']
			);
		}

		if ($e instanceof ClientNotFoundException) {
			$message = trim($e->getMessage());

			return new DataResponse(
				['error' => ($message === '') ? 'the access_token is invalid' : $message],
				Http::STATUS_UNAUTHORIZED,
				['WWW-Authenticate' => 'Bearer error="invalid_token"']
			);
		}

		if ($e instanceof ItemNotFoundException || $e instanceof ReportNotFoundException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		if ($e instanceof InvalidResourceException || $e instanceof InvalidArgumentException) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->logger->error('[AdminApiController] unexpected failure answering the admin API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
