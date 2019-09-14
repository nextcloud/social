/*
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Jonas Sulzer <jonas@violoncello.ch>
 *
 * @license GNU AGPL version 3 or any later version
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

import axios from 'nextcloud-axios'
import Vue from 'vue'

const state = {
	timeline: {},
	since: Math.floor(Date.now() / 1000) + 1,
	type: 'home',
	params: {},
	account: ''
}
const mutations = {
	addToTimeline(state, data) {
		for (let item in data) {
			state.since = data[item].publishedTime
			Vue.set(state.timeline, data[item].id, data[item])
		}
	},
	removePost(state, post) {
		Vue.delete(state.timeline, post.id)
	},
	resetTimeline(state) {
		state.timeline = {}
		state.since = Math.floor(Date.now() / 1000) + 1
	},
	setTimelineType(state, type) {
		state.type = type
	},
	setTimelineParams(state, params) {
		state.params = params
	},
	setAccount(state, account) {
		state.account = account
	},
	likePost(state, { post, parentAnnounce }) {
		if (typeof state.timeline[post.id] !== 'undefined') {
			Vue.set(state.timeline[post.id].action.values, 'liked', true)
		}
		if (typeof parentAnnounce.id !== 'undefined') {
			Vue.set(state.timeline[parentAnnounce.id].cache[parentAnnounce.object].object.action.values, 'liked', true)
		}
	},
	unlikePost(state, { post, parentAnnounce }) {
		if (typeof state.timeline[post.id] !== 'undefined') {
			Vue.set(state.timeline[post.id].action.values, 'liked', false)
		}
		if (typeof parentAnnounce.id !== 'undefined') {
			Vue.set(state.timeline[parentAnnounce.id].cache[parentAnnounce.object].object.action.values, 'liked', false)
		}
	},
	boostPost(state, { post, parentAnnounce }) {
		if (typeof state.timeline[post.id] !== 'undefined') {
			Vue.set(state.timeline[post.id].action.values, 'boosted', true)
		}
		if (typeof parentAnnounce.id !== 'undefined') {
			Vue.set(state.timeline[parentAnnounce.id].cache[parentAnnounce.object].object.action.values, 'boosted', true)
		}
	},
	unboostPost(state, { post, parentAnnounce }) {
		if (typeof state.timeline[post.id] !== 'undefined') {
			Vue.set(state.timeline[post.id].action.values, 'boosted', false)
		}
		if (typeof parentAnnounce.id !== 'undefined') {
			Vue.set(state.timeline[parentAnnounce.id].cache[parentAnnounce.object].object.action.values, 'boosted', false)
		}
	}
}
const getters = {
	getTimeline(state) {
		return Object.values(state.timeline).sort(function(a, b) {
			return b.publishedTime - a.publishedTime
		})
	}
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
	post(context, post) {
		return new Promise((resolve, reject) => {
			axios.post(OC.generateUrl('apps/social/api/v1/post'), { data: post }).then((response) => {
				// eslint-disable-next-line no-console
				console.log('Post created with token ' + response.data.result.token)
				resolve(response)
			}).catch((error) => {
				OC.Notification.showTemporary('Failed to create a post')
				console.error('Failed to create a post', error.response)
				reject(error)
			})
		})
	},
	postDelete(context, post) {
		return axios.delete(OC.generateUrl(`apps/social/api/v1/post?id=${post.id}`)).then((response) => {
			context.commit('removePost', post)
			// eslint-disable-next-line no-console
			console.log('Post deleted with token ' + response.data.result.token)
		}).catch((error) => {
			OC.Notification.showTemporary('Failed to delete the post')
			console.error('Failed to delete the post', error)
		})
	},
	postLike(context, { post, parentAnnounce }) {
		return new Promise((resolve, reject) => {
			axios.post(OC.generateUrl(`apps/social/api/v1/post/like?postId=${post.id}`)).then((response) => {
				context.commit('likePost', { post, parentAnnounce })
				resolve(response)
			}).catch((error) => {
				OC.Notification.showTemporary('Failed to like post')
				console.error('Failed to like post', error.response)
				reject(error)
			})
		})
	},
	postUnlike(context, { post, parentAnnounce }) {
		return axios.delete(OC.generateUrl(`apps/social/api/v1/post/like?postId=${post.id}`)).then((response) => {
			context.commit('unlikePost', { post, parentAnnounce })
			// Remove post from list if we are in the 'liked' timeline
			if (state.type === 'liked') {
				context.commit('removePost', post)
			}
		}).catch((error) => {
			OC.Notification.showTemporary('Failed to unlike post')
			console.error('Failed to unlike post', error)
		})
	},
	postBoost(context, { post, parentAnnounce }) {
		return new Promise((resolve, reject) => {
			axios.post(OC.generateUrl(`apps/social/api/v1/post/boost?postId=${post.id}`)).then((response) => {
				context.commit('boostPost', { post, parentAnnounce })
				// eslint-disable-next-line no-console
				console.log('Post boosted with token ' + response.data.result.token)
				resolve(response)
			}).catch((error) => {
				OC.Notification.showTemporary('Failed to create a boost post')
				console.error('Failed to create a boost post', error.response)
				reject(error)
			})
		})
	},
	postUnBoost(context, { post, parentAnnounce }) {
		return axios.delete(OC.generateUrl(`apps/social/api/v1/post/boost?postId=${post.id}`)).then((response) => {
			context.commit('unboostPost', { post, parentAnnounce })
			// eslint-disable-next-line no-console
			console.log('Boost deleted with token ' + response.data.result.token)
		}).catch((error) => {
			OC.Notification.showTemporary('Failed to delete the boost')
			console.error('Failed to delete the boost', error)
		})
	},
	refreshTimeline(context) {
		return this.dispatch('fetchTimeline', { sinceTimestamp: Math.floor(Date.now() / 1000) + 1 })
	},
	fetchTimeline(context, { sinceTimestamp }) {

		if (typeof sinceTimestamp === 'undefined') {
			sinceTimestamp = state.since - 1
		}

		let url
		if (state.type === 'account') {
			url = OC.generateUrl(`apps/social/api/v1/account/${state.account}/stream?limit=25&since=` + sinceTimestamp)
		} else if (state.type === 'tags') {
			url = OC.generateUrl(`apps/social/api/v1/stream/tag/${state.params.tag}?limit=25&since=` + sinceTimestamp)
		} else if (state.type === 'single-post') {
			url = OC.generateUrl(`apps/social/@{state.params.account}/${state.params.id}`)
		} else {
			url = OC.generateUrl(`apps/social/api/v1/stream/${state.type}?limit=25&since=` + sinceTimestamp)
		}

		// Get the data
		return axios.get(url).then((response) => {

			if (response.status === -1) {
				throw response.message
			}

			let result = []

			// Also load replies when displaying a single post timeline
			if (state.type === 'single-post') {
				result.push(response.data)
//				axios.get(OC.generateUrl(``)).then((response) => {
//					if (response.status !== -1) {
//						result.concat(response.data.result)
//					}
//				}
			} else {
				result = response.data.result
			}

			// Add results to timeline
			context.commit('addToTimeline', result)

			return response.data
		})
	},
	addToTimeline(context, data) {
		context.commit('addToTimeline', data)
	}
}

export default { state, mutations, getters, actions }
