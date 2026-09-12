<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\UserMigration;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\StreamActionService;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\IMigrator;
use OCP\UserMigration\ISizeEstimationMigrator;
use OCP\UserMigration\TMigratorBasicVersionHandling;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * One user's Social data in a Nextcloud account export, and back out of one.
 *
 * What travels is what the account *is* and what it *chose*: the actor's
 * profile and flags, who it follows and who follows it, what it blocks and
 * mutes, what it wrote, and what it kept (bookmarks and favourites). The lists
 * of accounts are written in the shape Mastodon exports them, so the archive is
 * useful outside Nextcloud too — `following_accounts.csv` is the file
 * `MigrationService::parseFollowsCsv()` reads and the one Mastodon's "Import
 * follows" accepts.
 *
 * What deliberately does not travel:
 *
 * - **the private key.** Without it the imported account cannot sign as the
 *   *old* actor — and it should not be able to. An export archive is an
 *   ordinary file: downloaded, mailed, left in another cloud. The key that a
 *   whole instance's worth of servers accept as proof of identity has no
 *   revocation in ActivityPub, and this app goes as far as encrypting it at
 *   rest (`PrivateKeyCipher`), so putting a plaintext copy in a user-held file
 *   would undo that for the convenience of a case that does not need it: an
 *   account imported on another server is a *new* actor with a new id, and
 *   `AccountService::createActor()` gives it a fresh pair. Identity continuity
 *   is carried by `alsoKnownAs` plus a `Move` from the old server — which is
 *   why the import records the old actor id as an alias — not by copying the
 *   secret. The public key is exported, because it is public and identifies
 *   which actor this was.
 * - **other people's posts.** The local copies of remote statuses are a cache
 *   of somebody else's content; it is re-fetched wherever it is needed and is
 *   not the user's to carry. Only what the user wrote is in `outbox.json`.
 * - **moderation decisions taken against the account** (`social_moderation`),
 *   reports, the outbound request queue, OAuth clients, tokens and secrets. A
 *   suspension outlives even the deletion of an actor on purpose (see
 *   `PersonInterface::delete()`), so it must not be something a user can shed
 *   by exporting and re-importing, and a credential that survived a move would
 *   be a credential nobody can revoke.
 *
 * The import federates nothing except the follows, which cannot be re-created
 * any other way — see import().
 */
class SocialMigrator implements IMigrator, ISizeEstimationMigrator {
	use TMigratorBasicVersionHandling;

	/** How many rows one read asks for, everywhere in here. */
	public const PAGE = 50;

	/**
	 * The block and mute lists are read in one query — `getByActor()` takes no
	 * offset — so the read is capped, and the cap is reported rather than
	 * silently truncating.
	 */
	public const RELATIONS_LIMIT = 5000;

	private const PATH_ROOT = Application::APP_ID . '/';
	/**
	 * What says an archive holds Social data at all.
	 *
	 * Public because the Migration page reads archives too — see
	 * `ZipImportSource` — and both halves have to agree on the one file that
	 * decides it, or an archive would be accepted by one and refused by the
	 * other.
	 */
	public const PATH_ACTOR_PUBLIC = self::PATH_ROOT . 'actor.json';
	private const PATH_ACTOR = self::PATH_ACTOR_PUBLIC;
	private const PATH_FOLLOWING = self::PATH_ROOT . 'following_accounts.csv';
	private const PATH_FOLLOWERS = self::PATH_ROOT . 'followers.csv';
	private const PATH_BLOCKS = self::PATH_ROOT . 'blocked_accounts.csv';
	private const PATH_MUTES = self::PATH_ROOT . 'muted_accounts.csv';
	private const PATH_BOOKMARKS = self::PATH_ROOT . 'bookmarks.csv';
	private const PATH_LIKES = self::PATH_ROOT . 'likes.csv';
	private const PATH_OUTBOX = self::PATH_ROOT . 'outbox.json';

	/** The header Mastodon writes over `muted_accounts.csv`. */
	private const MUTES_HEADER = 'Account address,Hide notifications';

