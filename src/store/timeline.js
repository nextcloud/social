/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

import logger from '../services/logger.js'

const state = {
	statuses: {},
	timeline: [],
	parentsTimeline: [],
	/** which list a removed status came from, so a rollback restores it there */
	removedFrom: {},
	type: 'home',
	params: {},
	account: '',
	composerDisplayStatus: false,
	searchQuery: '',
}

/**
 * Indexes a status, and the status it boosts, by id.
 *
 * @param {object} state the module state
 * @param {object} status the status to index
 */
function addToStatuses(state, status) {
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

const mutations = {
	addToStatuses(state, status) {
		addToStatuses(state, status)
	},
	addToTimeline(state, data) {
		if (Array.isArray(data)) {
			data.forEach(status => addToStatuses(state, status))
			data
				.filter(status => state.timeline.indexOf(status.id) === -1)
				.forEach(status => state.timeline.push(status.id))
		} else {
			data.descendants.forEach(status => addToStatuses(state, status))
			data.ancestors.forEach(status => addToStatuses(state, status))

			data.descendants
				.filter(status => state.timeline.indexOf(status.id) === -1)
				.forEach(status => state.timeline.push(status.id))
			data.ancestors
				.filter(status => state.parentsTimeline.indexOf(status.id) === -1)
				.forEach(status => state.parentsTimeline.push(status.id))
		}
	},
	removeStatus(state, status) {
		const timelineIndex = state.timeline.indexOf(status.id)
		if (timelineIndex !== -1) {
			state.timeline.splice(timelineIndex, 1)
		}
		const parentsTimelineIndex = state.parentsTimeline.indexOf(status.id)
		if (parentsTimelineIndex !== -1) {
			state.parentsTimeline.splice(parentsTimelineIndex, 1)
		}
		// which list it came from, so a failed delete puts it back where it was
		state.removedFrom = { ...state.removedFrom, [status.id]: parentsTimelineIndex !== -1 ? 'parents' : 'timeline' }
		delete state.statuses[status.id]
	},
	/**
	 * Puts a status back after a delete the server refused. `addToTimeline`
	 * always appended to `state.timeline`, so a failed delete of an ancestor
	 * reappeared among the replies.
	 *
	 * @param {object} state the module state
	 * @param {object} status the status that could not be deleted
	 */
	restoreStatus(state, status) {
		addToStatuses(state, status)
		const list = state.removedFrom?.[status.id] === 'parents' ? 'parentsTimeline' : 'timeline'
		if (state[list].indexOf(status.id) === -1) {
			state[list].push(status.id)
		}
		const removedFrom = { ...state.removedFrom }
		delete removedFrom[status.id]
		state.removedFrom = removedFrom
	},
	/**
	 * Drops the "where it came from" hint for a removal that is final. Only
	 * restoreStatus cleared it, so unliking from the liked timeline and
	 * un-bookmarking from the bookmarks left a hint behind for a status that
	 * is not coming back — and the next rollback of that id read it.
	 *
	 * @param {object} state the module state
	 * @param {object} status the status whose removal stands
	 */
	forgetRemoval(state, status) {
		if (state.removedFrom[status.id] === undefined) {
			return
		}
		const removedFrom = { ...state.removedFrom }
		delete removedFrom[status.id]
		state.removedFrom = removedFrom
	},
	removeStatusesByActor(state, accountId) {
		const id = String(accountId)
		const isByActor = (status) => String(status?.account?.id) === id
			|| (status?.reblog && String(status.reblog.account?.id) === id)
		const removed = new Set(Object.values(state.statuses).filter(isByActor).map(status => status.id))
		if (removed.size === 0) {
			return
		}
		state.timeline = state.timeline.filter(statusId => !removed.has(statusId))
		state.parentsTimeline = state.parentsTimeline.filter(statusId => !removed.has(statusId))
		const statuses = { ...state.statuses }
		removed.forEach(statusId => delete statuses[statusId])
		state.statuses = statuses
	},
	resetTimeline(state) {
		state.timeline = []
		state.parentsTimeline = []
		// the id lists used to be the only thing cleared, so `statuses` grew
		// for the whole session: every page of every timeline ever opened
		state.statuses = {}
		state.removedFrom = {}
	},
	setTimelineType(state, type) {
		state.type = type
	},
	setTimelineParams(state, params) {
		state.params = params
	},
	setComposerDisplayStatus(state, status) {
		state.composerDisplayStatus = status
	},
	setAccount(state, account) {
		state.account = account
	},
	setSearchQuery(state, query) {
		state.searchQuery = query
	},
	likeStatus(state, { status }) {
		const known = state.statuses[status.id]
		if (known !== undefined) {
			state.statuses[status.id] = { ...known, favourited: true, favourites_count: (known.favourites_count ?? 0) + 1 }
		}
	},
	unlikeStatus(state, { status }) {
		const known = state.statuses[status.id]
		if (known !== undefined) {
			state.statuses[status.id] = { ...known, favourited: false, favourites_count: Math.max((known.favourites_count ?? 0) - 1, 0) }
		}
	},
	boostStatus(state, { status }) {
		const known = state.statuses[status.id]
		if (known !== undefined) {
			state.statuses[status.id] = { ...known, reblogged: true, reblogs_count: (known.reblogs_count ?? 0) + 1 }
		}
	},
	unboostStatus(state, { status }) {
		const known = state.statuses[status.id]
		if (known !== undefined) {
			state.statuses[status.id] = { ...known, reblogged: false, reblogs_count: Math.max((known.reblogs_count ?? 0) - 1, 0) }
		}
	},
	/**
	 * A vote reaches every view of the same post, not just the component that
	 * cast it.
	 *
	 * @param {object} state the module state
	 * @param {object} payload the status and its new poll
	 * @param {string} payload.statusId the status that carries the poll
	 * @param {object} payload.poll the poll as the server returned it
	 */
	updateStatusPoll(state, { statusId, poll }) {
		const known = state.statuses[statusId]
		if (known !== undefined) {
			state.statuses[statusId] = { ...known, poll }
		}
	},
	bookmarkStatus(state, { status, bookmarked }) {
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], bookmarked }
		}
	},
	pinStatus(state, { status, pinned }) {
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], pinned }
		}
	},
	updateStatus(state, updatedStatus) {
		if (state.statuses[updatedStatus.id] !== undefined) {
			state.statuses[updatedStatus.id] = updatedStatus
		}
	},
}

