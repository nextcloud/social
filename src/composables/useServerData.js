/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed } from 'vue'

import { useSettingsStore } from '../store/settings.js'

/**
 * @typedef {object} ServerData
 * @property {string} account - The account that the user wants to follow (Only in 'OStatus.vue')
 * @property {string} cliUrl - The URL the background jobs address this instance by
 * @property {string} cloudAddress - This instance's own address
 * @property {boolean} firstrun - Whether the reader has not used the app before
 * @property {boolean} isAdmin - Whether the reader administers this instance
 * @property {string} local - The local part of the account that the user wants to follow
 * @property {boolean} public - False when the page is accessed by an authenticated user. True otherwise
 * @property {boolean} setup - Whether the instance still needs its address confirmed
 */

/**
 * What the server told the page about itself.
 *
 * @return {{serverData: import('vue').ComputedRef<ServerData>, hostname: import('vue').ComputedRef<string>}}
 */
export function useServerData() {
	const settingsStore = useSettingsStore()

	/** @type {import('vue').ComputedRef<ServerData>} */
	const serverData = computed(() => settingsStore.getServerData)

	/**
	 * The host of this instance, as the browser parses its address — so a
	 * cloud address with a scheme, a port or a path still gives the bare host
	 * that a handle is built from.
	 */
	const hostname = computed(() => {
		const url = document.createElement('a')
		url.setAttribute('href', serverData.value.cloudAddress)

		return url.hostname
	})

	return { serverData, hostname }
}
