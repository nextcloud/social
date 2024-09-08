<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Profile\BeforeTemplateRenderedEvent;
use OCP\Util;

/**
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class ProfileSectionListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent)) {
			return;
		}
		Util::addScript('social', 'social-profilePage');
	}
}
