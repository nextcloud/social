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
	since: new Date()
}
const mutations = {
	addToTimeline(state, data) {
		for (let item in data) {
			state.since = data[item].published
			Vue.set(state.timeline, data[item].id, data[item])
		}
	},
	addPost(state, data) {
		// FIXME: push data we receive to the timeline array
		// state.timeline.push(data)
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
			sinceTimestamp = Date.parse(state.since) / 1000
		}
		return axios.get(OC.generateUrl('apps/social/api/v1/timeline?limit=5&since=' + sinceTimestamp)).then((response) => {
			if (response.status === -1) {
				throw response.message
			}
			context.commit('addToTimeline', response.data.result)
			return response.data
		})
	}
}

export default { state, mutations, getters, actions }
