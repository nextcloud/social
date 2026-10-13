/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The element that has the keyboard focus, remembered before a dialog takes
 * it. A dialog's focus trap gives the focus back to whatever had it when the
 * trap was set, and when that was a menu item the menu has since taken away,
 * the focus lands on the page body.
 *
 * @return {HTMLElement|null} the focused element, or null when it is the page body
 */
export function focusedElement() {
	const active = document.activeElement

	return active instanceof HTMLElement && active !== document.body ? active : null
}

/**
 * Puts the focus back on the element a dialog was opened from, if it is
 * still on the page.
 *
 * @param {HTMLElement|null|undefined} element what had the focus
 * @return {boolean} whether it has it again
 */
export function returnFocusTo(element) {
	if (!(element instanceof HTMLElement) || !element.isConnected) {
		return false
	}
	element.focus()

	return document.activeElement === element
}
