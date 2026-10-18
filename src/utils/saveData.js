/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether the reader has asked their browser to spend less data.
 *
 * Two ways of asking: the Save-Data switch Chrome and Android expose as
 * `navigator.connection.saveData`, and the `prefers-reduced-data` media query.
 * This app has no setting of its own for it; what is fetched ahead of the
 * reader is the thing to hold back, and the browser's lazy loading still
 * fetches whatever comes into view.
 *
 * @return {boolean}
 */
export function savesData() {
	const browser = /** @type {Navigator & {connection?: {saveData?: boolean}}} */ (navigator)
	if (browser.connection?.saveData === true) {
		return true
	}

	return typeof window.matchMedia === 'function'
		&& window.matchMedia('(prefers-reduced-data: reduce)').matches
}
