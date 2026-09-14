/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether the app is on a phone-sized screen.
 *
 * One media query, shared by every component that lays itself out differently
 * on a phone, so they all switch at the same width — the same number the
 * stylesheets use in their `@media (max-width: …)` rules. Nextcloud's own
 * "mobile" breakpoint (1024px, where the sidebar collapses) is not it: a
 * tablet in portrait has the sidebar collapsed and still has room for the
 * avatar column beside a post. A phone does not.
 */
export const PHONE_WIDTH = 600

const query = (typeof window !== 'undefined' && typeof window.matchMedia === 'function')
	? window.matchMedia(`(max-width: ${PHONE_WIDTH}px)`)
	: null

/**
 * @return {boolean} whether the viewport is phone-sized right now
 */
export function isPhone() {
	return query?.matches ?? false
}

/**
 * Calls back whenever the answer changes — a rotation, a resized window.
 *
 * @param {(phone: boolean) => void} callback told the new answer
 * @return {() => void} stops the calls
 */
export function onPhoneChange(callback) {
	if (query === null) {
		return () => {}
	}
	const handler = (event) => callback(event.matches)
	query.addEventListener('change', handler)
	return () => query.removeEventListener('change', handler)
}
