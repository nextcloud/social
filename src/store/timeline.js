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
	 * @type {Object<string, import('../types/Mastodon.js').Status>} timeline - The posts' collection
	 */
	timeline: {},
	/**
	 * @type {string} type - Timeline's type: 'home', 'single-post',...
	 */
	type: 'home',
	/**
	 * @type {object} params - Timeline's parameters
	 * @property {string} params.account ???
	 * @property {string} params.id
	 * @property {string} params.localId
	 * @property {string} params.type ???
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

/** @type {import('vuex').MutationTree<state>} */
const mutations = {
	/**
	 * @param state
	 * @param {import('../types/Mastodon.js').Status[]} data
	 */
	addToTimeline(state, data) {
		// TODO: fix to handle ancestors
		if (data.descendants) {
			data = data.descendants
		}
		data.forEach((post) => Vue.set(state.timeline, post.id, post))
	},
	/**
	 * @param state
	 * @param {import('../types/Mastodon.js').Status} post
	 */
	removePost(state, post) {
		Vue.delete(state.timeline, post.id)
	},
	resetTimeline(state) {
		state.timeline = {}
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
	 * @param {import('../types/Mastodon.js').Status} root0.post
	 */
	likePost(state, { post }) {
		if (state.timeline[post.id] !== undefined) {
			Vue.set(state.timeline[post.id], 'favourited', true)
		}
		if (post.reblog !== null && state.timeline[post.reblog.id] !== undefined) {
			Vue.set(state.timeline[post.reblog.id], 'favourited', true)
		}
	},
	/**
	 * @param state
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.post
	 */
	unlikePost(state, { post }) {
		if (state.timeline[post.id] !== undefined) {
			Vue.set(state.timeline[post.id], 'favourited', false)
		}
		if (post.reblog !== null && state.timeline[post.reblog.id] !== undefined) {
			Vue.set(state.timeline[post.reblog.id], 'favourited', false)
		}
	},
	/**
	 * @param state
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.post
	 */
	boostPost(state, { post }) {
		if (state.timeline[post.id] !== undefined) {
			Vue.set(state.timeline[post.id], 'reblogged', true)
		}
		if (post.reblog !== null && state.timeline[post.reblog.id] !== undefined) {
			Vue.set(state.timeline[post.reblog.id], 'reblogged', true)
		}
	},
	/**
	 * @param state
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.post
	 */
	unboostPost(state, { post }) {
		if (state.timeline[post.id] !== undefined) {
			Vue.set(state.timeline[post.id], 'reblogged', false)
		}
		if (post.reblog !== null && state.timeline[post.reblog.id] !== undefined) {
			Vue.set(state.timeline[post.reblog.id], 'reblogged', false)
		}
	},
}

/** @type {import('vuex').GetterTree<state, any>} */
const getters = {
	getComposerDisplayStatus(state) {
		return state.composerDisplayStatus
	},
	getTimeline(state) {
		return Object.values(state.timeline).sort(function(a, b) {
			return new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
		})
	},
	getPostFromTimeline(state) {
		return (postId) => {
			if (typeof state.timeline[postId] !== 'undefined') {
				return state.timeline[postId]
			} else {
				logger.warn('Could not find post in timeline', { postId })
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
	 * @param {import('../types/Mastodon.js').Status} post
	 */
	async post(context, post) {
		try {
			const { data } = await axios.post(generateUrl('apps/social/api/v1/statuses'), post)
			logger.info('Post created', data.id)
		} catch (error) {
			showError('Failed to create a post')
			logger.error('Failed to create a post', { error })
		}
	},
	/**
	 * @param context
	 * @param {import('../types/Mastodon.js').Status} post
	 */
	async postDelete(context, post) {
		try {
			context.commit('removePost', post)
			const response = await axios.delete(generateUrl(`apps/social/api/v1/post?id=${post.uri}`))
			logger.info('Post deleted with token ' + response.data.result.token)
		} catch (error) {
			context.commit('addToTimeline', [post])
			showError('Failed to delete the post')
			logger.error('Failed to delete the post', { error })
		}
	},
	/**
	 * @param context
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.post
	 */
	async postLike(context, { post }) {
		try {
			context.commit('likePost', { post })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${post.id}/favourite`))
			logger.info('Post liked')
			context.commit('addToTimeline', [response.data])
			return response
		} catch (error) {
			context.commit('unlikePost', { post })
			showError('Failed to like post')
			logger.error('Failed to like post', { error })
		}
	},
	/**
	 * @param context
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.post
	 */
	async postUnlike(context, { post }) {
		try {
			// Remove post from list if we are in the 'liked' timeline
			if (state.type === 'liked') {
				context.commit('removePost', post)
			}
			context.commit('unlikePost', { post })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${post.id}/unfavourite`))
			logger.info('Post unliked')
			context.commit('addToTimeline', [response.data])
			return response
		} catch (error) {
			// Readd post from list if we are in the 'liked' timeline
			if (state.type === 'liked') {
				context.commit('addToTimeline', [post])
			}
			context.commit('likePost', { post })
			showError('Failed to unlike post')
			logger.error('Failed to unlike post', { error })
		}
	},
	/**
	 * @param context
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.post
	 */
	async postBoost(context, { post }) {
		try {
			context.commit('boostPost', { post })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${post.id}/reblog`))
			logger.info('Post boosted')
			context.commit('addToTimeline', [response.data])
			return response
		} catch (error) {
			context.commit('unboostPost', { post })
			showError('Failed to create a boost post')
			logger.error('Failed to create a boost post', { error })
		}
	},
	/**
	 * @param context
	 * @param {object} root0
	 * @param {import('../types/Mastodon.js').Status} root0.post
	 */
	async postUnBoost(context, { post }) {
		try {
			context.commit('unboostPost', { post })
			const response = await axios.post(generateUrl(`apps/social/api/v1/statuses/${post.id}/unreblog`))
			logger.info('Boost deleted')
			context.commit('addToTimeline', [response.data])
			return response
		} catch (error) {
			context.commit('boostPost', { post })
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
			url = generateUrl(`apps/social/api/v1/statuses/${state.params.localId}/context`)
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
