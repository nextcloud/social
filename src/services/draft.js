/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What is in the composer, kept where a failed post, a reload or a navigation
 * cannot take it away.
 *
 * The composer used to clear itself in a `finally`, so an offline moment, a
 * 500 or a rate limit meant a toast and an empty box: the text was gone. The
 * app also keys its router-view on the full path, so any navigation unmounts
 * the composer — the draft has to outlive the component, not just the request.
 *
 * Only the words are kept. Attachments are uploaded media the server already
 * holds a handle to, and restoring an id whose upload may have been reaped
 * would fail on the next send; a draft that says what you wrote is the part
 * worth keeping.
 */

const KEY = 'social.composer.draft'

/** a draft older than this is stale enough to be somebody else's day */
const MAX_AGE_MS = 7 * 24 * 3600 * 1000

/**
 * @typedef {object} ComposerDraft
 * @property {string} text what was typed, as plain text
 * @property {string} spoilerText the content warning, '' when there is none
 * @property {string} visibility who it was going to
 * @property {number} savedAt when it was last written, epoch milliseconds
 */

/**
 * Remembers a draft, replacing any previous one.
 *
 * @param {object} draft the draft to keep
 * @param {string} draft.text what was typed
 * @param {string} [draft.spoilerText] the content warning
 * @param {string} [draft.visibility] who it is going to
 * @return {boolean} whether it could be stored
 */
export function saveDraft({ text, spoilerText = '', visibility = '' }) {
	if ((text ?? '').trim() === '' && spoilerText.trim() === '') {
		return clearDraft()
	}

	try {
		window.localStorage.setItem(KEY, JSON.stringify({
			text,
			spoilerText,
			visibility,
			savedAt: Date.now(),
		}))
		return true
	} catch {
		// a private window, or storage that is full: losing the draft is bad,
		// but not being able to write a post at all would be worse
		return false
	}
}

/**
 * @return {ComposerDraft|null} the kept draft, or null when there is none
 */
export function loadDraft() {
	let raw
	try {
		raw = window.localStorage.getItem(KEY)
	} catch {
		return null
	}

	if (!raw) {
		return null
	}

	let draft
	try {
		draft = JSON.parse(raw)
	} catch {
		clearDraft()
		return null
	}

	if (draft === null || typeof draft !== 'object' || typeof draft.text !== 'string') {
		clearDraft()
		return null
	}

	if (typeof draft.savedAt === 'number' && Date.now() - draft.savedAt > MAX_AGE_MS) {
		clearDraft()
		return null
	}

	return {
		text: draft.text,
		spoilerText: typeof draft.spoilerText === 'string' ? draft.spoilerText : '',
		visibility: typeof draft.visibility === 'string' ? draft.visibility : '',
		savedAt: typeof draft.savedAt === 'number' ? draft.savedAt : 0,
	}
}

/**
 * Forgets the draft, which is what a post that actually went out means.
 *
 * @return {boolean} whether the store could be reached
 */
export function clearDraft() {
	try {
		window.localStorage.removeItem(KEY)
		return true
	} catch {
		return false
	}
}
