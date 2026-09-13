<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Puts "Share to Social" into the Files app.
 *
 * The composer could already attach a picture that is on the server; this is
 * the same road from the other end, offered where the picture is being looked
 * at. The script is loaded on every Files page, so it is the one entry built
 * without the shared framework chunk (see `webpack.common.js`) and is loaded
 * alone, on purpose: a few KB to register an action, not a megabyte for a
 * post most of those pages will never write.
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class FilesScriptsListener implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}

		// an init script: registered before the file list renders, so the
		// action is there on the first paint rather than after it
		Util::addInitScript('social', 'social-filesAction');
	}
}