/**
 * @param {object} state the module state
 * @param {string[]} ids the ids of one of the two lists
 * @return {object[]} the statuses those ids name, newest first
 */
function sortedByDate(state, ids) {
	return ids
		.map(statusId => state.statuses[statusId])
		.filter(Boolean)
		.sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at))
}

const getters = {
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
	 * @param {object} state the module state
	 * @return {object[]} the statuses
	 */
	getTimeline(state) {
		return sortedByDate(state, state.timeline)
	},
	getParentsTimeline(state) {
		return sortedByDate(state, state.parentsTimeline)
	},
	getSearchQuery(state) {
		return state.searchQuery
	},
	/**
	 * What list the store is currently holding, as one comparable value.
	 *
	 * The router-view is no longer keyed on the full path, so a view — and the
	 * TimelineList inside it — is reused across a navigation. This is what
	 * tells the list that the thing it is showing has been swapped underneath
	 * it, whether by a type, a tag, an account or a single post.
	 *
	 * @param {object} state the module state
	 * @return {string} the identity of the current timeline
	 */
	getTimelineIdentity(state) {
		return JSON.stringify([state.type, state.account, state.params])
	},
	getStatus(state) {
		return (statusId) => state.statuses[statusId]
	},
	getSinglePost(state) {
		return state.statuses[state.params.singlePost]
	},
	getPostFromTimeline(state) {
		return (statusId) => {
			if (state.statuses[statusId] !== undefined) {
				return state.statuses[statusId]
			} else {
				logger.warn('Could not find status in timeline', { statusId })
			}
		}
	},
}

