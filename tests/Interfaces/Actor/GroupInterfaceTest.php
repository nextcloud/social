<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Actor;

use OCA\Social\Interfaces\Actor\GroupInterface;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Person;

require_once __DIR__ . '/ActorInterfaceTestCase.php';

class GroupInterfaceTest extends ActorInterfaceTestCase {
	protected function createHandler(): PersonInterface {
		return new GroupInterface(
			$this->actionsRequest,
			$this->cacheActorsRequest,
			$this->cacheDocumentsRequest,
			$this->followsRequest,
			$this->actorRelationRequest,
			$this->requestQueueRequest,
			$this->streamRequest,
			$this->streamDestRequest,
			$this->actorService,
			$this->configService,
			$this->streamActionsRequest,
			$this->reportsRequest,
			$this->filtersRequest,
			$this->listsRequest,
			$this->conversationsRequest,
			$this->featuredTagsRequest,
			$this->announcementsRequest,
			$this->scheduledStatusesRequest,
			$this->jobList,
		);
	}

	protected function createActor(): Person {
		return new Group();
	}
}
