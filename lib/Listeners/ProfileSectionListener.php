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

/**
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class ProfileSectionListener implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent)) {
			return;
		}
		// the framework this entry was built without; see webpack.common.js
		\OCP\Util::addScript('social', 'social-framework');
		\OCP\Util::addScript('social', 'social-profilePage');
	}
}
