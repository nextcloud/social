<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Activity\Move;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\InstancePath;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moving a local account away, and bringing one here.
 *
 * Outbound: the account declares where it went (`movedTo`), tells every
 * follower with a `Move`, and the followers' servers re-follow the new
 * account. The new account has to list this one in its `alsoKnownAs` first —
 * that back-reference is the only thing that stops a Move from re-pointing
 * somebody else's followers at an account the sender controls, and
 * MoveInterface refuses an incoming Move without it just the same.
 *
 * Inbound: the alias is set here (`alsoKnownAs`), the Move is started on the
 * old server, and the follows the old server exported as CSV are re-created
 * from here through the ordinary follow path.
 */
class MigrationService {
	/** The header Mastodon writes over its `following_accounts.csv`. */
	private const CSV_ADDRESS_COLUMN = 'account address';

	/** How many followers of a moved account to re-follow at a time. */
	private const REFOLLOW_PAGE = 200;

	/**
	 * The most followers one Move will re-follow.
	 *
	 * Also what makes the paging terminate no matter what the query does: a
	 * source that ignored the offset would hand back a full page forever, and
	 * the loop below cannot tell that from a very popular account.
	 */
	private const REFOLLOW_MAX = 20000;

	public function __construct(
		private AccountService $accountService,
		private ActorsRequest $actorsRequest,
		private FollowsRequest $followsRequest,
		private CacheActorService $cacheActorService,
		private FollowService $followService,
		private ActivityService $activityService,
		private SignatureService $signatureService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return string[] the actor ids this user's actor also answers to
	 */
	public function listAliases(string $userId): array {
		return $this->accountService->getActorFromUserId($userId)->getAlsoKnownAs();
	}

	/**
	 * Adds an actor id to `alsoKnownAs`. Idempotent.
	 *
	 * @return string[] the list afterwards
	 * @throws InvalidResourceException when `$alias` is not an actor id
	 */
	public function addAlias(string $userId, string $alias): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$alias = $this->actorIdOrThrow($alias, $actor);

		$aliases = $actor->getAlsoKnownAs();
		if (in_array($alias, $aliases, true)) {
			return $aliases;
		}

		$aliases[] = $alias;
		$this->accountService->setAlsoKnownAs($userId, $aliases);

		return $aliases;
	}

	/**
	 * Removes an actor id from `alsoKnownAs`. Idempotent.
	 *
	 * @return string[] the list afterwards
	 */
	public function removeAlias(string $userId, string $alias): array {
		$actor = $this->accountService->getActorFromUserId($userId);

		$aliases = $actor->getAlsoKnownAs();
		if (!in_array($alias, $aliases, true)) {
			return $aliases;
		}

		$aliases = array_values(array_diff($aliases, [$alias]));
		$this->accountService->setAlsoKnownAs($userId, $aliases);

		return $aliases;
	}

	/**
	 * Moves this user's actor to `$targetId`.
	 *
	 * The target is fetched fresh — the `alsoKnownAs` that counts is the one
	 * its server publishes right now — and has to list our actor. Then a
	 * `Move{object: ours, target: theirs}` is queued for every follower's
	 * inbox and the new home, `movedTo` is recorded (which puts it on the actor
	 * document and as `moved` on the account entity), and the followers on
	 * this instance, who never receive the Move, are re-followed on their
	 * behalf.
	 *
	 * @return Person the target as fetched
	 * @throws InvalidResourceException when the target does not list the actor, or is the actor
	 */
	public function move(string $userId, string $targetId): Person {
		$actor = $this->accountService->getActorFromUserId($userId);
		$target = $this->cacheActorService->getFromId($targetId, true);

		if ($target->getId() === $actor->getId()) {
			throw new InvalidResourceException('an account cannot be moved onto itself');
		}

		if (!in_array($actor->getId(), $target->getAlsoKnownAs(), true)) {
			throw new InvalidResourceException(
				$target->getId() . ' does not list ' . $actor->getId() . ' in its alsoKnownAs:'
				. ' add the alias on the new account first, then try again'
			);
		}

		$move = new Move();
		$move->setId($actor->getId() . '#moves/' . time());
		$move->setActorId($actor->getId());
		$move->setObjectId($actor->getId());
		$move->setTarget($target->getId());

		$move->addInstancePath(
			new InstancePath($actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_MEDIUM)
		);
		if ($target->getInbox() !== '') {
			$move->addInstancePath(
				new InstancePath($target->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM)
			);
		}

		$this->signatureService->signObject($actor, $move);
		$this->activityService->request($move);

		// only once the Move is on its way: an actor marked moved whose
		// followers were never told is stuck
		$this->accountService->setMovedTo($userId, $target->getId());

		$this->refollowLocalFollowers($actor, $target);

		return $target;
	}

	/**
	 * Re-creates the follows of a Mastodon `following_accounts.csv` from this
	 * user's actor, one handle at a time through the ordinary follow path.
	 * One handle that fails does not stop the rest.
	 *
	 * @return array{followed: int, skipped: int, failed: array<string, string>}
	 *                                                                           `failed` maps a handle to the reason
	 */
	public function importFollows(string $userId, string $csv): array {
		$actor = $this->accountService->getActorFromUserId($userId);
		$result = ['followed' => 0, 'skipped' => 0, 'failed' => []];

		foreach (self::parseFollowsCsv($csv) as $handle) {
			if (strcasecmp($handle, $actor->getAccount()) === 0) {
				$result['skipped']++;
				continue;
			}

			try {
				$this->followService->followAccount($actor, $handle);
				$result['followed']++;
			} catch (FollowSameAccountException $e) {
				$result['skipped']++;
			} catch (Throwable $e) {
				$result['failed'][$handle] = $e->getMessage();
				$this->logger->notice('cannot import a follow', [
					'actor' => $actor->getId(), 'handle' => $handle, 'exception' => $e,
				]);
			}
		}

		return $result;
	}

