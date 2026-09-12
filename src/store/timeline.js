/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

import logger from '../services/logger.js'
import { useAccountStore } from './account.js'

/** Where the browser remembers that this reader's first post was celebrated. */
export const FIRST_POST_KEY = 'social.firstPostCelebrated'

/**
 * Whether this browser has already seen the celebration.
 *
 * Reading localStorage throws outright in a private window and wherever site
 * data is blocked, which is why this answers rather than raises: an
 * unreadable store means this guard is simply gone, and the account's own post
 * count below is the one that actually keeps an established reader from being
 * congratulated.
 *
 * @return {boolean} whether the flag is set
 */
function alreadyCelebrated() {
	try {
		return window.localStorage.getItem(FIRST_POST_KEY) !== null
	} catch {
		return false
	}
}

/**
 * Remembers that it happened, if the browser will remember anything.
 */
function rememberCelebrated() {
	try {
		window.localStorage.setItem(FIRST_POST_KEY, String(Date.now()))
	} catch {
		// nothing to do about it: `firstPostCelebrated` in the state still
		// stops a second celebration for as long as this page is open
	}
}

/**
 * Indexes a status, and the status it boosts, by id.
 *
 * @param {object} state the store state
 * @param {object} status the status to index
 */
function indexStatus(state, status) {
	if (status === undefined || status === null || status.id === undefined) {
		return
	}

	// assigned in place: replacing the whole map per status meant fifteen full
	// copies of every status in memory for each page that loaded
	state.statuses[status.id] = status
	if (status.reblog !== undefined && status.reblog !== null) {
		state.statuses[status.reblog.id] = status.reblog
	}
}

/**
 * @param {object} state the store state
 * @param {string[]} ids the ids of one of the two lists
 * @return {object[]} the statuses those ids name, newest first
 */
function sortedByDate(state, ids) {
	return ids
		.map((statusId) => state.statuses[statusId])
		.filter(Boolean)
		.sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at))
}

/**
 * The list currently on screen: which one it is, what it holds, and everything
 * the reader does to a post in it.
 */
