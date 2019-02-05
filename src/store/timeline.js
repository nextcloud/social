/*
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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
		} else {
			url = OC.generateUrl(`apps/social/api/v1/stream/${state.type}?limit=25&since=` + sinceTimestamp)
		}
		return axios.get(url).then((response) => {
			if (response.status === -1) {
				throw response.message
			}
			context.commit('addToTimeline', response.data.result)
			return response.data
		})
	}
}

export default { state, mutations, getters, actions }
