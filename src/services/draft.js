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
 * Everything the box holds is kept: the words, the warning, the audience, the
 * language, who it is written as and who may reply, the poll, the time it is
 * scheduled for, the place, a video's title and the card. Attachments are kept
 * by the id of their upload, with what was written about them, where their
 * subject is and the filter chosen for them; the composer asks the server
 * about each id again when the draft comes back, since an upload nobody posted
 * may have been cleared away in the meantime. An upload that has not come
 * back yet has no id to keep, which is what the composer's leave guard is for.
 */

import { forgetUnscoped, userKey } from '../utils/browserStore.js'
import { isFocalPoint } from '../utils/focalPoint.js'

const NAME = 'social.composer.draft'

/**
 * The key a draft was kept under before drafts were scoped to an account.
 * `localStorage` outlives a logout, so one account's unsent words were offered
 * to the next account to sign in on the same computer. It is removed rather
 * than adopted: there is no way to tell whose it was.
 */
const UNSCOPED_KEY = NAME

/**
 * What the draft is kept under, for whoever is signed in and for what is
 * being written.
 *
 * Read at each call rather than once: the module is evaluated when the page
 * loads, and the same page is not necessarily the same session by the time
 * somebody is typing in it.
 *
 * A reply and a top-level post are two drafts, not one slot: with one, a
 * half-written reply opened over a half-written post took its place, and
 * sending either threw the other away. The context is what the composer is
 * answering or quoting; '' is a post of its own, under the key drafts have
 * always had.
 *
 * @param {string} context what the draft belongs to, '' for a post of its own
 * @return {string} the key
 */
function key(context) {
	return userKey(context === '' ? NAME : `${NAME}.${context}`)
}

/** a draft older than this is stale enough to be somebody else's day */
const MAX_AGE_MS = 7 * 24 * 3600 * 1000

/**
 * @typedef {object} DraftMedia
 * @property {import('../types/Mastodon.js').MediaAttachment} data the upload,
 *   as the server last described it
 * @property {string} description the alt text as typed, which may not be
 *   saved on the server yet
 * @property {?import('../utils/focalPoint.js').FocalPoint} focus where the
 *   subject is, null for none
 * @property {string} filter the filter chosen, '' for none
 */

/**
 * @typedef {object} DraftPoll
 * @property {string[]} options the options as typed, empty ones included
 * @property {boolean} multiple whether more than one may be chosen
 * @property {number} expiresIn how long it runs, in seconds
 */

/**
 * @typedef {object} ComposerDraft
 * @property {string} text what was typed, as plain text
 * @property {string} spoilerText the content warning, '' when there is none
 * @property {string} visibility who it was going to
 * @property {string} postAs the team account it was being written as, '' for
 *   the reader's own
 * @property {DraftMedia[]} media the attachments, in order
 * @property {?DraftPoll} poll the poll, null when there is none
 * @property {string} scheduledAt when it is to go out, as an ISO date, '' for now
 * @property {string} language the language it is written in, '' for the default
 * @property {string} replyPolicy who may reply, '' for the default
 * @property {?object} place where it was taken, as the place picker holds it
 * @property {?{title: string, category: string, licence: string}} video what
 *   a video post is called, null when nothing was written
 * @property {string} card the background of a post sent as a card, '' for a
 *   plain post
 * @property {number} savedAt when it was last written, epoch milliseconds
 */

/**
 * Remembers a draft, replacing any previous one in the same context.
 *
 * Kept while it holds anything that would be missed: words, a warning, an
 * attachment or a poll option. The audience or the language alone is a
 * setting, not a draft.
 *
 * @param {Partial<ComposerDraft>} draft the draft to keep; `text` is required
 * @param {string} [context] what it belongs to — see `key()`
 * @return {boolean} whether it could be stored
 */
