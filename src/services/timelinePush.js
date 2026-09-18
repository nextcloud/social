/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { listen } from '@nextcloud/notify_push'
import eventBus, { TIMELINE_PUSHED } from './eventBus.js'

/**
 * `listen()` has no counterpart: what it registers stays registered for the
 * life of the page. A component that called it per mount therefore left a
 * closure holding itself behind on every unmount — and every open thread
 * mounts a timeline list of its own — so after a few navigations one pushed
 * event ran the same timeline request several times over.
 *
 * So it is called once here, for the page, and the event is handed on over the
 * bus, which components can leave.
 */

/** Whether the server has notify_push, or null before it has been asked. */
let available = null

/**
 * Subscribes to the server's word that a timeline has something new.
 *
 * @param {() => void} handler what to run; the same function unsubscribes
 * @return {boolean} whether push is available — if not, the caller has to poll
 */
export function onTimelinePush(handler) {
	if (available === null) {
		available = listen('social_timeline', () => eventBus.emit(TIMELINE_PUSHED))
	}

	eventBus.on(TIMELINE_PUSHED, handler)

	return available
}

/**
 * @param {() => void} handler the function that was subscribed
 */
export function offTimelinePush(handler) {
	eventBus.off(TIMELINE_PUSHED, handler)
}