const actions = {
	changeTimelineType(context, { type, params }) {
		context.commit('resetTimeline')
		context.commit('setTimelineType', type)
		context.commit('setTimelineParams', params)
		context.commit('setAccount', '')
	},
	changeTimelineTypeAccount(context, account) {
		context.commit('resetTimeline')
		context.commit('setTimelineType', 'account')
		context.commit('setAccount', account)
	},
	/**
	 * Tells the server what an attachment shows, so it federates as alt text.
	 *
	 * @param {object} context the store
	 * @param {object} media the attachment
	 * @param {string} media.id its id on this server
	 * @param {string} media.description what it shows
	 */
	async describeMedia(context, { id, description }) {
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
	 * @param {object} context the store
	 * @param {File|object} payload the file, or `{file, onProgress}`
	 * @return {Promise<object|undefined>} the media entity, or undefined when the server refused
	 */
	async createMedia(context, payload) {
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
	 * Sends a status.
	 *
	 * Resolves with what the server created and with `undefined` when it
	 * refused, so the composer can tell the two apart: it used to clear itself
	 * either way, and an offline moment threw away what was typed.
	 *
	 * @param {object} context the store
	 * @param {object} status the status to send
	 * @return {Promise<object|undefined>} the created status, or undefined
	 */
	async post(context, status) {
		try {
			const { data } = await axios.post(generateUrl('apps/social/api/v1/statuses'), status)
			logger.info('Post created', data.id)
			return data
		} catch (error) {
			showError(t('social', 'Could not send the post'))
			logger.error('Failed to create a status', { error })
		}
	},
	async postEdit(context, { status, content, spoiler_text, sensitive }) {
		try {
			const response = await axios.put(
				generateUrl(`apps/social/api/v1/statuses/${status.id}`),
				{ status: content, spoiler_text, sensitive },
			)
			context.commit('updateStatus', response.data)
			logger.info('Post edited', response.data.id)
			return response
		} catch (error) {
			showError(t('social', 'Could not save the changes to the post'))
			logger.error('Failed to edit the status', { error })
		}
	},
	async postDelete(context, status) {
		try {
			context.commit('removeStatus', status)
			const response = await axios.delete(generateUrl(`apps/social/api/v1/post?id=${status.uri}`))
			logger.info('Post deleted with token ' + response.data.result.token)
		} catch (error) {
			// restoreStatus puts it back in the list it came from; addToTimeline
			// always appended to the replies, so a failed delete of an ancestor
			// reappeared among its own answers
			context.commit('restoreStatus', status)
			showError(t('social', 'Could not delete the post'))
			logger.error('Failed to delete the status', { error })
		}
	},
	async postLike(context, { status }) {
		try {
			context.commit('likeStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/favourite`))
			logger.info('Post liked')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('unlikeStatus', { status })
			showError(t('social', 'Could not like the post'))
			logger.error('Failed to like status', { error })
		}
	},
	async postUnlike(context, { status }) {
		try {
			if (context.state.type === 'favourites') {
				context.commit('removeStatus', status)
			}
			context.commit('unlikeStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unfavourite`))
			logger.info('Post unliked')
			context.commit('addToStatuses', response.data)
			context.commit('forgetRemoval', status)
			return response
		} catch (error) {
			if (context.state.type === 'favourites') {
				// restoreStatus puts back the caller's pre-unlike copy of the
				// status (favourited, original count) — a likeStatus on top of
				// that would count the like twice.
				context.commit('restoreStatus', status)
			} else {
				context.commit('likeStatus', { status })
			}
			showError(t('social', 'Could not remove the like'))
			logger.error('Failed to unlike status', { error })
		}
	},
	async postBoost(context, { status }) {
		try {
			context.commit('boostStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/reblog`))
			logger.info('Post boosted')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('unboostStatus', { status })
			showError(t('social', 'Could not boost the post'))
			logger.error('Failed to create a boost status', { error })
		}
	},
	async postUnBoost(context, { status }) {
		try {
			context.commit('unboostStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unreblog`))
			logger.info('Boost deleted')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('boostStatus', { status })
			showError(t('social', 'Could not undo the boost'))
			logger.error('Failed to delete the boost', { error })
		}
	},
	async postBookmark(context, { status, bookmarked }) {
		// the flag flips first so the button answers at once, and is put back
		// if the server refuses
		context.commit('bookmarkStatus', { status, bookmarked })
		try {
			const action = bookmarked ? 'bookmark' : 'unbookmark'
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/${action}`))
			logger.info(bookmarked ? 'Post bookmarked' : 'Bookmark removed')
			context.commit('addToStatuses', response.data)
			if (!bookmarked && context.state.type === 'bookmarks') {
				context.commit('removeStatus', status)
				context.commit('forgetRemoval', status)
			}
			return response
		} catch (error) {
			context.commit('bookmarkStatus', { status, bookmarked: !bookmarked })
			showError(bookmarked
				? t('social', 'Could not bookmark the post')
				: t('social', 'Could not remove the bookmark'))
			logger.error('Failed to change the bookmark', { error })
		}
	},
	async postPin(context, { status, pinned }) {
		// the flag is flipped first so the menu answers at once, and rolled
		// back if the server refuses (somebody else's post, or the pin limit)
		context.commit('pinStatus', { status, pinned })
		try {
			const action = pinned ? 'pin' : 'unpin'
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/${action}`))
			logger.info(pinned ? 'Post pinned' : 'Post unpinned')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('pinStatus', { status, pinned: !pinned })
			showError(pinned
				? t('social', 'Could not pin the post')
				: t('social', 'Could not unpin the post'))
			logger.error('Failed to change the pinned state', { error })
		}
	},
	refreshTimeline(context) {
		return this.dispatch('fetchTimeline')
	},
	async fetchTimeline(context, params = {}) {
		if (params.limit === undefined) {
			params.limit = 15
		}

		// context.state, not the module-closure `state`: the closure works only
		// because there happens to be exactly one store, which is the trap
		// store/notifications.js already documents
		const current = context.state
		let url = ''
		switch (current.type) {
		case 'account':
			url = generateUrl(`apps/social/api/v1/accounts/${current.account}/statuses`)
			break
		case 'tags':
			url = generateUrl(`apps/social/api/v1/timelines/tag/${current.params.tag}`)
			break
		case 'single-post':
			url = generateUrl(`apps/social/api/v1/statuses/${current.params.id}/context`)
			break
		case 'timeline':
			url = generateUrl('apps/social/api/v1/timelines/public')
			params.local = true
			break
		case 'federated':
			url = generateUrl('apps/social/api/v1/timelines/public')
			break
		case 'notifications':
			url = generateUrl('apps/social/api/v1/notifications')
			break
		case 'bookmarks':
			// the only timeline the server serves without a trailing slash
			url = generateUrl('apps/social/api/v1/bookmarks')
			break
		default:
			url = generateUrl(`apps/social/api/v1/timelines/${current.type}`)
		}

		// which list this page was asked for, so an answer that arrives after
		// the reader has moved on is dropped instead of being committed under
		// the new heading
		const identity = context.getters.getTimelineIdentity
		const response = await axios.get(url, { params })

		if (context.getters.getTimelineIdentity !== identity) {
			logger.debug('Dropped a page that belongs to a timeline no longer on screen', { identity })
			return []
		}

		context.commit('addToTimeline', response.data)

		return response.data
	},
	addToTimeline(context, data) {
		context.commit('addToTimeline', data)
	},
}

export default { state, mutations, getters, actions }
