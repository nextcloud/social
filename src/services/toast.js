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

/**
 * The stylesheet travels with the library, in the same chunk.
 *
 * `@nextcloud/dialogs` 7 builds a toast out of CSS-module class names --
 * `_toastContainer_1biev_1`, `_toast_v43ag_11` -- and ships the rules for them
 * in a stylesheet of its own. The server's `core/css/toast.css` styles
 * `.toastify.toast`, which is the markup an older major produced, and matches
 * none of them; no other stylesheet this app loads defines them either. Without
 * this import a toast is a block with no position, no background and no
 * shadow, which the page then lays out where any unstyled block goes: the top
 * left corner.
 *
 * Imported here rather than at the top of the module so it lands in the `toast`
 * chunk with the code that needs it, instead of in the entry this file was
 * split out of to keep small.
 *
 * @return {Promise<object>} the dialogs module, loaded once
 */
function dialogs() {
	return Promise.all([
		import(/* webpackChunkName: "toast" */ '@nextcloud/dialogs'),
		import(/* webpackChunkName: "toast" */ '@nextcloud/dialogs/style.css'),
	]).then(([module]) => module)
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