export const useTimelineStore = defineStore('timeline', {
	state: () => ({
		statuses: {},
		timeline: [],
		parentsTimeline: [],
		/** which list a removed status came from, so a rollback restores it there */
		removedFrom: {},
		type: 'home',
		/** @type {{tag?: string, id?: string, account?: string, scope?: string, media?: string}} */
		params: {},
		account: '',
		composerDisplayStatus: false,
		searchQuery: '',
		/** whether the one-time first-post celebration is on screen right now */
		firstPostCelebration: false,
		/**
		 * whether this session has already celebrated — the guard that still holds
		 * when the browser refuses to remember anything
		 */
		firstPostCelebrated: false,
	}),

	getters: {
		/**
		 * @param {object} state the store state
		 * @return {boolean} whether the composer is open
		 */
		getComposerDisplayStatus(state) {
			return state.composerDisplayStatus
		},
		/**
		 * The timeline, newest first.
		 *
		 * This used to also filter by `searchQuery` with String.includes over the
		 * ~15 statuses that happened to be loaded, which answered "No posts match
		 * your search" for posts the instance was holding. Searching now asks the
		 * server (`/api/v2/search`), so the timeline is only the timeline.
		 *
		 * @param {object} state the store state
		 * @return {object[]} the statuses
		 */
		getTimeline(state) {
			return sortedByDate(state, state.timeline)
		},
		/**
		 * @param {object} state the store state
		 * @return {object[]} the ancestors of the post on screen, newest first
		 */
		getParentsTimeline(state) {
			return sortedByDate(state, state.parentsTimeline)
		},
		/**
		 * @param {object} state the store state
		 * @return {string} what the reader searched for
		 */
		getSearchQuery(state) {
			return state.searchQuery
		},
		/**
		 * @param {object} state the store state
		 * @return {boolean} whether the celebration is on screen
		 */
		isCelebratingFirstPost(state) {
			return state.firstPostCelebration
		},
		/**
		 * What list the store is currently holding, as one comparable value.
		 *
		 * The router-view is no longer keyed on the full path, so a view — and the
		 * TimelineList inside it — is reused across a navigation. This is what
		 * tells the list that the thing it is showing has been swapped underneath
		 * it, whether by a type, a tag, an account or a single post.
		 *
		 * @param {object} state the store state
		 * @return {string} the identity of the current timeline
		 */
		getTimelineIdentity(state) {
			return JSON.stringify([state.type, state.account, state.params])
		},
		/**
		 * @param {object} state the store state
		 * @return {(statusId: string) => object|undefined} id -> status
		 */
		getStatus(state) {
			return (statusId) => state.statuses[statusId]
		},
		/**
		 * @param {object} state the store state
		 * @return {object|undefined} the post a single-post view is showing
		 */
		getSinglePost(state) {
			return state.statuses[state.params.singlePost]
		},
		/**
		 * @param {object} state the store state
		 * @return {(statusId: string) => object|undefined} id -> status, with a log line when there is none
		 */
		getPostFromTimeline(state) {
			return (statusId) => {
				if (state.statuses[statusId] !== undefined) {
					return state.statuses[statusId]
				} else {
					logger.warn('Could not find status in timeline', { statusId })
				}
			}
		},
	},

	actions: {
		addToStatuses(status) {
			indexStatus(this, status)
		},
		addToTimeline(data) {
			if (Array.isArray(data)) {
				data.forEach((status) => indexStatus(this, status))
				data
					.filter((status) => this.timeline.indexOf(status.id) === -1)
					.forEach((status) => this.timeline.push(status.id))
			} else {
				data.descendants.forEach((status) => indexStatus(this, status))
				data.ancestors.forEach((status) => indexStatus(this, status))

				data.descendants
					.filter((status) => this.timeline.indexOf(status.id) === -1)
					.forEach((status) => this.timeline.push(status.id))
				data.ancestors
					.filter((status) => this.parentsTimeline.indexOf(status.id) === -1)
					.forEach((status) => this.parentsTimeline.push(status.id))
			}
		},
		removeStatus(status) {
			const timelineIndex = this.timeline.indexOf(status.id)
			if (timelineIndex !== -1) {
				this.timeline.splice(timelineIndex, 1)
			}
			const parentsTimelineIndex = this.parentsTimeline.indexOf(status.id)
			if (parentsTimelineIndex !== -1) {
				this.parentsTimeline.splice(parentsTimelineIndex, 1)
			}
			// which list it came from, so a failed delete puts it back where it was
			this.removedFrom = { ...this.removedFrom, [status.id]: parentsTimelineIndex !== -1 ? 'parents' : 'timeline' }
			delete this.statuses[status.id]
		},
		/**
		 * Puts a status back after a delete the server refused. `addToTimeline`
		 * always appended to `timeline`, so a failed delete of an ancestor
		 * reappeared among the replies.
		 *
		 * @param {object} status the status that could not be deleted
		 */
		restoreStatus(status) {
			indexStatus(this, status)
			const list = this.removedFrom?.[status.id] === 'parents' ? 'parentsTimeline' : 'timeline'
			if (this[list].indexOf(status.id) === -1) {
				this[list].push(status.id)
			}
			const removedFrom = { ...this.removedFrom }
			delete removedFrom[status.id]
			this.removedFrom = removedFrom
		},
		/**
		 * Drops the "where it came from" hint for a removal that is final. Only
		 * restoreStatus cleared it, so unliking from the liked timeline and
		 * un-bookmarking from the bookmarks left a hint behind for a status that
		 * is not coming back — and the next rollback of that id read it.
		 *
		 * @param {object} status the status whose removal stands
		 */
		forgetRemoval(status) {
			if (this.removedFrom[status.id] === undefined) {
				return
			}
			const removedFrom = { ...this.removedFrom }
			delete removedFrom[status.id]
			this.removedFrom = removedFrom
		},
		removeStatusesByActor(accountId) {
			const id = String(accountId)
			const isByActor = (status) => String(status?.account?.id) === id
				|| (status?.reblog && String(status.reblog.account?.id) === id)
			const removed = new Set(Object.values(this.statuses).filter(isByActor).map((status) => status.id))
			if (removed.size === 0) {
				return
			}
			this.timeline = this.timeline.filter((statusId) => !removed.has(statusId))
			this.parentsTimeline = this.parentsTimeline.filter((statusId) => !removed.has(statusId))
			const statuses = { ...this.statuses }
			removed.forEach((statusId) => delete statuses[statusId])
			this.statuses = statuses
		},
		resetTimeline() {
			this.timeline = []
			this.parentsTimeline = []
			// the id lists used to be the only thing cleared, so `statuses` grew
			// for the whole session: every page of every timeline ever opened
			this.statuses = {}
			this.removedFrom = {}
		},
		setTimelineType(type) {
			this.type = type
		},
		setTimelineParams(params) {
			this.params = params
		},
		setComposerDisplayStatus(status) {
			this.composerDisplayStatus = status
		},
		setAccount(account) {
			this.account = account
		},
		setSearchQuery(query) {
			this.searchQuery = query
		},
		startFirstPostCelebration() {
			this.firstPostCelebration = true
			this.firstPostCelebrated = true
		},
		likeStatus({ status }) {
			const known = this.statuses[status.id]
			if (known !== undefined) {
				this.statuses[status.id] = { ...known, favourited: true, favourites_count: (known.favourites_count ?? 0) + 1 }
			}
		},
		unlikeStatus({ status }) {
			const known = this.statuses[status.id]
			if (known !== undefined) {
				this.statuses[status.id] = { ...known, favourited: false, favourites_count: Math.max((known.favourites_count ?? 0) - 1, 0) }
			}
		},
		boostStatus({ status }) {
			const known = this.statuses[status.id]
			if (known !== undefined) {
				this.statuses[status.id] = { ...known, reblogged: true, reblogs_count: (known.reblogs_count ?? 0) + 1 }
			}
		},
		unboostStatus({ status }) {
			const known = this.statuses[status.id]
			if (known !== undefined) {
				this.statuses[status.id] = { ...known, reblogged: false, reblogs_count: Math.max((known.reblogs_count ?? 0) - 1, 0) }
			}
		},
		/**
		 * A vote reaches every view of the same post, not just the component that
		 * cast it.
		 *
		 * @param {object} payload the status and its new poll
		 * @param {string} payload.statusId the status that carries the poll
		 * @param {object} payload.poll the poll as the server returned it
		 */
		updateStatusPoll({ statusId, poll }) {
			const known = this.statuses[statusId]
			if (known !== undefined) {
				this.statuses[statusId] = { ...known, poll }
			}
		},
		bookmarkStatus({ status, bookmarked }) {
			if (this.statuses[status.id] !== undefined) {
				this.statuses[status.id] = { ...this.statuses[status.id], bookmarked }
			}
		},
		pinStatus({ status, pinned }) {
			if (this.statuses[status.id] !== undefined) {
				this.statuses[status.id] = { ...this.statuses[status.id], pinned }
			}
		},
		updateStatus(updatedStatus) {
			if (this.statuses[updatedStatus.id] !== undefined) {
				this.statuses[updatedStatus.id] = updatedStatus
			}
		},
		/**
		 * Decides whether the post that just went out is the first this reader has
		 * ever published, and starts the celebration if it is.
		 *
		 * Two guards, because neither is right on its own. The stored flag is what
		 * stops the second post of the same account being celebrated, and it is
		 * cheap — no request, no endpoint. The account's own `statuses_count` is
		 * what stops a reader of five years who cleared their browser storage from
		 * being congratulated on post number 1001; it is already in the store,
		 * fetched once when the page opened, so reading it costs nothing and it
		 * does not yet count the post that just went out.
		 *
		 * An account that has not loaded has an unknown count, and unknown is not
		 * proof of a first post: nothing happens.
		 *
		 * @return {boolean} whether the celebration was started
		 */
		celebrateFirstPost() {
			if (this.firstPostCelebration || this.firstPostCelebrated) {
				return false
			}
			if (useAccountStore().currentAccount?.statuses_count !== 0) {
				return false
			}
			if (alreadyCelebrated()) {
				return false
			}

			rememberCelebrated()
			this.startFirstPostCelebration()
			return true
		},
		endFirstPostCelebration() {
			this.firstPostCelebration = false
		},
		changeTimelineType({ type, params }) {
			this.resetTimeline()
			this.setTimelineType(type)
			this.setTimelineParams(params)
			this.setAccount('')
		},
		/**
		 * @param {string} account whose posts to show
		 * @param {string} media which kind of attachment to keep, '' for all
		 */
		changeTimelineTypeAccount(account, media = '') {
			this.resetTimeline()
			this.setTimelineType('account')
			// part of what identifies this timeline, so that changing the tab
			// asks the server again rather than filtering the page on screen
			this.setTimelineParams(media === '' ? {} : { media })
			this.setAccount(account)
		},
		/**
		 * Tells the server what an attachment shows, so it federates as alt text.
		 *
		 * @param {object} media the attachment
		 * @param {string} media.id its id on this server
		 * @param {string} media.description what it shows
		 */
		async describeMedia({ id, description }) {
			try {
				await axios.put(generateUrl('apps/social/api/v1/media/' + id), { description })
			} catch (error) {
				// the post itself is still worth sending; say so and carry on
				showError(t('social', 'Could not save the description of an attachment'))
				logger.error('Failed to describe a media', { error })
			}
		},

		/**
		 * Uploads one attachment.
		 *
		 * @param {File|object} payload the file, or `{file, onProgress}`
		 * @return {Promise<object|undefined>} the media entity, or undefined when the server refused
		 */
		async createMedia(payload) {
			const file = payload instanceof File ? payload : payload.file
			const onProgress = payload instanceof File ? undefined : payload.onProgress
			try {
				const formData = new FormData()
				formData.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/media'),
					formData,
					{
						headers: {
							'Content-Type': 'multipart/form-data',
						},
						onUploadProgress: typeof onProgress === 'function'
							? (event) => onProgress(event.total ? Math.min(event.loaded / event.total, 1) : 0)
							: undefined,
					},
				)
				logger.info('Media created with id ' + data.id)
				return data
			} catch (error) {
				showError(t('social', 'Could not upload the attachment'))
				logger.error('Failed to create a media', { error })
			}
		},
		/**
		 * Attaches a file the reader already keeps in Nextcloud.
		 *
		 * The bytes never leave the server: the path is all the browser sends, and
		 * what comes back is the same attachment an upload would have produced.
		 *
		 * @param {object} media what to attach
		 * @param {string} media.path the file, relative to the reader's own files
		 * @param {string} [media.description] what it shows
		 * @return {Promise<object|undefined>} the media entity, or undefined when the server refused
		 */
		async createMediaFromFile({ path, description = '' }) {
			try {
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/media/from-file'),
					{ path, description },
				)
				logger.info('Media created from ' + path + ' with id ' + data.id)
				return data
			} catch (error) {
				// named, because a picker run attaches several at once and "it
				// failed" would not say which one to try again
				showError(t('social', 'Could not attach {file}', { file: path }))
				logger.error('Failed to attach a file from Nextcloud', { error })
			}
		},
		/**
		 * Sends a status.
		 *
		 * Resolves with what the server created and with `undefined` when it
		 * refused, so the composer can tell the two apart: it used to clear itself
		 * either way, and an offline moment threw away what was typed.
		 *
		 * @param {object} status the status to send
		 * @return {Promise<object|undefined>} the created status, or undefined
		 */
		async post(status) {
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/statuses'), status)
				logger.info('Post created', data.id)
				return data
			} catch (error) {
				showError(t('social', 'Could not send the post'))
				logger.error('Failed to create a status', { error })
			}
		},
		async postEdit({ status, content, spoiler_text, sensitive }) {
			try {
				const response = await axios.put(
					generateUrl(`apps/social/api/v1/statuses/${status.id}`),
					{ status: content, spoiler_text, sensitive },
				)
				this.updateStatus(response.data)
				logger.info('Post edited', response.data.id)
				return response
			} catch (error) {
				showError(t('social', 'Could not save the changes to the post'))
				logger.error('Failed to edit the status', { error })
			}
		},
		async postDelete(status) {
			try {
				this.removeStatus(status)
				const response = await axios.delete(generateUrl(`apps/social/api/v1/post?id=${status.uri}`))
				logger.info('Post deleted with token ' + response.data.result.token)
			} catch (error) {
				// restoreStatus puts it back in the list it came from; addToTimeline
				// always appended to the replies, so a failed delete of an ancestor
				// reappeared among its own answers
				this.restoreStatus(status)
				showError(t('social', 'Could not delete the post'))
				logger.error('Failed to delete the status', { error })
			}
		},
		async postLike({ status }) {
			try {
				this.likeStatus({ status })
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/favourite`))
				logger.info('Post liked')
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				this.unlikeStatus({ status })
				showError(t('social', 'Could not like the post'))
				logger.error('Failed to like status', { error })
			}
		},
		async postUnlike({ status }) {
			try {
				if (this.type === 'favourites') {
					this.removeStatus(status)
				}
				this.unlikeStatus({ status })
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unfavourite`))
				logger.info('Post unliked')
				this.addToStatuses(response.data)
				this.forgetRemoval(status)
				return response
			} catch (error) {
				if (this.type === 'favourites') {
					// restoreStatus puts back the caller's pre-unlike copy of the
					// status (favourited, original count) — a likeStatus on top of
					// that would count the like twice.
					this.restoreStatus(status)
				} else {
					this.likeStatus({ status })
				}
				showError(t('social', 'Could not remove the like'))
				logger.error('Failed to unlike status', { error })
			}
		},
		async postBoost({ status }) {
			try {
				this.boostStatus({ status })
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/reblog`))
				logger.info('Post boosted')
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				this.unboostStatus({ status })
				showError(t('social', 'Could not boost the post'))
				logger.error('Failed to create a boost status', { error })
			}
		},
		async postUnBoost({ status }) {
			try {
				this.unboostStatus({ status })
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unreblog`))
				logger.info('Boost deleted')
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				this.boostStatus({ status })
				showError(t('social', 'Could not undo the boost'))
				logger.error('Failed to delete the boost', { error })
			}
		},
		async postBookmark({ status, bookmarked }) {
			// the flag flips first so the button answers at once, and is put back
			// if the server refuses
			this.bookmarkStatus({ status, bookmarked })
			try {
				const action = bookmarked ? 'bookmark' : 'unbookmark'
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/${action}`))
				logger.info(bookmarked ? 'Post bookmarked' : 'Bookmark removed')
				this.addToStatuses(response.data)
				if (!bookmarked && this.type === 'bookmarks') {
					this.removeStatus(status)
					this.forgetRemoval(status)
				}
				return response
			} catch (error) {
				this.bookmarkStatus({ status, bookmarked: !bookmarked })
				showError(bookmarked
					? t('social', 'Could not bookmark the post')
					: t('social', 'Could not remove the bookmark'))
				logger.error('Failed to change the bookmark', { error })
			}
		},
		async postPin({ status, pinned }) {
			// the flag is flipped first so the menu answers at once, and rolled
			// back if the server refuses (somebody else's post, or the pin limit)
			this.pinStatus({ status, pinned })
			try {
				const action = pinned ? 'pin' : 'unpin'
				const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/${action}`))
				logger.info(pinned ? 'Post pinned' : 'Post unpinned')
				this.addToStatuses(response.data)
				return response
			} catch (error) {
				this.pinStatus({ status, pinned: !pinned })
				showError(pinned
					? t('social', 'Could not pin the post')
					: t('social', 'Could not unpin the post'))
				logger.error('Failed to change the pinned state', { error })
			}
		},
		refreshTimeline() {
			return this.fetchTimeline()
		},
		async fetchTimeline(params = {}) {
			if (params.limit === undefined) {
				params.limit = 15
			}

			let url
			switch (this.type) {
				case 'account':
					url = generateUrl(`apps/social/api/v1/accounts/${this.account}/statuses`)
					// the profile's Photos and Videos tabs. `media_type` is a
					// Social extension; `only_media` is Mastodon's own and
					// says what the two have in common, so a client that knows
					// neither still gets a sensible answer to the first.
					if (this.params.media === 'image' || this.params.media === 'video') {
						params.only_media = true
						params.media_type = this.params.media
					}
					break
				case 'tags':
					url = generateUrl(`apps/social/api/v1/timelines/tag/${this.params.tag}`)
					break
				case 'single-post':
					url = generateUrl(`apps/social/api/v1/statuses/${this.params.id}/context`)
					break
				case 'timeline':
					url = generateUrl('apps/social/api/v1/timelines/public')
					params.local = true
					break
				case 'federated':
					url = generateUrl('apps/social/api/v1/timelines/public')
					break
				case 'photos':
				case 'videos':
				// a timeline with the text-only posts left out: what people
				// showed rather than what they said. Which people is the scope
				// the switcher sets — the ones you follow by default, this
				// instance, or everywhere — so this is the same three feeds
				// above, one predicate narrower.
					if (this.params.scope === 'timeline' || this.params.scope === 'federated') {
						url = generateUrl('apps/social/api/v1/timelines/public')
						if (this.params.scope === 'timeline') {
							params.local = true
						}
					} else {
						url = generateUrl('apps/social/api/v1/timelines/home')
					}
					// `only_media` is Mastodon's question and would answer a
					// video page with every photo on the instance; `only_video`
					// is this app's own and is the narrower of the two. Both go
					// out, so a server that has not been upgraded yet still
					// answers a video page with media rather than with
					// everything.
					params.only_media = true
					if (this.type === 'videos') {
						params.only_video = true
					}
					break
				case 'notifications':
					url = generateUrl('apps/social/api/v1/notifications')
					break
				case 'bookmarks':
				// the only timeline the server serves without a trailing slash
					url = generateUrl('apps/social/api/v1/bookmarks')
					break
				default:
					url = generateUrl(`apps/social/api/v1/timelines/${this.type}`)
			}

			// which list this page was asked for, so an answer that arrives after
			// the reader has moved on is dropped instead of being committed under
			// the new heading
			const identity = this.getTimelineIdentity
			const response = await axios.get(url, { params })

			if (this.getTimelineIdentity !== identity) {
				logger.debug('Dropped a page that belongs to a timeline no longer on screen', { identity })
				return []
			}

			this.addToTimeline(response.data)

			return response.data
		},
	},
})
