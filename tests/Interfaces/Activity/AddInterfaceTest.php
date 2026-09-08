<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Add;

require_once __DIR__ . '/DispatchingActivityTestCase.php';

class AddInterfaceTest extends DispatchingActivityTestCase {
	protected function createHandler(): IActivityPubInterface {
		return new AddInterface();
	}

	protected function activityType(): string {
		return Add::TYPE;
	}
}
