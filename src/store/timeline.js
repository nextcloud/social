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
	type: 'home'
}
const mutations = {
	addToTimeline(state, data) {
		for (let item in data) {
			state.since = data[item].publishedTime
			data[item].actor_info = {}
			Vue.set(state.timeline, data[item].id, data[item])
		}
	},
	resetTimeline(state) {
		state.timeline = {}
		state.since = Math.floor(Date.now() / 1000) + 1
	},
	setTimelineType(state, type) {
		state.type = type
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
	changeTimelineType(context, type) {
		context.commit('resetTimeline')
		context.commit('setTimelineType', type)
	},
	post(context, post) {
		return axios.post(OC.generateUrl('apps/social/api/v1/post'), { data: post }).then((response) => {
			context.commit('addPost', { data: response.data })
		}).catch((error) => {
			OC.Notification.showTemporary('Failed to create a post')
			console.error('Failed to create a post', error)
		})
	},
	refreshTimeline(context, account) {
		return this.dispatch('fetchTimeline', { account: account, sinceTimestamp: Math.floor(Date.now() / 1000) + 1 })
	},
	fetchTimeline(context, { account, sinceTimestamp }) {
		if (typeof sinceTimestamp === 'undefined') {
			sinceTimestamp = state.since - 1
		}
		return axios.get(OC.generateUrl(`apps/social/api/v1/stream/${state.type}?limit=5&since=` + sinceTimestamp)).then((response) => {
			if (response.status === -1) {
				throw response.message
			}
			context.commit('addToTimeline', response.data.result)
			return response.data
		})
	}
}

export default { state, mutations, getters, actions }
