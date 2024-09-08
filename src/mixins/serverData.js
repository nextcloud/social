/**
 * @file Provides global methods for using the serverData structure.
 * @mixin
 *
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * @typedef {object} ServerData
 * @property {string} account - The account that the user wants to follow (Only in 'OStatus.vue')
 * @property {string} cliUrl
 * @property {string} cloudAddress
 * @property {boolean} firstrun
 * @property {boolean} isAdmin
 * @property {string} local - The local part of the account that the user wants to follow
 * @property {boolean} public - False when the page is accessed by an authenticated user. True otherwise
 * @property setup
 */

export default {
	computed: {
		/**
		 * @return {ServerData} Returns the serverData object
		 */
		serverData() {
			if (!this.$store) {
				return {}
			}
			return this.$store.getters.getServerData
		},
		/**
		 * @return {string}
		 */
		hostname() {
			const url = document.createElement('a')
			url.setAttribute('href', this.serverData.cloudAddress)
			return url.hostname
		},
	},
}