	public function __construct(
		private IL10N $l10n,
		private AccountService $accountService,
		private MigrationService $migrationService,
		private CacheActorService $cacheActorService,
		private FollowsRequest $followsRequest,
		private ActorRelationRequest $actorRelationRequest,
		private StreamRequest $streamRequest,
		private StreamActionService $streamActionService,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function getId(): string {
		return Application::APP_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function getDisplayName(): string {
		return $this->l10n->t('Social');
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function getDescription(): string {
		return $this->l10n->t(
			'Your Fediverse profile, the accounts you follow, block and mute, your own posts,'
			. ' your bookmarks and your favourites. Your private key stays behind.'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Counted rather than measured: three cheap counts, and a post costs about
	 * a kilobyte of JSON.
	 */
	#[\Override]
	public function getEstimatedExportSize(IUser $user): int|float {
		try {
			$actor = $this->accountService->getActorFromUserId($user->getUID());
		} catch (Throwable $e) {
			return 0;
		}

		$size = 4; // the actor document
		$size += $this->streamRequest->countNotesFromActorId($actor->getId());
		// a handle is a line of some 40 bytes in a csv
		$size += ($this->followsRequest->countFollowing($actor->getId())
			+ $this->followsRequest->countFollowers($actor->getId())) / 25;

		return (int)ceil($size);
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function export(IUser $user, IExportDestination $exportDestination, OutputInterface $output): void {
		try {
			$actor = $this->accountService->getActorFromUserId($user->getUID());
		} catch (Throwable $e) {
			$output->writeln($user->getUID() . ' has no Social account, nothing to export…');

			return;
		}

		$this->exportActor($actor, $exportDestination, $output);
		$this->exportFollows($actor, $exportDestination, $output);
		$this->exportRelations($actor, $exportDestination, $output);
		$this->exportMarks($actor, $exportDestination, $output);
		$this->exportOutbox($actor, $exportDestination, $output);
	}

	/**
	 * The actor, without its private key. Exported as JSON rather than as the
	 * actor document itself: this file is what the import reads back, and an
	 * actor document is full of URLs of the server it is leaving.
	 */
	private function exportActor(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		$output->writeln('Exporting the Social actor in ' . self::PATH_ACTOR . '…');

		try {
			$exportDestination->addFileContents(self::PATH_ACTOR, json_encode([
				'id' => $actor->getId(),
				'account' => $actor->getAccount(),
				'preferredUsername' => $actor->getPreferredUsername(),
				'name' => $actor->getName(),
				'summary' => $actor->getDescription(),
				'fields' => $actor->getFields(),
				'locked' => $actor->isLocked(),
				'discoverable' => $actor->isDiscoverable(),
				'indexable' => $actor->isIndexable(),
				'bot' => $actor->isBot(),
				'sensitive' => $actor->isSensitive(),
				'privacy' => $actor->getPrivacy(),
				'language' => $actor->getLanguage(),
				'avatar' => $actor->getAvatar(),
				'header' => $actor->getHeader(),
				'alsoKnownAs' => $actor->getAlsoKnownAs(),
				'movedTo' => $actor->getMovedTo(),
				// the public half only; see the class comment
				'publicKey' => $actor->getPublicKey(),
				'created' => $actor->getCreation(),
			], JSON_THROW_ON_ERROR));
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social actor', 0, $e);
		}
	}

	private function exportFollows(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		try {
			$following = $this->handlesOfFollows(
				fn (int $offset): array => $this->followsRequest->getFollowingByActorId(
					$actor->getId(), self::PAGE, $offset
				)
			);
			$exportDestination->addFileContents(
				self::PATH_FOLLOWING, MigrationService::exportFollowsCsv($following)
			);
			$output->writeln('Exported ' . count($following) . ' follow(s) to ' . self::PATH_FOLLOWING . '…');

			$followers = $this->handlesOfFollows(
				fn (int $offset): array => $this->followsRequest->getFollowersByActorId(
					$actor->getId(), self::PAGE, $offset
				)
			);
			$exportDestination->addFileContents(
				self::PATH_FOLLOWERS, MigrationService::exportFollowsCsv($followers)
			);
			$output->writeln(
				'Exported ' . count($followers) . ' follower(s) to ' . self::PATH_FOLLOWERS
				. ' — a record, not something an import can re-create: a follower follows again,'
				. ' or their server is told by the Move of the account they followed…'
			);
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social follows', 0, $e);
		}
	}

	/**
	 * The handles of a paged follow query, in order, without the ones whose
	 * account this server never cached — a bare actor URL is not a handle, and
	 * writing one into the address column would produce a row every reader of
	 * the format skips anyway.
	 *
	 * @param callable(int):Follow[] $page
	 *
	 * @return string[]
	 */
	private function handlesOfFollows(callable $page): array {
		$handles = [];
		$offset = 0;

		while (true) {
			$follows = $page($offset);
			if ($follows === []) {
				return $handles;
			}

			foreach ($follows as $follow) {
				$account = $follow->hasActor() ? $follow->getActor()?->getAccount() ?? '' : '';
				if ($account === '') {
					$this->logger->debug('a follow has no cached account, leaving it out of the export', [
						'actor' => $follow->getActorId(), 'object' => $follow->getObjectId(),
					]);

					continue;
				}

				$handles[] = $account;
			}

			$offset += count($follows);
		}
	}

	/**
	 * Blocks and mutes, in the two files Mastodon writes them to.
	 *
	 * `blocked_accounts.csv` is a bare list of handles; `muted_accounts.csv`
	 * has a header and carries whether notifications are hidden, which is the
	 * one thing a mute stores besides its target.
	 */
	private function exportRelations(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		try {
			$blocks = $this->actorRelationRequest->getByActor(
				$actor->getId(), ActorRelation::TYPE_BLOCK, self::RELATIONS_LIMIT
			);
			$lines = [];
			foreach ($blocks as $block) {
				$handle = $this->handleOf($block->getObjectId());
				if ($handle !== '') {
					$lines[] = $handle;
				}
			}
			$exportDestination->addFileContents(self::PATH_BLOCKS, $this->csv($lines));
			$output->writeln('Exported ' . count($lines) . ' block(s) to ' . self::PATH_BLOCKS . '…');
			$this->warnOnCap($blocks, 'blocks', $output);

			$mutes = $this->actorRelationRequest->getByActor(
				$actor->getId(), ActorRelation::TYPE_MUTE, self::RELATIONS_LIMIT
			);
			$lines = [];
			foreach ($mutes as $mute) {
				$handle = $this->handleOf($mute->getObjectId());
				if ($handle !== '') {
					$lines[] = $handle . ',' . ($mute->isNotifications() ? 'false' : 'true');
				}
			}
			$exportDestination->addFileContents(
				self::PATH_MUTES, $this->csv(array_merge([self::MUTES_HEADER], $lines))
			);
			$output->writeln('Exported ' . count($lines) . ' mute(s) to ' . self::PATH_MUTES . '…');
			$this->warnOnCap($mutes, 'mutes', $output);
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social blocks and mutes', 0, $e);
		}
	}

	/**
	 * What the user kept: the bookmarked and the favourited posts, as the URLs
	 * of those posts — the shape Mastodon's `bookmarks.csv` has, and the only
	 * shape that survives leaving this server, where the local ids mean nothing.
	 */
	private function exportMarks(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		try {
			foreach ([
				ProbeOptions::BOOKMARKS => self::PATH_BOOKMARKS,
				ProbeOptions::FAVOURITES => self::PATH_LIKES,
			] as $probe => $path) {
				$urls = [];
				foreach ($this->posts($actor, $probe) as $post) {
					$urls[] = $post->getId();
				}

				$exportDestination->addFileContents($path, $this->csv($urls));
				$output->writeln('Exported ' . count($urls) . ' entr(y|ies) to ' . $path . '…');
			}
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social bookmarks and favourites', 0, $e);
		}
	}

	/**
	 * The user's own posts, as an ActivityPub `OrderedCollection`.
	 *
	 * Written through a temporary file a page at a time, the way
	 * `CalendarMigrator` does it: an account with ten years of posts must not
	 * have to fit in memory to be exportable. `totalItems` is written last,
	 * once counting is done — JSON does not care about key order and it saves
	 * a second pass over the rows.
	 */
	private function exportOutbox(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		$output->writeln('Exporting the Social posts to ' . self::PATH_OUTBOX . '…');

		$path = $this->tempManager->getTemporaryFile();
		$file = fopen($path, 'w+');
		if ($file === false) {
			throw new SocialMigratorException('Could not open a temporary file for ' . self::PATH_OUTBOX);
		}

		try {
			fwrite($file, '{"@context":"' . ACore::CONTEXT_ACTIVITYSTREAMS
				. '","id":' . json_encode($actor->getId() . '/outbox', JSON_THROW_ON_ERROR)
				. ',"type":"OrderedCollection","orderedItems":[');

			$count = 0;
			foreach ($this->posts($actor, ProbeOptions::ACCOUNT) as $post) {
				$item = $post->jsonSerialize();
				// the collection carries the context once
				unset($item['@context']);
				fwrite($file, ($count > 0 ? ',' : '') . json_encode($item, JSON_THROW_ON_ERROR));
				$count++;
			}

			fwrite($file, '],"totalItems":' . $count . '}');
			rewind($file);
			$exportDestination->addFileAsStream(self::PATH_OUTBOX, $file);
			$output->writeln('Exported ' . $count . ' post(s)…');
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social posts', 0, $e);
		} finally {
			fclose($file);
		}
	}

	/**
	 * One timeline of this actor, page by page, oldest last.
	 *
	 * The cursor is the `nid` of the last row of the page, which is what
	 * `ProbeOptions::setMaxId()` pages on, so a post added while the export
	 * runs cannot make it loop.
	 *
	 * @return iterable<Stream>
	 */
	private function posts(Person $actor, string $probe): iterable {
		$this->streamRequest->setViewer($actor);
		$maxId = 0;

		while (true) {
			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
				->setProbe($probe)
				->setAccountId($actor->getId())
				->setLimit(self::PAGE);
			if ($maxId > 0) {
				$options->setMaxId($maxId);
			}

			$posts = $this->streamRequest->getTimeline($options);
			if ($posts === []) {
				return;
			}

			foreach ($posts as $post) {
				yield $post;
			}

			$last = end($posts);
			$nid = ($last === false) ? 0 : $last->getNid();
			if ($nid <= 0 || ($maxId > 0 && $nid >= $maxId)) {
				// a row we cannot page on: stop rather than ask for the same page again
				return;
			}

			$maxId = $nid;
		}
	}

	/** The handle of an actor this server has cached, or '' when it has not. */
	private function handleOf(string $actorId): string {
		try {
			return $this->cacheActorService->getFromId($actorId)->getAccount();
		} catch (Throwable $e) {
			$this->logger->debug('cannot resolve an account for the export, leaving it out', [
				'actorId' => $actorId, 'exception' => $e,
			]);

			return '';
		}
	}

	/**
	 * @param string[] $lines
	 */
	private function csv(array $lines): string {
		return $lines === [] ? '' : implode("\n", $lines) . "\n";
	}

	private function warnOnCap(array $rows, string $what, OutputInterface $output): void {
		if (count($rows) >= self::RELATIONS_LIMIT) {
			$output->writeln(
				'Only the first ' . self::RELATIONS_LIMIT . ' ' . $what . ' were exported…'
			);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Safe to run on a server where the account already exists: the actor is
	 * taken as it is found (and only created when there is none), every write
	 * below is idempotent, and nothing is deleted — an import adds what the
	 * archive knows to what is here.
	 *
	 * Federation: an import sends no Delete, no Move and no Update{Person}
	 * except the one that follows a restored bio, which goes to the followers
	 * this account has here — on a freshly imported account, nobody — and is
	 * the same Update the user's own edit of the bio sends. Blocks and mutes
	 * are written straight to the relation table rather than through
	 * `RelationshipService`, which would federate a `Block` to an account that
	 * was already blocked before the move. The **follows** are the deliberate
	 * exception: a Follow towards each account is the only way a follow can
	 * exist at all, and it is what the user asked for by bringing the file.
	 *
	 * A missing file is not a failure: an archive from an older version of this
	 * app, or one a user assembled by hand, imports whatever it does carry.
	 */
	#[\Override]
	public function import(IUser $user, IImportSource $importSource, OutputInterface $output): void {
		if ($importSource->getMigratorVersion($this->getId()) === null) {
			$output->writeln('No version for ' . static::class . ', skipping import…');

			return;
		}

		if (!$this->archiveHasSocialData($importSource)) {
			// every migrator that ran gets a version recorded, including for a
			// user who never used this app: creating an account here would hand
			// them a Fediverse identity, and publish it, on the strength of an
			// empty folder
			$output->writeln('No Social data in this archive, nothing to import…');

			return;
		}

		$userId = $user->getUID();
		try {
			// creates one where the server has none, which is the ordinary
			// case for an account that has just been imported
			$actor = $this->accountService->getActorFromUserId($userId, true);
		} catch (Throwable $e) {
			$this->logger->warning('cannot import Social data: no actor for the user', [
				'userId' => $userId, 'exception' => $e,
			]);
			$output->writeln(
				$userId . ' has no Social account here and none could be created (' . $e->getMessage()
				. '), skipping the Social import…'
			);

			return;
		}

		$this->importActor($userId, $importSource, $output);
		$this->importFollows($userId, $importSource, $output);
		$this->importRelations($actor, $importSource, $output);
		$this->importMarks($actor, $importSource, $output);
		$this->reportOutbox($importSource, $output);
	}

	/**
	 * The profile, through the paths the app uses itself, and the old actor id
	 * as an alias.
	 *
	 * The display name is not among them: it belongs to the Nextcloud account,
	 * the core `account` migrator carries it, and `AccountService` re-derives
	 * the actor's name from it. Neither is the key pair — see the class
	 * comment — nor `movedTo`, which would point the new account's own
	 * followers somewhere else.
	 */
	private function importActor(string $userId, IImportSource $importSource, OutputInterface $output): void {
		$contents = $this->optionalFile($importSource, self::PATH_ACTOR, $output);
		if ($contents === null) {
			return;
		}

		try {
			/** @var array<string, mixed> $data */
			$data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$output->writeln('<error>' . self::PATH_ACTOR . ' is not readable JSON, skipping it…</error>');

			return;
		}

		$output->writeln('Importing the Social profile from ' . self::PATH_ACTOR . '…');

		try {
			if (array_key_exists('locked', $data)) {
				$this->accountService->setLocked($userId, (bool)$data['locked']);
			}

			$flags = [];
			foreach (['discoverable', 'indexable'] as $flag) {
				if (array_key_exists($flag, $data)) {
					$flags[$flag] = (bool)$data[$flag];
				}
			}
			if ($flags !== []) {
				$this->accountService->setActorFlags($userId, $flags);
			}

			if (isset($data['fields']) && is_array($data['fields'])) {
				$this->accountService->setFields($userId, array_values($data['fields']));
			}

			// only when the archive has one: an archive written before bios
			// existed must not blank the bio of the account importing it
			$summary = trim((string)($data['summary'] ?? ''));
			if ($summary !== '') {
				$this->accountService->setSummary($userId, $summary);
			}
		} catch (Throwable $e) {
			$this->logger->warning('could not restore a Social profile', [
				'userId' => $userId, 'exception' => $e,
			]);
			$output->writeln('<error>Could not restore the Social profile: ' . $e->getMessage() . '</error>');
		}

		$oldId = trim((string)($data['id'] ?? ''));
		if ($oldId === '') {
			return;
		}

		try {
			$this->migrationService->addAlias($userId, $oldId);
			$output->writeln(
				'Recorded ' . $oldId . ' as an alias of this account, so a Move from it is accepted here…'
			);
		} catch (Throwable $e) {
			$this->logger->warning('could not record the old actor id as an alias', [
				'userId' => $userId, 'alias' => $oldId, 'exception' => $e,
			]);
			$output->writeln('<error>Could not record ' . $oldId . ' as an alias: ' . $e->getMessage() . '</error>');
		}
	}

	/**
	 * Re-follows everything in `following_accounts.csv`, through the ordinary
	 * follow path — which resolves each handle and queues a Follow, exactly as
	 * `occ social:account:import-follows` does with the same file. One handle
	 * whose server is unreachable is reported and does not stop the others.
	 */
	private function importFollows(string $userId, IImportSource $importSource, OutputInterface $output): void {
		$csv = $this->optionalFile($importSource, self::PATH_FOLLOWING, $output);
		if ($csv === null) {
			return;
		}

		$output->writeln('Following the accounts of ' . self::PATH_FOLLOWING . '…');

		try {
			$result = $this->migrationService->importFollows($userId, $csv);
		} catch (Throwable $e) {
			$this->logger->warning('could not import the follows', ['userId' => $userId, 'exception' => $e]);
			$output->writeln('<error>Could not import the follows: ' . $e->getMessage() . '</error>');

			return;
		}

		$output->writeln(
			'Followed ' . $result['followed'] . ', skipped ' . $result['skipped']
			. ', failed ' . count($result['failed']) . '…'
		);
		foreach ($result['failed'] as $handle => $reason) {
			$output->writeln('  - ' . $handle . ': ' . $reason);
		}
	}

	/**
	 * The blocks and mutes, written straight to the relation table: a block
	 * that was federated once when it was made does not need federating again,
	 * and the account on the other end was never told the user moved.
	 */
	private function importRelations(
		Person $actor,
		IImportSource $importSource,
		OutputInterface $output,
	): void {
		$blocks = $this->optionalFile($importSource, self::PATH_BLOCKS, $output);
		if ($blocks !== null) {
			$count = 0;
			foreach (MigrationService::parseFollowsCsv($blocks) as $handle) {
				$count += $this->relate($actor, $handle, ActorRelation::TYPE_BLOCK, true, $output) ? 1 : 0;
			}
			$output->writeln('Restored ' . $count . ' block(s) from ' . self::PATH_BLOCKS . '…');
		}

		$mutes = $this->optionalFile($importSource, self::PATH_MUTES, $output);
		if ($mutes !== null) {
			$hidden = $this->hiddenNotifications($mutes);
			$count = 0;
			foreach (MigrationService::parseFollowsCsv($mutes) as $handle) {
				$notifications = !($hidden[strtolower($handle)] ?? false);
				$count += $this->relate($actor, $handle, ActorRelation::TYPE_MUTE, $notifications, $output) ? 1 : 0;
			}
			$output->writeln('Restored ' . $count . ' mute(s) from ' . self::PATH_MUTES . '…');
		}
	}

	/**
	 * One block or mute against a handle. The account is resolved the way the
	 * app resolves any handle — which may fetch it, a plain signed GET; it
	 * tells the other end nothing it was not going to learn from a follower
	 * anyway, and without an actor id there is no relation to store.
	 *
	 * @return bool whether the relation was stored
	 */
	private function relate(
		Person $actor,
		string $handle,
		string $type,
		bool $notifications,
		OutputInterface $output,
	): bool {
		if (strcasecmp($handle, $actor->getAccount()) === 0) {
			return false;
		}

		try {
			$target = $this->cacheActorService->getFromAccount($handle);
		} catch (Throwable $e) {
			$this->logger->notice('cannot restore a block or mute: the account did not resolve', [
				'handle' => $handle, 'type' => $type, 'exception' => $e,
			]);
			$output->writeln('  - ' . $handle . ' did not resolve, its ' . $type . ' was not restored…');

			return false;
		}

		if ($target->getId() === $actor->getId()) {
			return false;
		}

		try {
			$this->actorRelationRequest->save($actor->getId(), $target->getId(), $type, $notifications);
		} catch (Throwable $e) {
			$this->logger->warning('cannot store a restored block or mute', [
				'handle' => $handle, 'type' => $type, 'exception' => $e,
			]);

			return false;
		}

		return true;
	}

	/**
	 * `handle => whether notifications are hidden`, from the second column of
	 * a `muted_accounts.csv`. The handles themselves come from the shared
	 * reader, so what is not a handle never gets here.
	 *
	 * @return array<string, bool>
	 */
	private function hiddenNotifications(string $csv): array {
		$hidden = [];
		foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $line) {
			if (trim($line) === '') {
				continue;
			}

			$cells = str_getcsv($line, ',', '"', '');
			$handle = strtolower(ltrim(trim((string)($cells[0] ?? '')), '@'));
			$hidden[$handle] = strtolower(trim((string)($cells[1] ?? ''))) === 'true';
		}

		return $hidden;
	}

	/**
	 * Marks again what the user had bookmarked and favourited — on the posts
	 * this server already has.
	 *
	 * A post nobody here has ever seen is skipped rather than fetched: pulling
	 * a decade of remote statuses out of other people's servers during an
	 * import is a cost they did not agree to, and a mark is restored the moment
	 * the post arrives here by any ordinary route. The mark is written locally
	 * only — a favourite is not re-federated as a Like, which the author
	 * already received once.
	 */
	private function importMarks(Person $actor, IImportSource $importSource, OutputInterface $output): void {
		foreach ([
			self::PATH_BOOKMARKS => StreamAction::BOOKMARKED,
			self::PATH_LIKES => StreamAction::LIKED,
		] as $path => $mark) {
			$contents = $this->optionalFile($importSource, $path, $output);
			if ($contents === null) {
				continue;
			}

			$marked = 0;
			$missing = 0;
			foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
				$id = trim($line);
				if ($id === '' || !str_starts_with($id, 'http')) {
					continue;
				}

				try {
					$post = $this->streamRequest->getStreamById($id);
				} catch (Throwable $e) {
					$missing++;
					continue;
				}

				try {
					$this->streamActionService->setActionBool($actor->getId(), $post->getId(), $mark, true);
					$marked++;
				} catch (Throwable $e) {
					$this->logger->warning('cannot restore a mark on a post', [
						'post' => $id, 'mark' => $mark, 'exception' => $e,
					]);
				}
			}

			$output->writeln(
				'Restored ' . $marked . ' ' . $mark . ' mark(s) from ' . $path . '; ' . $missing
				. ' post(s) this server does not have were left…'
			);
		}
	}

	private function reportOutbox(IImportSource $importSource, OutputInterface $output): void {
		if (!$this->pathExists($importSource, self::PATH_OUTBOX)) {
			return;
		}

		$output->writeln(
			'The posts in ' . self::PATH_OUTBOX . ' are not restored into the timeline: their ids'
			. ' belong to the server they were written on. They are in the archive as your copy of'
			. ' what you wrote…'
		);
	}

	/**
	 * The contents of a file that may not be there, or null — with a line
	 * saying so, because "nothing happened" and "there was nothing to do" look
	 * the same in a log otherwise.
	 */
	private function optionalFile(
		IImportSource $importSource,
		string $path,
		OutputInterface $output,
	): ?string {
		if (!$this->pathExists($importSource, $path)) {
			$output->writeln('No ' . $path . ' in the archive, skipping it…');

			return null;
		}

		try {
			return $importSource->getFileContents($path);
		} catch (Throwable $e) {
			$this->logger->warning('cannot read a file of the archive', [
				'path' => $path, 'exception' => $e,
			]);
			$output->writeln('<error>Could not read ' . $path . ', skipping it…</error>');

			return null;
		}
	}

	/**
	 * Whether the archive holds any file this migrator wrote. Asked file by
	 * file rather than by listing the folder, so it does not depend on how an
	 * implementation of the framework answers for a directory.
	 */
	private function archiveHasSocialData(IImportSource $importSource): bool {
		foreach ([
			self::PATH_ACTOR,
			self::PATH_FOLLOWING,
			self::PATH_FOLLOWERS,
			self::PATH_BLOCKS,
			self::PATH_MUTES,
			self::PATH_BOOKMARKS,
			self::PATH_LIKES,
			self::PATH_OUTBOX,
		] as $path) {
			if ($this->pathExists($importSource, $path)) {
				return true;
			}
		}

		return false;
	}

	private function pathExists(IImportSource $importSource, string $path): bool {
		try {
			return $importSource->pathExists($path);
		} catch (Throwable $e) {
			return false;
		}
	}
}
