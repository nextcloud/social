/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @file Timeline related store
 *
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Jonas Sulzer <jonas@violoncello.ch>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import Vue from 'vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

import logger from '../services/logger.js'

const state = {
	/**
	 * @type {Object<string, import('../types/Mastodon.js').Status>} List of locally known statuses
	 */
	statuses: {},
	/**
	 * @type {string[]} timeline - The statuses' collection
	 */
	timeline: [],
	/**
	 * @type {string[]} parentsTimeline - The parents statuses' collection
	 */
	parentsTimeline: [],
	/**
	 * @type {string} type - Timeline's type: 'home', 'single-post',...
	 */
	type: 'home',
	/**
	 * @type {object} params - Timeline's parameters
	 * @property {string} params.account ???
	 * @property {string} params.id
	 * @property {string} params.type ???
	 * @property {string?} params.singlePost ???
	 */
	params: {},
	/**
	 * @type {string} account -
	 */
	account: '',
	/**
	 * Tells whether the composer should be displayed or not.
	 * It's up to the view to honor this status or not.
	 *
	 * @member {boolean}
	 */
	composerDisplayStatus: false,
}

/**
 *
 * @param {typeof state} state
 * @param {import ('../types/Mastodon.js').Status} status
 */
function addToStatuses(state, status) {
	Vue.set(state.statuses, status.id, status)
	if (status.reblog !== undefined && status.reblog !== null) {
		Vue.set(state.statuses, status.reblog.id, status.reblog)
	}
}

/** @type {import('vuex').MutationTree<state>} */
const mutations = {
	/**
	 * @param state
	 * @param {import ('../types/Mastodon.js').Status} status
	 */
	addToStatuses(state, status) {
		addToStatuses(state, status)
	},
	/**
	 * @param state
	 * @param {import ('../types/Mastodon.js').Status[]|import('../types/Mastodon.js').Context} data
	 */
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
	/**
	 * @param state
	 * @param {import('../types/Mastodon.js').Status} status
	 */
	removeStatus(state, status) {
		const timelineIndex = state.timeline.indexOf(status.id)
		if (timelineIndex !== -1) {
			state.timeline.splice(timelineIndex, 1)
		}
		const parentsTimelineIndex = state.parentsTimeline.indexOf(status.id)
		if (timelineIndex !== -1) {
			state.parentsTimeline.splice(parentsTimelineIndex, 1)
		}
	},
	resetTimeline(state) {
		state.timeline = []
		state.parentsTimeline = []
	},
	/**
	 * @param state
	 * @param {string} type
	 */
	setTimelineType(state, type) {
		state.type = type
	},
	setTimelineParams(state, params) {
		state.params = params
	},
	/**
	 * @param state
	 * @param {boolean} status
	 */
	setComposerDisplayStatus(state, status) {
		state.composerDisplayStatus = status
	},
	/**
	 * @param state
	 * @param {string} account
	 */
	setAccount(state, account) {
		state.account = account
	},
	/**
	 * @param state
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.status
	 */
	likeStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			Vue.set(state.statuses[status.id], 'favourited', true)
			state.statuses[status.id].favourites_count++
		}
	},
	/**
	 * @param state
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.status
	 */
	unlikeStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			Vue.set(state.statuses[status.id], 'favourited', false)
			state.statuses[status.id].favourites_count--
		}
	},
	/**
	 * @param state
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.status
	 */
	boostStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			Vue.set(state.statuses[status.id], 'reblogged', true)
			state.statuses[status.id].reblogs_count++
		}
	},
	/**
	 * @param state
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.status
	 */
	unboostStatus(state, { status }) {
		if (state.statuses[status.id] !== undefined) {
			Vue.set(state.statuses[status.id], 'reblogged', false)
			state.statuses[status.id].reblogs_count--
		}
	},
}