	/**
	 * The handles as a Mastodon `following_accounts.csv`: the header Mastodon
	 * writes, then one row per handle.
	 *
	 * The column values are what a fresh Mastodon follow carries — boosts
	 * shown, no notification, no language filter — because neither this app nor
	 * the file format it is borrowing stores them per follow. What matters is
	 * the shape: the file an export produces has to be one that Mastodon's own
	 * "Import follows" accepts, and one that parseFollowsCsv() reads back.
	 *
	 * @param string[] $handles
	 */
	public static function exportFollowsCsv(array $handles): string {
		// written by hand rather than with fputcsv(), which quotes a field
		// containing a space and would put the header out in a shape no other
		// implementation writes
		$lines = ['Account address,Show boosts,Notify on new posts,Languages'];
		foreach ($handles as $handle) {
			$lines[] = self::csvCell($handle) . ',true,false,';
		}

		return implode("\n", $lines) . "\n";
	}

	/** A value as one CSV cell: quoted only where it has to be. */
	private static function csvCell(string $value): string {
		if (strpbrk($value, ",\"\r\n") === false) {
			return $value;
		}

		return '"' . str_replace('"', '""', $value) . '"';
	}

	/**
	 * The handles in a Mastodon `following_accounts.csv`.
	 *
	 * Current exports carry the header `Account address,Show boosts,Notify on
	 * new posts,Languages`; older ones are a bare list of handles. Either way
	 * a leading `@` is dropped, and a handle that is not `user@host` or that
	 * repeats (case-insensitively) is left out.
	 *
	 * @return string[]
	 */
	public static function parseFollowsCsv(string $csv): array {
		$lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
		$column = 0;

		$first = array_map('trim', str_getcsv((string)($lines[0] ?? ''), ',', '"', ''));
		$header = array_search(self::CSV_ADDRESS_COLUMN, array_map('strtolower', $first), true);
		if ($header !== false) {
			$column = $header;
			array_shift($lines);
		}

		$handles = [];
		$seen = [];
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}

			$cells = str_getcsv($line, ',', '"', '');
			$handle = ltrim(trim((string)($cells[$column] ?? '')), '@');
			// `name@host`, with the host allowed to be a single label: a
			// fediverse host is usually dotted, but an instance reached as
			// `cloud` or `devel` on a private network is not, and insisting on
			// a dot silently dropped every handle on such a server — including
			// the ones in this app's own export. A handle that resolves to
			// nothing is reported as failed, which says more than skipping it.
			if (preg_match('/^[^@\s]+@[^@\s]+$/', $handle) !== 1) {
				continue;
			}

			$key = strtolower($handle);
			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$handles[] = $handle;
		}

		return $handles;
	}

	/**
	 * @throws InvalidResourceException
	 */
	private function actorIdOrThrow(string $alias, Person $actor): string {
		$alias = trim($alias);
		$parts = parse_url($alias);
		if ($parts === false
			|| !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
			|| ($parts['host'] ?? '') === '') {
			throw new InvalidResourceException(
				'"' . $alias . '" is not an actor id: expected the https:// address of the account,'
				. ' not its handle'
			);
		}

		if ($alias === $actor->getId()) {
			throw new InvalidResourceException('an account is not an alias of itself');
		}

		return $alias;
	}

	/**
	 * Follows the new account on behalf of everyone on this instance who
	 * followed the old one. They never receive the Move — a delivery to
	 * ourselves is dropped — so nothing else would do it for them.
	 *
	 * Paged. getFollowersByActorId() takes a limit and this used to call it
	 * without one, so a popular account's entire follower set was loaded into
	 * memory before the first re-follow, and each row then costs a lookup and an
	 * outbound follow. The page size bounds the memory; the work itself is still
	 * one account's followers, which is what a Move is.
	 */
	private function refollowLocalFollowers(Person $actor, Person $target): void {
		$offset = 0;

		while ($offset < self::REFOLLOW_MAX) {
			$page = $this->followsRequest->getFollowersByActorId(
				$actor->getId(), self::REFOLLOW_PAGE, $offset
			);
			if ($page === []) {
				return;
			}

			$this->refollowPage($page, $target);
			$offset += count($page);

			if (count($page) < self::REFOLLOW_PAGE) {
				return;
			}
		}

		$this->logger->warning(
			'stopped re-following the followers of a moved account at the ceiling of '
			. self::REFOLLOW_MAX . '; the rest keep following the old account',
			['actor' => $actor->getId(), 'target' => $target->getId()]
		);
	}

	/**
	 * @param Follow[] $page
	 */
	private function refollowPage(array $page, Person $target): void {
		foreach ($page as $follow) {
			try {
				// only a local account has a row here
				$follower = $this->actorsRequest->getFromId($follow->getActorId());
			} catch (Exception $e) {
				continue;
			}

			try {
				$this->followService->followAccount($follower, $target->getAccount());
			} catch (Throwable $e) {
				// one unreachable target must not stop the others
				$this->logger->warning('cannot re-follow a moved account', [
					'follower' => $follower->getId(),
					'target' => $target->getId(),
					'exception' => $e,
				]);
			}
		}
	}
}
