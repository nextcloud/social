<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\ActivityPubFormatException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TStringTools;

class ImportService {
	use TArrayTools;
	use TStringTools;


	private ConfigService $configService;

	private MiscService $miscService;


	/**
	 * ImportService constructor.
	 *
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(ConfigService $configService, MiscService $miscService) {
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $json
	 *
	 * @return ACore
	 * @throws ActivityPubFormatException
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 */
	public function importFromJson(string $json): ACore {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new ActivityPubFormatException();
		}

		return AP::$activityPub->getItemFromData($data);
	}


	/**
	 * @param ACore $activity
	 *
	 * @throws ItemUnknownException
	 * @throws InvalidOriginException
	 */
	public function parseIncomingRequest(ACore $activity) {
		$activity->checkOrigin($activity->getId());
		$activity->setRequestToken($this->uuid());

		$interface = AP::$activityPub->getInterfaceForItem($activity);
		try {
			$interface->processIncomingRequest($activity);
		} catch (Exception $e) {
			$this->miscService->log(
				'Cannot parse ' . $activity->getType() . ': ' . get_class($e) . ' '
				. $e->getMessage()
			);
		}
	}
}