/** @type {import('vuex').GetterTree<state, any>} */
const getters = {
	getComposerDisplayStatus(state) {
		return state.composerDisplayStatus
	},
	getTimeline(state) {
		return state.timeline
			.map(statusId => state.statuses[statusId])
			.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
	},
	getParentsTimeline(state) {
		return state.parentsTimeline
			.map(statusId => state.statuses[statusId])
			.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
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

/** @type {import('vuex').ActionTree<state, any>} */
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
	 * @param context
	 * @param {File} file
	 */
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
				}
			)
			logger.info('Media created with id ' + data.id)
			return data
		} catch (error) {
			showError('Failed to create a media')
			logger.error('Failed to create a media', { error })
		}
	},
	/**
	 * @param context
	 * @param {import('../types/Mastodon.js').Status} status
	 */
	async post(context, status) {
		try {
			const { data } = await axios.post(generateUrl('apps/social/api/v1/statuses'), status)
			logger.info('Post created', data.id)
		} catch (error) {
			showError('Failed to create a status')
			logger.error('Failed to create a status', { error })
		}
	},
	/**
	 * @param context
	 * @param {import('../types/Mastodon.js').Status} status
	 */
	async postDelete(context, status) {
		try {
			context.commit('removeStatus', status)
			const response = await axios.delete(generateUrl(`apps/social/api/v1/post?id=${status.uri}`))
			logger.info('Post deleted with token ' + response.data.result.token)
		} catch (error) {
			context.commit('addToStatuses', status)
			showError('Failed to delete the status')
			logger.error('Failed to delete the status', { error })
		}
	},
	/**
	 * @param context
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.status
	 */
	async postLike(context, { status }) {
		try {
			context.commit('likeStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/favourite`))
			logger.info('Post liked')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('unlikeStatus', { status })
			showError('Failed to like status')
			logger.error('Failed to like status', { error })
		}
	},
	/**
	 * @param context
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.status
	 */
	async postUnlike(context, { status }) {
		try {
			// Remove status from list if we are in the 'liked' timeline
			if (state.type === 'liked') {
				context.commit('removeStatus', status)
			}
			context.commit('unlikeStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unfavourite`))
			logger.info('Post unliked')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			// Readd status from list if we are in the 'liked' timeline
			if (state.type === 'liked') {
				context.commit('addToTimeline', [status])
			}
			context.commit('likeStatus', { status })
			showError('Failed to unlike status')
			logger.error('Failed to unlike status', { error })
		}
	},
	/**
	 * @param context
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.status
	 */
	async postBoost(context, { status }) {
		try {
			context.commit('boostStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/reblog`))
			logger.info('Post boosted')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('unboostStatus', { status })
			showError('Failed to create a boost status')
			logger.error('Failed to create a boost status', { error })
		}
	},
	/**
	 * @param context
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.status
	 */
	async postUnBoost(context, { status }) {
		try {
			context.commit('unboostStatus', { status })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${status.id}/unreblog`))
			logger.info('Boost deleted')
			context.commit('addToStatuses', response.data)
			return response
		} catch (error) {
			context.commit('boostStatus', { status })
			showError('Failed to delete the boost')
			logger.error('Failed to delete the boost', { error })
		}
	},
	refreshTimeline(context) {
		return this.dispatch('fetchTimeline')
	},
	/**
	 *
	 * @param {object} context
	 * @param {object} params - see https://docs.joinmastodon.org/methods/timelines
	 * @param {number} [params.since_id] - Fetch results newer than ID
	 * @param {number} [params.max_id] - Fetch results older than ID
	 * @param {number} [params.min_id] - Fetch results immediately newer than ID
	 * @param {number} [params.limit] - Maximum number of results to return. Defaults to 20 statuses. Max 40 statuses
	 * @param {boolean} [params.local] - Show only local statuses? Defaults to false.
	 * @return {Promise<object[]>}
	 */
	async fetchTimeline(context, params = {}) {
		if (params.limit === undefined) {
			params.limit = 15
		}

		// Compute URL to get the data
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
		default:
			url = generateUrl(`apps/social/api/v1/timelines/${state.type}`)
		}

		// Get the data and add them to the timeline
		const response = await axios.get(url, { params })

		// Add results to timeline
		context.commit('addToTimeline', response.data)

		return response.data
	},
	/**
	 * @param context
	 * @param {import('../types/Mastodon.js').Status[]} data
	 */
	addToTimeline(context, data) {
		context.commit('addToTimeline', data)
	},
}

export default { state, mutations, getters, actions }
