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
	type: 'home',
	params: {},
	account: '',
	composerDisplayStatus: false,
	searchQuery: '',
}

/**
 *
 * @param state
 * @param status
 */
function addToStatuses(state, status) {
	state.statuses = { ...state.statuses, [status.id]: status }
	if (status.reblog !== undefined && status.reblog !== null) {
		state.statuses = { ...state.statuses, [status.reblog.id]: status.reblog }
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
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], favourited: true }
			state.statuses[status.id].favourites_count++
		}
	},
	unlikeStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], favourited: false }
			state.statuses[status.id].favourites_count--
		}
	},
	boostStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], reblogged: true }
			state.statuses[status.id].reblogs_count++
		}
	},
	unboostStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			state.statuses[status.id] = { ...state.statuses[status.id], reblogged: false }
			state.statuses[status.id].reblogs_count--
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

const getters = {
	getComposerDisplayStatus(state) {
		return state.composerDisplayStatus
	},
	getTimeline(state) {
		let items = state.timeline
			.map(statusId => state.statuses[statusId])
			.filter(Boolean)
			.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())

		if (state.searchQuery) {
			const q = state.searchQuery.toLowerCase()
			items = items.filter(item => {
				const content = item.content ? item.content.toLowerCase() : ''
				const displayName = item.account?.display_name?.toLowerCase() || ''
				const acct = item.account?.acct?.toLowerCase() || ''
				return content.includes(q) || displayName.includes(q) || acct.includes(q)
			})
		}

		return items
	},
	getParentsTimeline(state) {
		let items = state.parentsTimeline
			.map(statusId => state.statuses[statusId])
			.filter(Boolean)
			.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())

		if (state.searchQuery) {
			const q = state.searchQuery.toLowerCase()
			items = items.filter(item => {
				const content = item.content ? item.content.toLowerCase() : ''
				const displayName = item.account?.display_name?.toLowerCase() || ''
				const acct = item.account?.acct?.toLowerCase() || ''
				return content.includes(q) || displayName.includes(q) || acct.includes(q)
			})
		}

		return items
	},
	getSearchQuery(state) {
		return state.searchQuery
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

	async createMedia(context, file) {
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
				},
			)
			logger.info('Media created with id ' + data.id)
			return data
		} catch (error) {
			showError(t('social', 'Could not upload the attachment'))
			logger.error('Failed to create a media', { error })
		}
	},
	async post(context, status) {
		try {
			const { data } = await axios.post(generateUrl('apps/social/api/v1/statuses'), status)
			logger.info('Post created', data.id)
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
			context.commit('addToTimeline', [status])
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
			return response
		} catch (error) {
			if (context.state.type === 'favourites') {
				// addToTimeline restores the caller's pre-unlike copy of the
				// status (favourited, original count) — a likeStatus on top of
				// that would count the like twice.
				context.commit('addToTimeline', [status])
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

		let url = ''
		switch (state.type) {
		case 'account':
			url = generateUrl(`apps/social/api/v1/accounts/${state.account}/statuses`)
			break
		case 'tags':
			url = generateUrl(`apps/social/api/v1/timelines/tag/${state.params.tag}`)
			break
		case 'single-post':
			url = generateUrl(`apps/social/api/v1/statuses/${state.params.id}/context`)
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
			url = generateUrl(`apps/social/api/v1/timelines/${state.type}`)
		}

		const response = await axios.get(url, { params })

		context.commit('addToTimeline', response.data)

		return response.data
	},
	addToTimeline(context, data) {
		context.commit('addToTimeline', data)
	},
}

export default { state, mutations, getters, actions }
