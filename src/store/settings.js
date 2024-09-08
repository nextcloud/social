/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const state = {
	serverData: {},
}
const mutations = {
	setServerData(state, data) {
		state.serverData = data
	},
	setServerDataEntry(state, key, value) {
		state.serverData[key] = value
	},
}
const getters = {
	getServerData(state) {
		return state.serverData
	},
}

export default { state, mutations, getters }
