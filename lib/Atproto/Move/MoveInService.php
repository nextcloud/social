<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyListImport;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\AtprotoPlcRefusedException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moving a Bluesky account here (§13.1), driven from here: the person names
 * their Bluesky account and its password, and this server signs in to the
 * old PDS as them and does what a migration tool would.
 *
 * The password is the account's own, not an app password: a PDS signs a
 * change of the DID only for a full session. It is used to sign in when the
 * move starts and not kept; the session is, sealed, while the move runs.
 *
 * A background job copies the repository — checked against the DID's
 * signing key, written here byte for byte under a commit signed with a key
 * made for the account here — the blobs and the preferences. Then the old
 * PDS e-mails the person a code; with it, it signs the operation that
 * points the DID here, and the account takes the DID: the one this server
 * had made for it is retired. Last, the account is switched off on the old
 * PDS, its follows become follows here — only now, so a move called off
 * leaves none behind — its posts posts in its timeline here and its lists
 * its lists here.
 */
class MoveInService {
	private const PAGE = 500;

	public function __construct(
		private PdsClient $pds,
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PlcClient $plc,
		private IdentityService $identities,
		private InstanceKeyService $instanceKeys,
		private RepositoryService $repositories,
		private AtprotoRepoRequest $repoRequest,
		private AtprotoBlobRequest $blobs,
		private DocumentService $documents,
		private Preferences $preferences,
		private BlueskyActorService $blueskyActors,
		private FollowService $follows,
		private PostHistory $history,
		private ActorsRequest $actors,
		private AtprotoMoveRequest $moves,
		private ICrypto $crypto,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private BlueskyListImport $lists,
	) {
	}

	/**
	 * Signs in to the old PDS and queues the copy.
	 *
	 * @param string $authFactorToken the code a PDS e-mails for a sign-in it wants confirmed
	 * @throws MoveErrorException with what to tell the person
	 * @throws AtprotoException
	 */
	public function start(string $userId, string $typed, string $password, string $authFactorToken = ''): Move {
		$latest = $this->moves->latestOfUser($userId);
		if ($latest !== null && in_array($latest->state, [Move::RUNNING, Move::WAITING], true)) {
			throw new MoveErrorException('A move is already under way', 'move-in-progress');
		}
		$did = $this->didOf($typed);
		try {
			$this->identities->getByDid($did);
			throw new MoveErrorException('That Bluesky account is on this server already', 'account-here-already');
		} catch (AtprotoIdentityNotFoundException) {
		}
		$data = $this->didState($did);
		$endpoint = (string)($data['services']['atproto_pds']['endpoint'] ?? '');
		if ($endpoint === '') {
			throw new MoveErrorException('The directory names no server for that account', 'pds-not-named');
		}
		$origin = $this->pds->origin($endpoint);

		$login = ['identifier' => $did, 'password' => $password];
		if (trim($authFactorToken) !== '') {
			$login['authFactorToken'] = trim($authFactorToken);
		}
		$answer = $this->pds->call($origin, 'com.atproto.server.createSession', 'POST', [], $login);
		if ($answer['status'] !== 200 || ($answer['body']['did'] ?? '') !== $did) {
			throw self::signInRefused($answer);
		}

		$key = PrivateKey::generate(Curve::K256);
		$move = new Move(0, $did, $userId, Move::IN, $origin, '', (string)($answer['body']['handle'] ?? $typed), Move::STEP_REPO, Move::RUNNING);
		$move->session = $this->seal([
			'accessJwt' => (string)($answer['body']['accessJwt'] ?? ''),
			'refreshJwt' => (string)($answer['body']['refreshJwt'] ?? ''),
			'key' => bin2hex($key->secret()),
		]);
		$id = $this->moves->add($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $id]);

