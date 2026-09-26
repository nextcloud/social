<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCP\IGroupManager;

/**
 * What this instance offers: the sections of the app, and the Nextcloud groups
 * that become lists.
 *
 * Separate from ServerSettingsService, which is about how the server behaves
 * towards its peers -- what it will accept, what it will publish, how it
 * stores what it is given. These are about what the people using it are shown,
 * and an administrator turning off Videos is not making a decision about
 * federation.
 *
 * Turning a section off hides it and stops it being offered. It does not touch
 * what is already there: the posts remain what they are, and turning the
 * section back on shows them again.
 */
class SectionsService {
	/** The keys this card owns, in the order the card shows them. */
	public const KEYS = [
		ConfigService::SOCIAL_STORIES,
		ConfigService::SOCIAL_SECTION_PHOTOS,
		ConfigService::SOCIAL_SECTION_VIDEOS,
		ConfigService::SOCIAL_GROUP_LISTS,
	];

	/**
	 * More than this many groups chosen and the list is not a choice any more.
	 * It is also the point past which every member's sidebar is unusable.
	 */
	public const MAX_GROUPS = 100;

	public function __construct(
		private ConfigService $configService,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * What stands now, typed the way the page and the endpoint hand it back.
	 *
	 * @return array{stories: bool, section_photos: bool, section_videos: bool,
	 *     group_lists: string[]}
	 */
	public function current(): array {
		return [
			ConfigService::SOCIAL_STORIES => $this->configService->getAppValueBool(ConfigService::SOCIAL_STORIES),
			ConfigService::SOCIAL_SECTION_PHOTOS
				=> $this->configService->getAppValueBool(ConfigService::SOCIAL_SECTION_PHOTOS),
			ConfigService::SOCIAL_SECTION_VIDEOS
				=> $this->configService->getAppValueBool(ConfigService::SOCIAL_SECTION_VIDEOS),
			ConfigService::SOCIAL_GROUP_LISTS => $this->groupLists(),
		];
	}

	/**
	 * The groups that become lists.
	 *
	 * Read through here rather than from the config directly, because what is
	 * stored is JSON and what has been stored includes a group that has since
	 * been deleted. A missing group is dropped on the way out rather than
	 * written back: the administrator may be about to recreate it, and a read
	 * is not the place to decide they were not.
	 *
	 * @return string[] group ids, in the order they were chosen
	 */
	public function groupLists(): array {
		$stored = json_decode((string)$this->configService->getAppValue(ConfigService::SOCIAL_GROUP_LISTS), true);
		if (!is_array($stored)) {
			return [];
		}

		$groups = [];
		foreach ($stored as $groupId) {
			if (is_string($groupId) && $this->groupManager->groupExists($groupId)) {
				$groups[] = $groupId;
			}
		}

		return $groups;
	}

	/**
	 * Whether a group's members get a list for it.
	 *
	 * @param string $groupId the group to ask about
	 */
	public function groupHasList(string $groupId): bool {
		return in_array($groupId, $this->groupLists(), true);
	}

	/** Whether stories are offered at all. */
	public function storiesEnabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_STORIES);
	}

	/**
	 * Validates every value and writes them all, or writes none.
	 *
	 * All-or-nothing for the same reason ServerSettingsService is: a form that
	 * saved four of its five fields leaves the administrator guessing which.
	 *
	 * @param bool $stories whether stories are offered
	 * @param bool $photos whether the Photos timeline is offered
	 * @param bool $videos whether the Videos timeline is offered
	 * @param string[] $groupLists the Nextcloud groups that become lists
	 *
	 * @return array what current() answers afterwards
	 * @throws InvalidArgumentException naming the field that was refused
	 */
	public function save(bool $stories, bool $photos, bool $videos, array $groupLists): array {
		$groups = [];
		foreach ($groupLists as $groupId) {
			if (!is_string($groupId)) {
				throw new InvalidArgumentException('group_lists must be a list of group ids');
			}
			$groupId = trim($groupId);
			if ($groupId === '' || in_array($groupId, $groups, true)) {
				continue;
			}
			// refused rather than dropped: an id that names no group is a
			// mistake in what was sent, and silently saving four of the five
			// groups somebody picked is the failure this method exists to
			// avoid
			if (!$this->groupManager->groupExists($groupId)) {
				throw new InvalidArgumentException('no such group: ' . $groupId);
			}
			$groups[] = $groupId;
		}

		if (count($groups) > self::MAX_GROUPS) {
			throw new InvalidArgumentException('at most ' . self::MAX_GROUPS . ' groups may become lists');
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_STORIES, $stories ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_SECTION_PHOTOS, $photos ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_SECTION_VIDEOS, $videos ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_GROUP_LISTS, json_encode($groups));

		return $this->current();
	}
}
