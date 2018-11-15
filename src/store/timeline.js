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

const state = {
	timeline: [],
	since: new Date()
}
const mutations = {
	addToTimeline(state, data) {
		for (let item in data) {
			state.since = data[item].published
			state.timeline.push(data[item])
		}
	}
}
const getters = {
	getTimeline(state) {
		return state.timeline
	}
}
const actions = {
	post(context, post) {
		return axios.post(OC.generateUrl('apps/social/api/v1/post'), {data: post}).then((response) => {
			let uid = ''
			context.commit('addPost', { uid: uid, data: response.data })
		})
	},
	fetchTimeline(context, account) {
		const sinceTimestamp = Date.parse(state.since) / 1000
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
