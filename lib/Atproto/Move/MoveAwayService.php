<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoPlcRefusedException;
use OCA\Social\Exceptions\MoveErrorException;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moving a Bluesky account away from this server (§13.2), driven from here:
 * the person names the PDS they move to and the account they want there,
 * and this server — which holds the account's signing key and a rotation
 * key of its DID — does what a migration tool would.
 *
 * Started in the request, so what the other PDS refuses is said at once:
 * the account is made there with a token the account signs, deactivated.
 * Then a background job copies the repository, the blobs and the
 * preferences, points the DID at the other PDS, activates the account there
 * and switches it off here. A step that fails can be started again; the
 * DID only moves once everything it needs is there.
 */
class MoveAwayService {
	private const CREATE_ACCOUNT = 'com.atproto.server.createAccount';
	private const MAX_BLOBS_PER_PAGE = 500;
	/** what the other PDS's `createAccount` errors mean, as codes for the page */
	private const CREATE_ACCOUNT_ERRORS = [
		'HandleNotAvailable' => 'handle-taken',
		'InvalidHandle' => 'handle-not-allowed',
		'UnsupportedDomain' => 'handle-not-allowed',
		'InvalidInviteCode' => 'invite-code-invalid',
		'InvalidPassword' => 'password-not-accepted',
		'IncompatibleDidDoc' => 'account-not-created',
		'RateLimitExceeded' => 'rate-limited',
	];

