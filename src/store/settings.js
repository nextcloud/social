/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'

/**
 * What this store holds, for the getter that is handed it.
 *
 * @typedef {object} SettingsState
 * @property {import('../composables/useServerData.js').ServerData} serverData what the server put in the page
 */

/**
 * What the server told the page about itself, as `templates/main.php` sent it.
 */
export const useSettingsStore = defineStore('settings', {
	state: () => ({
		serverData: {},
	}),

	getters: {
		/**
		 * @param {SettingsState} state the store state
		 * @return {import('../composables/useServerData.js').ServerData} the whole block
		 */
		getServerData(state) {
			return state.serverData
		},
	},

	actions: {
		setServerData(data) {
			this.serverData = data
		},
		setServerDataEntry({ key, value }) {
			this.serverData[key] = value
		},
	},
})
