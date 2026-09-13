<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\MastodonList;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud groups as Social lists.
 *
 * The one thing this instance knows that no other server can: who works
 * together. Every group a person is in becomes a list of theirs -- "Design",
 * "Berlin office" -- holding the group's members that have a Social account,
 * readable as a timeline like any list. Nobody has to build it and nobody has
 * to keep it: membership follows the group.
 *
 * A group list is a private list like any other, owned by the person who is
 * in the group, and it shows them only what they could see anyway -- the list
 * timeline applies the same visibility as the home timeline. What it does is
 * gather it. The group's members are not followed by being in it, and nothing
 * federates: a list is a view, not a relationship.
 *
 * Kept in step three ways, each cheap where it runs: the lists a person is
 * missing are made when they open their lists (`ensureForViewer()`), a group
 * change is applied the moment it happens (`GroupListListener`), and the cron
 * reconciles every group list against its group (`reconcile()`) for whatever
 * the first two did not see -- an account created after the lists were.
 */
class GroupListService {
	/**
	 * A group larger than this gets no list. The list would be every account
	 * on the instance under the name "everyone", which the Local timeline
	 * already is, and filling it is a query per member.
	 */
	public const MAX_GROUP_SIZE = 500;

	public function __construct(
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ActorsRequest $actorsRequest,
		private ListsRequest $listsRequest,
		private LoggerInterface $logger,
	) {
	}

	/** Whether the list's title and membership are a group's, not the owner's. */
	public static function isGroupList(MastodonList $list): bool {
		return $list->getGroupId() !== '';
	}

	/**
	 * Makes the group lists the viewer is missing, and removes the ones for
	 * groups they have left. Called when they ask for their lists, which is
	 * when they would notice either.
	 *
	 * @return int how many lists were made
	 */
	public function ensureForViewer(Person $viewer): int {
		if ($viewer->getUserId() === '') {
			return 0;
		}
		$user = $this->userManager->get($viewer->getUserId());
		if ($user === null) {
			return 0;
		}

		$existing = [];
		foreach ($this->listsRequest->getByActor($viewer->getId()) as $list) {
			if (self::isGroupList($list)) {
				$existing[$list->getGroupId()] = $list;
			}
		}

		$made = 0;
		foreach ($this->groupManager->getUserGroups($user) as $group) {
			if (isset($existing[$group->getGID()])) {
				unset($existing[$group->getGID()]);
				continue;
			}
			if (!$this->isListable($group)) {
				continue;
			}

			$list = (new MastodonList())
				->setOwnerId($viewer->getId())
				->setTitle(ListsRequest::normaliseTitle($group->getDisplayName()))
				->setGroupId($group->getGID());
			$this->listsRequest->create($list);
			$this->setMembers($list, $this->memberActorIds($group));
			$made++;
		}

		// what is left are lists for groups the viewer is no longer in
		foreach ($existing as $list) {
			$this->listsRequest->delete($list);
		}

		return $made;
	}

	/**
	 * Every group list against its group: title, membership, and whether the
	 * group still exists. The catch-all the cron runs; the listener and
	 * `ensureForViewer()` do the same work sooner where they can see it.
	 *
	 * @return int how many lists were looked at
	 */
	public function reconcile(): int {
		$seen = 0;
		foreach ($this->listsRequest->getGroupIds() as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				$this->listsRequest->deleteByGroup($groupId);
				continue;
			}

			$members = $this->memberActorIds($group);
			$title = ListsRequest::normaliseTitle($group->getDisplayName());
			foreach ($this->listsRequest->getByGroup($groupId) as $list) {
				$seen++;
				if ($list->getTitle() !== $title) {
					$list->setTitle($title);
					$this->listsRequest->update($list);
				}
				$this->setMembers($list, $members);
			}
		}

		return $seen;
	}

	/** Somebody joined a group: into every list that follows it. */
	public function onUserAdded(IGroup $group, IUser $user): void {
		$actorId = $this->actorIdOf($user->getUID());
		if ($actorId === null) {
			// no Social account yet; the cron picks them up if they get one
			return;
		}

		foreach ($this->listsRequest->getByGroup($group->getGID()) as $list) {
			if ($list->getOwnerId() !== $actorId) {
				$this->listsRequest->addMember($list, $actorId);
			}
		}
	}

	/** Somebody left a group: out of every list that follows it, and their own goes. */
	public function onUserRemoved(IGroup $group, IUser $user): void {
		$actorId = $this->actorIdOf($user->getUID());
		if ($actorId === null) {
			return;
		}

		foreach ($this->listsRequest->getByGroup($group->getGID()) as $list) {
			if ($list->getOwnerId() === $actorId) {
				$this->listsRequest->delete($list);
			} else {
				$this->listsRequest->removeMember($list, $actorId);
			}
		}
	}

	public function onGroupDeleted(IGroup $group): void {
		$this->listsRequest->deleteByGroup($group->getGID());
	}

	public function onGroupRenamed(IGroup $group): void {
		$this->listsRequest->updateTitleByGroup(
			$group->getGID(), ListsRequest::normaliseTitle($group->getDisplayName())
		);
	}

	/**
	 * The Social accounts of the group's members, as actor ids. Empty for a
	 * group too large to be a list.
	 *
	 * @return list<string>
	 */
	public function memberActorIds(IGroup $group): array {
		if (!$this->isListable($group)) {
			return [];
		}

		$ids = [];
		foreach ($group->getUsers() as $user) {
			$actorId = $this->actorIdOf($user->getUID());
			if ($actorId !== null) {
				$ids[] = $actorId;
			}
		}

		return $ids;
	}

	private function isListable(IGroup $group): bool {
		$count = $group->count();
		if ($count === false) {
			// a backend that cannot count; counting what it can list is the
			// same answer, one round trip later
			$count = count($group->getUsers());
		}

		return $count <= self::MAX_GROUP_SIZE;
	}

	/** Membership becomes exactly `$actorIds`, the owner excepted. */
	private function setMembers(MastodonList $list, array $actorIds): void {
		$wanted = array_values(array_diff(array_unique($actorIds), [$list->getOwnerId()]));
		$current = $this->listsRequest->getMemberIds($list);

		foreach (array_diff($wanted, $current) as $actorId) {
			$this->listsRequest->addMember($list, $actorId);
		}
		foreach (array_diff($current, $wanted) as $actorId) {
			$this->listsRequest->removeMember($list, $actorId);
		}
	}

	private function actorIdOf(string $userId): ?string {
		try {
			return $this->actorsRequest->getFromUserId($userId)->getId();
		} catch (ActorDoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->debug('[GroupListService] could not resolve an actor', ['userId' => $userId, 'exception' => $e]);

			return null;
		}
	}
}