	public function __construct(
		private PdsClient $pds,
		private ServiceAuth $serviceAuth,
		private IdentityService $identities,
		private RepositoryService $repositories,
		private AtprotoBlobRequest $blobs,
		private PictureService $pictures,
		private VideoBlobService $videos,
		private Preferences $preferences,
		private AtprotoMoveRequest $moves,
		private ICrypto $crypto,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Makes the account on the other PDS and queues the rest.
	 *
	 * @throws MoveErrorException with what to tell the person
	 */
	public function start(string $userId, Identity $identity, string $pds, string $handle, string $email, string $password, string $inviteCode = ''): Move {
		if (!$identity->isActive()) {
			throw new MoveErrorException('The Bluesky account is not active here', 'identity-inactive');
		}
		$latest = $this->moves->latestOfUser($userId);
		if ($latest !== null && in_array($latest->state, [Move::RUNNING, Move::WAITING], true)) {
			throw new MoveErrorException('A move is already under way', 'move-in-progress');
		}
		try {
			$origin = $this->pds->origin($pds);
			$server = $this->pds->expect($origin, 'com.atproto.server.describeServer');
		} catch (AtprotoException $e) {
			throw new MoveErrorException($e->getMessage(), self::codeOf($e), $e);
		}
		$pdsDid = (string)($server['did'] ?? '');
		if (!Syntax::isDid($pdsDid)) {
			throw new MoveErrorException('That server does not say what it is', 'pds-unknown');
		}
		$handle = strtolower(trim(ltrim(trim($handle), '@')));
		$domains = is_array($server['availableUserDomains'] ?? null) ? $server['availableUserDomains'] : [];
		if (!Syntax::isHandle($handle) || ($domains !== [] && !self::underAny($handle, $domains))) {
			throw new MoveErrorException('That server gives out handles ending in ' . implode(', ', $domains), 'handle-not-allowed');
		}
		if (($server['inviteCodeRequired'] ?? false) === true && trim($inviteCode) === '') {
			throw new MoveErrorException('That server needs an invite code', 'invite-code-required');
		}

		try {
			$token = $this->serviceAuth->token($this->identities->signingKey($identity), $identity->did, $pdsDid, self::CREATE_ACCOUNT, 300);
			$body = ['did' => $identity->did, 'handle' => $handle, 'email' => trim($email), 'password' => $password];
			if (trim($inviteCode) !== '') {
				$body['inviteCode'] = trim($inviteCode);
			}
			$answer = $this->pds->call($origin, self::CREATE_ACCOUNT, 'POST', [], $body, null, '', $token);
		} catch (AtprotoException $e) {
			throw new MoveErrorException($e->getMessage(), self::codeOf($e), $e);
		}
		if ($answer['status'] !== 200 || !is_string($answer['body']['accessJwt'] ?? null)) {
			throw new MoveErrorException(
				'The other server did not make the account: ' . trim((string)($answer['body']['message'] ?? $answer['body']['error'] ?? 'status ' . $answer['status'])),
				self::CREATE_ACCOUNT_ERRORS[(string)($answer['body']['error'] ?? '')] ?? 'account-not-created',
			);
		}

		$move = new Move(0, $identity->did, $userId, Move::AWAY, $origin, $pdsDid, (string)($answer['body']['handle'] ?? $handle), Move::STEP_REPO, Move::RUNNING);
		$move->session = $this->seal($answer['body']);
		$saved = new Move($this->moves->add($move), $move->did, $userId, Move::AWAY, $origin, $pdsDid, $move->handle, $move->step, $move->state, $move->session);
		$this->jobList->add(AtprotoMove::class, ['move' => $saved->id]);

		return $saved;
	}

	/**
	 * The stable code of what stopped a call or a step, for the page.
	 */
	public static function codeOf(Throwable $e): string {
		$message = $e->getMessage();

		return match (true) {
			$e instanceof MoveErrorException => $e->errorCode,
			$e instanceof AtprotoPlcRefusedException => 'plc-refused',
			str_starts_with($message, 'PLC directory could not be reached') => 'plc-unreachable',
			str_contains($message, 'signing key of') && str_contains($message, 'cannot be opened') => 'keys-sealed',
			str_starts_with($message, 'That is not the https address') => 'pds-address-invalid',
			str_contains($message, 'public address') => 'pds-not-public',
			str_contains($message, 'could not be reached') => 'pds-unreachable',
			str_contains($message, '(ExpiredToken') || str_contains($message, '(InvalidToken') => 'pds-session-expired',
			preg_match('/ answered \d{3}/', $message) === 1 => 'pds-refused',
			default => 'move-failed',
		};
	}

	/**
	 * Starts a failed move again, from the step it failed at.
	 *
	 * @throws MoveErrorException
	 */
	public function retry(string $userId): Move {
		$move = $this->moves->latestOfUser($userId);
		if ($move === null || $move->state !== Move::FAILED || $move->direction !== Move::AWAY) {
			throw new MoveErrorException('There is no failed move to start again', 'no-failed-move');
		}
		$move->state = Move::RUNNING;
		$move->error = '';
		unset($move->progress['code']);
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/** The person's latest move, for the settings. */
	public function latest(string $userId): ?Move {
		return $this->moves->latestOfUser($userId);
	}

	/**
	 * Runs the steps that are left, in order; stops at the first that fails.
	 */
	public function run(int $moveId): void {
		$move = $this->moves->get($moveId);
		if ($move === null || $move->state !== Move::RUNNING || $move->direction !== Move::AWAY) {
			return;
		}
		try {
			$identity = $this->identities->getByDid($move->did);
			while ($move->step !== Move::STEP_DONE) {
				$this->step($move, $identity);
				$move->step = Move::next($move->step);
				$this->moves->update($move);
			}
			$move->state = Move::DONE;
			$move->session = '';
			$this->moves->update($move);
			$this->logger->info('Bluesky account moved away', ['did' => $move->did, 'pds' => $move->pds]);
		} catch (Throwable $e) {
			$move->state = Move::FAILED;
			$move->error = $e->getMessage();
			$move->progress['code'] = self::codeOf($e);
			$this->moves->update($move);
			$this->logger->warning('Bluesky account move stopped', ['did' => $move->did, 'step' => $move->step, 'exception' => $e]);
		}
	}

	/**
	 * @throws AtprotoException
	 */
	private function step(Move $move, Identity $identity): void {
		match ($move->step) {
			Move::STEP_REPO => $this->authed($move, 'com.atproto.repo.importRepo', 'POST', [], null, $this->repositories->exportCar($move->did), 'application/vnd.ipld.car'),
			Move::STEP_BLOBS => $this->copyBlobs($move),
			Move::STEP_PREFERENCES => $this->authed($move, 'app.bsky.actor.putPreferences', 'POST', [], $this->preferences->get(new ClientSession($move->userId, $identity, ''))),
			Move::STEP_IDENTITY => $this->identities->handOver($identity, $this->authed($move, 'com.atproto.identity.getRecommendedDidCredentials')),
			Move::STEP_ACTIVATE => $this->activate($move, $identity),
			default => null,
		};
	}

	/**
	 * Uploads to the other PDS every blob it says the repository names and
	 * it does not have.
	 *
	 * @throws AtprotoException
	 */
	private function copyBlobs(Move $move): void {
		$cursor = '';
		$copied = 0;
		do {
			$query = ['limit' => self::MAX_BLOBS_PER_PAGE] + ($cursor !== '' ? ['cursor' => $cursor] : []);
			$page = $this->authed($move, 'com.atproto.repo.listMissingBlobs', 'GET', $query);
			foreach (is_array($page['blobs'] ?? null) ? $page['blobs'] : [] as $missing) {
				$blob = $this->blobs->get($move->did, (string)($missing['cid'] ?? ''));
				if ($blob === null) {
					// a record names a blob this server never had; the other PDS
					// lists it as missing, as this one would have
					continue;
				}
				$bytes = str_starts_with($blob->mime, 'video/') ? $this->videos->open($blob)['stream'] : $this->pictures->read($blob);
				$this->authed($move, 'com.atproto.repo.uploadBlob', 'POST', [], null, $bytes, $blob->mime);
				$copied++;
			}
			$cursor = (string)($page['cursor'] ?? '');
			$move->progress = ['blobs' => $copied];
			$this->moves->update($move);
		} while ($cursor !== '');
	}

	/**
	 * @throws AtprotoException
	 */
	private function activate(Move $move, Identity $identity): void {
		// a procedure without input: a PDS refuses one that is sent a body
		$this->authed($move, 'com.atproto.server.activateAccount', 'POST');
		$this->identities->markMovedAway($identity);
	}

	/**
	 * A call to the other PDS as the moving account, its session refreshed
	 * once when it has expired.
	 *
	 * @param resource|string|null $bytes
	 * @throws AtprotoException
	 */
	private function authed(Move $move, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = ''): array {
		$session = $this->unseal($move->session);
		$answer = $this->pds->call($move->pds, $method, $verb, $query, $json, $bytes, $type, (string)($session['accessJwt'] ?? ''));
		if (in_array($answer['status'], [400, 401], true) && in_array($answer['body']['error'] ?? '', ['ExpiredToken', 'InvalidToken'], true) && is_string($session['refreshJwt'] ?? null)) {
			$refreshed = $this->pds->expect($move->pds, 'com.atproto.server.refreshSession', 'POST', [], null, '', '', $session['refreshJwt']);
			$move->session = $this->seal($refreshed);
			$this->moves->update($move);
			if (is_resource($bytes)) {
				rewind($bytes);
			}
			$answer = $this->pds->call($move->pds, $method, $verb, $query, $json, $bytes, $type, (string)($refreshed['accessJwt'] ?? ''));
		}
		if ($answer['status'] !== 200) {
			$error = trim((string)($answer['body']['error'] ?? '') . ': ' . (string)($answer['body']['message'] ?? ''), ': ');
			throw new AtprotoException($method . ' answered ' . $answer['status'] . ($error !== '' ? ' (' . $error . ')' : ''));
		}

		return $answer['body'];
	}

	private function seal(array $session): string {
		return $this->crypto->encrypt((string)json_encode(['accessJwt' => $session['accessJwt'] ?? '', 'refreshJwt' => $session['refreshJwt'] ?? '']));
	}

	private function unseal(string $sealed): array {
		if ($sealed === '') {
			return [];
		}
		try {
			$session = json_decode($this->crypto->decrypt($sealed), true);
		} catch (Throwable) {
			return [];
		}

		return is_array($session) ? $session : [];
	}

	private static function underAny(string $handle, array $domains): bool {
		foreach ($domains as $domain) {
			if (is_string($domain) && $domain !== '' && str_ends_with($handle, str_starts_with($domain, '.') ? $domain : '.' . $domain)) {
				return true;
			}
		}

		return false;
	}
}