export function saveDraft({
	text,
	spoilerText = '',
	visibility = '',
	postAs = '',
	media = [],
	poll = null,
	scheduledAt = '',
	language = '',
	replyPolicy = '',
	place = null,
	video = null,
	card = '',
}, context = '') {
	const pollWritten = poll !== null && poll.options.some((option) => option.trim() !== '')
	if ((text ?? '').trim() === '' && spoilerText.trim() === '' && media.length === 0 && !pollWritten) {
		return clearDraft(context)
	}

	try {
		window.localStorage.setItem(key(context), JSON.stringify({
			text,
			spoilerText,
			visibility,
			// the composer has always sent this and this function has always
			// dropped it, so the line it has that reads it back — "a reload
			// does not quietly turn a team post back into a personal one" —
			// could never be true
			postAs,
			media,
			poll,
			scheduledAt,
			language,
			replyPolicy,
			place,
			video,
			card,
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
 * @param {string} [context] what the draft belongs to — see `key()`
 * @return {ComposerDraft|null} the kept draft, or null when there is none
 */
export function loadDraft(context = '') {
	let raw
	try {
		// signed out there is nothing to separate, and the old key is the
		// one a post of its own is kept under — forgetting it there would
		// delete that draft, whichever context is being read
		if (key('') !== UNSCOPED_KEY) {
			forgetUnscoped(UNSCOPED_KEY)
		}

		raw = window.localStorage.getItem(key(context))
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
		clearDraft(context)
		return null
	}

	if (draft === null || typeof draft !== 'object' || typeof draft.text !== 'string') {
		clearDraft(context)
		return null
	}

	if (typeof draft.savedAt === 'number' && Date.now() - draft.savedAt > MAX_AGE_MS) {
		clearDraft(context)
		return null
	}

	// each field is checked on its own: a draft written by another version
	// of the composer may lack one or hold it in another shape, and that is
	// no reason to throw away the words that came with it
	return {
		text: draft.text,
		spoilerText: stringOr(draft.spoilerText),
		visibility: stringOr(draft.visibility),
		postAs: stringOr(draft.postAs),
		media: Array.isArray(draft.media) ? draft.media.map(mediaOf).filter((item) => item !== null) : [],
		poll: pollOf(draft.poll),
		scheduledAt: stringOr(draft.scheduledAt),
		language: stringOr(draft.language),
		replyPolicy: stringOr(draft.replyPolicy),
		place: isObject(draft.place) ? draft.place : null,
		video: isObject(draft.video)
			? { title: stringOr(draft.video.title), category: stringOr(draft.video.category), licence: stringOr(draft.video.licence) }
			: null,
		card: stringOr(draft.card),
		savedAt: typeof draft.savedAt === 'number' ? draft.savedAt : 0,
	}
}

/**
 * @param {unknown} value anything
 * @return {string} it when it is a string, '' otherwise
 */
function stringOr(value) {
	return typeof value === 'string' ? value : ''
}

/**
 * @param {unknown} value anything
 * @return {value is Record<string, unknown>} whether it is a plain object rather
 *   than null or a list
 */
function isObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * @param {unknown} item one kept attachment
 * @return {DraftMedia|null} it, or null when it names no upload
 */
function mediaOf(item) {
	if (!isObject(item) || !isObject(item.data)) {
		return null
	}
	const id = item.data.id
	if ((typeof id !== 'string' && typeof id !== 'number') || String(id) === '') {
		return null
	}

	return {
		data: /** @type {import('../types/Mastodon.js').MediaAttachment} */ (item.data),
		description: stringOr(item.description),
		focus: isFocalPoint(item.focus) ? /** @type {import('../utils/focalPoint.js').FocalPoint} */ (item.focus) : null,
		filter: stringOr(item.filter),
	}
}

/**
 * @param {unknown} poll the kept poll
 * @return {DraftPoll|null} it, or null when there is none
 */
function pollOf(poll) {
	if (!isObject(poll) || !Array.isArray(poll.options)) {
		return null
	}

	const expiresIn = typeof poll.expiresIn === 'number' && Number.isFinite(poll.expiresIn) && poll.expiresIn > 0
		? poll.expiresIn
		: 86400

	return {
		options: poll.options.map(stringOr),
		multiple: poll.multiple === true,
		expiresIn,
	}
}

/**
 * Forgets the draft, which is what a post that actually went out means.
 *
 * @param {string} [context] what the draft belongs to — see `key()`
 * @return {boolean} whether the store could be reached
 */
export function clearDraft(context = '') {
	try {
		window.localStorage.removeItem(key(context))
		return true
	} catch {
		return false
	}
}
