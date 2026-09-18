/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The element that scrolls. The window does not: Nextcloud gives an app a
 * fixed viewport and the content column scrolls inside it, so `window.scrollY`
 * is 0 wherever the reader is and `window.scrollTo()` moves nothing.
 *
 * @return {Element|null} the column, or null before the app has rendered
 */
export function scroller() {
	return document.querySelector('#app-content-vue')
}

/**
 * How far down the reader is.
 *
 * @return {number} the offset, in pixels
 */
export function scrollOffset() {
	const column = scroller()

	return column === null ? window.scrollY : column.scrollTop
}
