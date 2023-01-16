/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @file Provides global methods for using the serverData structure.
 *
 * @mixin
 *
 * @author Julius Härtl <jus@bitgrid.net>
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
		 * @return {Partial<ServerData>} Returns the serverData object
		 */
		serverData() {
			if (!this.$store) {
				return {}
			}
			return this.$store.getters.getServerData
		},
		hostname() {
			const url = document.createElement('a')
			url.setAttribute('href', this.serverData.cloudAddress)
			return url.hostname
		},
	},
}
