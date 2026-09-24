/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'

/**
 * Keys for what this app keeps in the browser.
 *
 * `localStorage` belongs to the origin, not to the session: it survives a
 * logout, and every account that signs in to this Nextcloud in this browser
 * profile reads the same keys. What this app keeps there is somebody's — an
 * unsent draft above all, but also which notifications they were looking at
 * and how far down a timeline they had read — so the key has to say whose.
 *
 * Signed out there is no uid, and the key is the bare name. Nothing writes
 * anything there worth keeping, and a session that is over has no reader to
 * keep it for.
 *
 * @param {string} name what is being kept
 * @return {string} the key to keep it under
 */
export function userKey(name) {
	const uid = getCurrentUser()?.uid ?? ''

	return uid === '' ? name : `${name}::${uid}`
}

/**
 * Removes a key that was written before this app scoped what it keeps.
 *
 * Deleted rather than adopted: whoever wrote it is not necessarily whoever is
 * reading now, and handing one account's unsent words to the next account to
 * sign in on the same computer is the thing being fixed.
 *
 * @param {string} name the old, unscoped key
 * @return {boolean} whether the store could be reached
 */
export function forgetUnscoped(name) {
	try {
		window.localStorage.removeItem(name)

		return true
	} catch {
		return false
	}
}
