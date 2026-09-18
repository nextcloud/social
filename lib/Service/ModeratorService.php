<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Settings\Service\AuthorizedGroupService;
use OCA\Social\Settings\AdminSection;
use OCA\Social\Settings\AdminSettings;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who may moderate here, and who has to be told about a report.
 *
 * A moderator is a Nextcloud administrator or whoever the administrator has
 * delegated the Social settings section to — the rule the moderation routes
 * already enforce through `AuthorizedAdminSetting`. Everything that has to
 * answer the same question asks this, so there is no second list of moderators
 * to keep in step with the routes: the dashboard widgets used to offer
 * themselves to the `admin` group alone, and a new report told nobody else
 * either, so a delegated moderator was given the job and none of the ways of
 * hearing that there was work to do.
 */
class ModeratorService {
	public function __construct(
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ISettingsManager $settingsManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	public function isModerator(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return false;
		}

		return $this->settingsManager->getAllowedAdminSettings(
			AdminSection::SECTION_ID, $user
		) !== [];
	}

	/**
	 * Every user who may act on a report, without a pass over the user table.
	 *
	 * Read from the groups that hold the right rather than by asking every
	 * account on the instance whether it has it: a report is filed by anything
	 * that can reach the inbox, and one walk of `social_actor`'s worth of
	 * Nextcloud users per `Flag` is a denial of service with a moderation panel
	 * attached.
	 *
	 * @return string[] user ids, each once
	 */
	public function moderators(): array {
		$userIds = [];
		foreach (array_merge(['admin'], $this->delegatedGroups()) as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$userIds[$user->getUID()] = true;
			}
		}

		return array_keys($userIds);
	}

	/**
	 * The groups the Social settings section has been delegated to.
	 *
	 * There is no interface in `OCP` that answers this — `IManager` asks about
	 * one user at a time, which is the question this one is here to avoid — so
	 * it comes from the settings app, and an installation where that lookup
	 * fails is one where the administrators alone are told.
	 *
	 * @return string[] group ids
	 */
	private function delegatedGroups(): array {
		try {
			/** @var AuthorizedGroupService $authorized */
			$authorized = $this->container->get(AuthorizedGroupService::class);
			$groups = [];
			foreach ($authorized->findExistingGroupsForClass(AdminSettings::class) as $group) {
				$groups[] = $group->getGroupId();
			}

			return $groups;
		} catch (Throwable $e) {
			$this->logger->debug('cannot read who the Social settings are delegated to', [
				'exception' => $e,
			]);

			return [];
		}
	}
}
