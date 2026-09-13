/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Toasts, fetched when something actually has to be said.
 *
 * `@nextcloud/dialogs` re-exports its whole surface — the file picker, the
 * conflict picker, the dialog builder, the icon set they use — from a single
 * module, so importing `showError` from it cannot be tree-shaken down to the
 * toast: it puts **259 KB** into the bundle, 18% of this app's main entry, to
 * say "Could not load the timeline".
 *
 * Nothing waits for a toast, so nothing here needs to be awaited: each call
 * returns a promise that the caller is free to ignore, and the library arrives
 * in its own chunk the first time one is shown. The import is memoised by the
 * module system, so the second toast is immediate.
 *
 * The signatures are `@nextcloud/dialogs`' own, forwarded unchanged.
 */

/** @return {Promise<object>} the dialogs module, loaded once */
function dialogs() {
	return import(/* webpackChunkName: "toast" */ '@nextcloud/dialogs')
}

/**
 * @param {string} text what went wrong
 * @param {object} [options] as `@nextcloud/dialogs` takes them
 * @return {Promise<object>} the toast, for a caller that wants to hide it
 */
export async function showError(text, options) {
	return (await dialogs()).showError(text, options)
}

/**
 * @param {string} text what worked
 * @param {object} [options] as `@nextcloud/dialogs` takes them
 * @return {Promise<object>} the toast
 */
export async function showSuccess(text, options) {
	return (await dialogs()).showSuccess(text, options)
}

/**
 * @param {string} text what the reader should know about
 * @param {object} [options] as `@nextcloud/dialogs` takes them
 * @return {Promise<object>} the toast
 */
export async function showWarning(text, options) {
	return (await dialogs()).showWarning(text, options)
}

/**
 * @param {string} text what happened
 * @param {object} [options] as `@nextcloud/dialogs` takes them
 * @return {Promise<object>} the toast
 */
export async function showInfo(text, options) {
	return (await dialogs()).showInfo(text, options)
}