		return $this->moves->get($id) ?? $move;
	}

	/**
	 * The code the old PDS e-mailed: the move goes on with it.
	 *
	 * @throws MoveErrorException
	 */
	public function code(string $userId, string $code): Move {
		$move = $this->moves->latestOfUser($userId);
		if ($move === null || $move->direction !== Move::IN || $move->state !== Move::WAITING) {
			throw new MoveErrorException('No move is waiting for a code', 'no-move-waiting');
		}
		$code = trim($code);
		if ($code === '' || strlen($code) > 64) {
			throw new MoveErrorException('That is not a code', 'code-invalid');
		}
		$move->session = $this->seal(['code' => $code] + $this->unseal($move->session));
		$move->step = Move::STEP_IDENTITY;
		$move->resume();
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/**
	 * Starts a failed move again from its step; a wrong code asks for a new one.
	 *
	 * @throws MoveErrorException
	 */
	public function retry(Move $move): Move {
		if ($move->direction !== Move::IN || $move->state !== Move::FAILED) {
			throw new MoveErrorException('There is no failed move to start again', 'no-failed-move');
		}
		if ($move->step === Move::STEP_IDENTITY) {
			$move->step = Move::STEP_CODE;
		}
		$move->resume();
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/**
	 * Runs the steps left, until the move is done, waits for the code, or a
	 * step fails.
	 */
	public function run(Move $move): void {
		if ($move->state !== Move::RUNNING || $move->direction !== Move::IN) {
			return;
		}
		if ($move->step === Move::STEP_FOLLOWS && !isset($this->unseal($move->session)['code'])) {
			// stopped at the follows when they still came before the code
			$move->step = Move::STEP_CODE;
		}
		try {
			while ($move->step !== Move::STEP_DONE) {
				if (!$this->step($move)) {
					$move->state = Move::WAITING;
					$this->moves->update($move);

					return;
				}
				$move->step = Move::nextIn($move->step);
				$this->moves->update($move);
			}
			$move->state = Move::DONE;
			$move->session = '';
			$this->moves->update($move);
		} catch (Throwable $e) {
			$move->fail($e->getMessage(), MoveFailure::codeOf($e));
			$this->moves->update($move);
			$this->logger->warning('Bluesky account move here stopped', ['did' => $move->did, 'step' => $move->step, 'exception' => $e]);
		}
	}

	/**
	 * @return bool false when the move waits for the person
	 * @throws AtprotoException
	 */
	private function step(Move $move): bool {
		switch ($move->step) {
			case Move::STEP_REPO:
				$this->copyRepository($move);
				break;
			case Move::STEP_BLOBS:
				$this->copyBlobs($move);
				break;
			case Move::STEP_PREFERENCES:
				$preferences = $this->authed($move, 'app.bsky.actor.getPreferences');
				try {
					$this->preferences->put($this->sessionFor($move), ['preferences' => $preferences['preferences'] ?? []]);
				} catch (XrpcException $e) {
					$this->logger->info('Preferences not copied', ['did' => $move->did, 'error' => $e->getMessage()]);
				}
				break;
			case Move::STEP_FOLLOWS:
				// the DID is the account's by now: a follow not taken over
				// does not undo the move
				try {
					$this->adoptFollows($move);
				} catch (Throwable $e) {
					$this->logger->warning('Follows of a Bluesky account that moved here not taken over', ['did' => $move->did, 'exception' => $e]);
				}
				break;
			case Move::STEP_CODE:
				$this->authed($move, 'com.atproto.identity.requestPlcOperationSignature', 'POST');

				return false;
			case Move::STEP_IDENTITY:
				$this->takeDid($move);
				break;
			case Move::STEP_ACTIVATE:
				$this->authed($move, 'com.atproto.server.deactivateAccount', 'POST', [], []);
				break;
			case Move::STEP_POSTS:
				$this->importPosts($move);
				break;
		}

		return true;
	}

	/**
	 * @throws AtprotoException
	 */
	private function copyRepository(Move $move): void {
		if ($this->repositories->getHead($move->did) !== null) {
			return;
		}
		$signing = (string)($this->didState($move->did)['verificationMethods']['atproto'] ?? '');
		$car = $this->pds->bytes($move->pds, 'com.atproto.sync.getRepo', ['did' => $move->did]);
		if ($car['status'] !== 200 || $signing === '') {
			throw new MoveFailure('repo-unavailable', 'The old server did not hand out the repository (' . $car['status'] . ')');
		}
		try {
			$archive = RepoArchive::read($car['bytes'], $move->did, PublicKey::fromDidKey($signing));
		} catch (AtprotoException|InvalidArgumentException $e) {
			throw new MoveFailure('repo-invalid', $e->getMessage(), $e);
		}
		$this->repositories->import($move->did, $this->key($move), $archive['records'], $archive['rev']);
		$move->progress = ['records' => count($archive['records'])];
	}

	/**
	 * Every blob of the old repository, byte for byte.
	 *
	 * @throws AtprotoException
	 */
	private function copyBlobs(Move $move): void {
		$actor = $this->actors->getFromUserId($move->userId);
		$cursor = '';
		$copied = (int)($move->progress['blobs'] ?? 0);
		do {
			$page = $this->pds->expect($move->pds, 'com.atproto.sync.listBlobs', 'GET', ['did' => $move->did, 'limit' => self::PAGE] + ($cursor !== '' ? ['cursor' => $cursor] : []));
			foreach (is_array($page['cids'] ?? null) ? $page['cids'] : [] as $cid) {
				if (!is_string($cid) || !Cid::isValid($cid) || $this->blobs->get($move->did, $cid) !== null) {
					continue;
				}
				$blob = $this->pds->bytes($move->pds, 'com.atproto.sync.getBlob', ['did' => $move->did, 'cid' => $cid]);
				if ($blob['status'] !== 200 || !Cid::forRaw($blob['bytes'])->equals(Cid::parse($cid))) {
					$this->logger->info('Blob not copied', ['did' => $move->did, 'cid' => $cid, 'status' => $blob['status']]);
					continue;
				}
				$path = (string)tempnam(sys_get_temp_dir(), 'social-atproto-move-');
				try {
					file_put_contents($path, $blob['bytes']);
					$document = $this->documents->storeAsIs($actor, $path);
				} finally {
					@unlink($path);
				}
				$this->blobs->put(new BlobRef($move->did, Cid::parse($cid), $document->getId(), BlobRef::servableType($document->getMimeType()), strlen($blob['bytes'])));
				$this->repoRequest->addBlobBytes($move->did, strlen($blob['bytes']));
				$copied++;
			}
			$cursor = (string)($page['cursor'] ?? '');
			$move->progress = ['blobs' => $copied] + $move->progress;
			$this->moves->update($move);
		} while ($cursor !== '');
	}

	/**
	 * The account's posts become posts in its timeline here, its latest
	 * likes and reposts likes and boosts, and its curate lists public lists
	 * here (`BlueskyListImport`). The account has moved by then, so a
	 * failure here does not fail the move.
	 */
	public function importPosts(Move $move): void {
		try {
			$actor = $this->actors->getFromUserId($move->userId);
			$move->progress = ['posts' => $this->history->import($actor, $move->did)] + $move->progress;
			$move->progress = $this->history->importActions($actor, $move->did) + $move->progress;
		} catch (Throwable $e) {
			$this->logger->warning('Posts of a Bluesky account that moved here not imported', ['did' => $move->did, 'exception' => $e]);
		}
		try {
			$move->progress = ['lists' => $this->lists->adopt($this->actors->getFromUserId($move->userId), $move->did)] + $move->progress;
		} catch (Throwable $e) {
			$this->logger->warning('Lists of a Bluesky account that moved here not taken over', ['did' => $move->did, 'exception' => $e]);
		}
	}

	/**
	 * The account's follows on Bluesky become follows here, each tied to the
	 * record it came with. An account that cannot be read now is skipped.
	 */
	public function adoptFollows(Move $move): void {
		$actor = $this->actors->getFromUserId($move->userId);
		$adopted = 0;
		$cursor = '';
		do {
			$page = $this->repositories->listRecords($move->did, RecordMapper::FOLLOW, 100, $cursor);
			foreach ($page as $record) {
				$value = DagCbor::decode($record->bytes);
				$subject = is_array($value) ? (string)($value['subject'] ?? '') : '';
				if (!Syntax::isDid($subject)) {
					continue;
				}
				try {
					$adopted += $this->follows->adoptBlueskyFollow($actor, $this->blueskyActors->resolve($subject), $move->did, $record->rkey) ? 1 : 0;
				} catch (Throwable $e) {
					$this->logger->info('Follow not taken over', ['did' => $move->did, 'subject' => $subject, 'exception' => $e]);
				}
			}
			$cursor = count($page) === 100 ? end($page)->rkey : '';
		} while ($cursor !== '');
		$move->progress = ['follows' => $adopted] + $move->progress;
	}

	/**
	 * The old PDS signs the operation that points the DID here, and the
	 * account takes it.
	 *
	 * @throws AtprotoException
	 */
	private function takeDid(Move $move): void {
		$session = $this->unseal($move->session);
		$actor = $this->actors->getFromUserId($move->userId);
		$current = $this->identities->forActor($actor) ?? throw new MoveFailure('no-identity', 'This account has no Bluesky identity to move into');
		$key = $this->key($move);
		$signed = $this->authed($move, 'com.atproto.identity.signPlcOperation', 'POST', [], [
			'token' => (string)($session['code'] ?? ''),
			'rotationKeys' => [$this->instanceKeys->rotationKey()->didKey()],
			'alsoKnownAs' => ['at://' . $current->handle],
			'verificationMethods' => ['atproto' => $key->didKey()],
			'services' => ['atproto_pds' => ['type' => PlcOperation::PDS_SERVICE_TYPE, 'endpoint' => $this->config->pdsEndpoint()]],
		]);
		$operation = is_array($signed['operation'] ?? null) ? $signed['operation'] : [];
		if (($operation['services']['atproto_pds']['endpoint'] ?? '') !== $this->config->pdsEndpoint()
			|| ($operation['verificationMethods']['atproto'] ?? '') !== $key->didKey()) {
			throw new MoveFailure('operation-mismatch', 'The old server signed something else than was asked');
		}
		try {
			$this->identities->adopt($current, $move->did, $key, $move->pds, $operation);
		} catch (AtprotoException $e) {
			throw $e instanceof AtprotoPlcRefusedException || !str_starts_with($e->getMessage(), 'PLC directory') ? $e : new MoveFailure('plc-unreachable', $e->getMessage(), $e);
		}
	}

	/**
	 * A call to the old PDS as the account, its session refreshed once when
	 * it has expired.
	 *
	 * @throws AtprotoException
	 */
	private function authed(Move $move, string $method, string $verb = 'GET', array $query = [], ?array $json = null): array {
		$session = $this->unseal($move->session);
		$answer = $this->pds->call($move->pds, $method, $verb, $query, $json, null, '', (string)($session['accessJwt'] ?? ''));
		if (in_array($answer['status'], [400, 401], true) && in_array($answer['body']['error'] ?? '', ['ExpiredToken', 'InvalidToken'], true) && ($session['refreshJwt'] ?? '') !== '') {
			try {
				$refreshed = $this->pds->expect($move->pds, 'com.atproto.server.refreshSession', 'POST', [], null, null, '', (string)$session['refreshJwt']);
			} catch (MoveFailure $e) {
				// a PLC token that is wrong or old answers as an expired session does
				throw $method === 'com.atproto.identity.signPlcOperation' ? self::codeRefused($answer) : new MoveFailure($e->errorCode === 'pds-refused' ? 'pds-session-expired' : $e->errorCode, $e->getMessage(), $e);
			}
			$move->session = $this->seal(['accessJwt' => (string)($refreshed['accessJwt'] ?? ''), 'refreshJwt' => (string)($refreshed['refreshJwt'] ?? '')] + $session);
			$this->moves->update($move);
			$answer = $this->pds->call($move->pds, $method, $verb, $query, $json, null, '', (string)($refreshed['accessJwt'] ?? ''));
		}
		if ($answer['status'] !== 200) {
			throw $method === 'com.atproto.identity.signPlcOperation' && in_array($answer['body']['error'] ?? '', ['ExpiredToken', 'InvalidToken'], true)
				? self::codeRefused($answer)
				: PdsClient::refused($method, $answer);
		}

		return $answer['body'];
	}

	/**
	 * The old PDS did not take the e-mailed code: `code-expired` or
	 * `code-invalid`.
	 *
	 * @param array{status: int, body: array} $answer
	 */
	private static function codeRefused(array $answer): MoveFailure {
		$failure = PdsClient::refused('com.atproto.identity.signPlcOperation', $answer);

		return new MoveFailure(($answer['body']['error'] ?? '') === 'ExpiredToken' ? 'code-expired' : 'code-invalid', $failure->getMessage());
	}

	/**
	 * The old PDS did not let the person sign in, as a code: a sign-in code
	 * asked for, wrong or old; a wrong password; too many tries.
	 *
	 * @param array{status: int, body: array} $answer
	 */
	private static function signInRefused(array $answer): MoveErrorException {
		$error = (string)($answer['body']['error'] ?? '');
		$slug = match (true) {
			$error === 'AuthFactorTokenRequired' => 'auth-factor-required',
			$error === 'ExpiredToken' => 'sign-in-code-expired',
			$error === 'InvalidToken' => 'sign-in-code-invalid',
			$answer['status'] === 429 || $error === 'RateLimitExceeded' => 'rate-limited',
			$answer['status'] === 401 || $error === 'AuthenticationRequired' => 'wrong-password',
			default => 'sign-in-refused',
		};
		if ($slug === 'auth-factor-required') {
			return new MoveErrorException('The Bluesky server e-mailed you a sign-in code: enter it and start again', $slug);
		}

		return new MoveErrorException('The Bluesky server did not let you sign in: ' . trim((string)($answer['body']['message'] ?? 'status ' . $answer['status'])), $slug);
	}

	/**
	 * The directory's current state of a DID.
	 *
	 * @throws MoveErrorException `did-not-found` when the directory does not know it
	 * @throws MoveFailure `plc-unreachable`
	 */
	public function didState(string $did): array {
		try {
			$data = $this->plc->data($did);
		} catch (AtprotoException $e) {
			throw new MoveFailure('plc-unreachable', $e->getMessage(), $e);
		}

		return $data ?? throw new MoveErrorException('The PLC directory does not know ' . $did, 'did-not-found');
	}

	/**
	 * The did:plc a handle or DID the person typed names.
	 *
	 * @throws MoveErrorException
	 */
	public function didOf(string $typed): string {
		$typed = strtolower(trim(ltrim(trim($typed), '@')));
		if (Syntax::isDid($typed)) {
			if (!str_starts_with($typed, 'did:plc:')) {
				throw new MoveErrorException('Only accounts with a did:plc can move here', 'did-not-supported');
			}

			return $typed;
		}
		if (!Syntax::isHandle($typed)) {
			throw new MoveErrorException('That is not a Bluesky handle', 'handle-invalid');
		}
		try {
			$did = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $typed])['did'] ?? '');
		} catch (Throwable) {
			$did = '';
		}
		if (!str_starts_with($did, 'did:plc:')) {
			throw new MoveErrorException('No Bluesky account with a did:plc goes by ' . $typed, 'handle-not-found');
		}

		return $did;
	}

	private function sessionFor(Move $move): ClientSession {
		$identity = $this->identities->forActor($this->actors->getFromUserId($move->userId), false)
			?? new Identity(0, '', $move->did, $move->handle, '', '', '', Identity::STATE_DEACTIVATED, $move->pds, 0);

		return new ClientSession($move->userId, $identity, '');
	}

	/**
	 * @throws AtprotoException
	 */
	private function key(Move $move): PrivateKey {
		$secret = (string)($this->unseal($move->session)['key'] ?? '');
		if ($secret === '') {
			throw new MoveFailure('keys-sealed', 'The move lost its signing key; start it again');
		}

		return new PrivateKey(Curve::K256, (string)hex2bin($secret));
	}

	private function seal(array $session): string {
		return $this->crypto->encrypt((string)json_encode($session));
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
}
