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
 *
 * The server pushes once per post that reaches the account, and each
 * subscriber answers a push with requests of its own — the sidebar with three.
 * Someone who follows a few hundred active accounts was pushed every few
 * seconds, in every open tab, hidden ones too. So the pushes are gathered
 * here before anybody hears of them: a run of them is handed on once it has
 * been quiet for `PUSH_SETTLE_MS` (and no later than `PUSH_MAX_WAIT_MS` after
 * the first, so a steady stream still gets through); a tab nobody is looking
 * at hands on nothing and catches up once when it is looked at again; and a
 * subscriber whose last answer is still on its way is not asked again until
 * it has arrived, and then once for everything pushed meanwhile.
 */

/** How long the pushes have to be quiet before they are handed on, in milliseconds. */
export const PUSH_SETTLE_MS = 3000

/** The longest a run of pushes is held back, from the first of them, in milliseconds. */
export const PUSH_MAX_WAIT_MS = 10000

/** Whether the server has notify_push, or null before it has been asked. */
let available = null

/** The timer that hands the gathered pushes on, while one is waiting. */
let timer = null

/** When the first push of the run being gathered came, 0 for none. */
let firstAt = 0

/** Whether a push came while the tab was hidden. */
let missed = false

/** @type {Map<() => unknown, () => void>} each subscriber => what the bus runs for it */
const subscribers = new Map()

/** A push from the server. */
function pushed() {
	if (document.visibilityState === 'hidden') {
		missed = true
		return
	}

	const now = Date.now()
	firstAt = firstAt || now
	clearTimeout(timer)
	timer = setTimeout(handOn, Math.min(PUSH_SETTLE_MS, firstAt + PUSH_MAX_WAIT_MS - now))
}

/** Tells the subscribers, once for the run that was gathered. */
function handOn() {
	timer = null
	firstAt = 0
	if (document.visibilityState === 'hidden') {
		missed = true
		return
	}

	eventBus.emit(TIMELINE_PUSHED)
}

/** Catches up once, on the way back to a tab that was pushed to while hidden. */
function catchUp() {
	if (document.visibilityState === 'visible' && missed) {
		missed = false
		pushed()
	}
}

/**
 * The subscriber as the bus runs it: never twice at once. A push that comes
 * while its answer is on the way runs it once more when that answer is in.
 *
 * @param {() => (Promise<unknown>|unknown)} handler the subscriber
 * @return {() => void} what the bus runs
 */
function oneAtATime(handler) {
	let running = false
	let again = false

	const run = () => {
		if (running) {
			again = true
			return
		}

		running = true
		const done = () => {
			running = false
			if (again) {
				again = false
				run()
			}
		}
		try {
			Promise.resolve(handler()).then(done, done)
		} catch {
			done()
		}
	}

	return run
}

/**
 * Subscribes to the server's word that a timeline has something new.
 *
 * @param {() => (Promise<unknown>|unknown)} handler what to run — a promise it
 *        returns is waited for before it is run again; the same function
 *        unsubscribes
 * @return {boolean} whether push is available — if not, the caller has to poll
 */
export function onTimelinePush(handler) {
	if (available === null) {
		available = listen('social_timeline', pushed)
		document.addEventListener('visibilitychange', catchUp)
	}

	if (!subscribers.has(handler)) {
		const run = oneAtATime(handler)
		subscribers.set(handler, run)
		eventBus.on(TIMELINE_PUSHED, run)
	}

	return available
}

/**
 * @param {() => (Promise<unknown>|unknown)} handler the function that was subscribed
 */
export function offTimelinePush(handler) {
	const run = subscribers.get(handler)
	if (run !== undefined) {
		eventBus.off(TIMELINE_PUSHED, run)
		subscribers.delete(handler)
	}
}
