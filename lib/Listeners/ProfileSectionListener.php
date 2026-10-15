<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use Exception;
use OCA\Social\Db\ActorsRequest;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Profile\BeforeTemplateRenderedEvent;

/**
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class ProfileSectionListener implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private ActorsRequest $actorsRequest,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent)) {
			return;
		}

		// the page is a Nextcloud user's and knows them by their user id; the
		// account's username need not be it, and the section asks the API
		// by the username. '' where the user has no account here.
		try {
			$username = $this->actorsRequest->getFromUserId($event->getUserId())->getPreferredUsername();
		} catch (Exception) {
			$username = '';
		}
		$this->initialState->provideInitialState('profileAccount', $username);

		// the framework this entry was built without; see webpack.common.js
		\OCP\Util::addScript('social', 'social-framework');
		\OCP\Util::addScript('social', 'social-profilePage');
	}
}
