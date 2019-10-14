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

/**
 * This file provides various computed properties related to the currently
 * logged-in user.
 *
 * @mixin
 */

import serverData from './serverData'

export default {
	mixins: [
		serverData
	],
	computed: {
		/**
		 * Returns an object describing the currently logged-in user
		 *
		 * @returns {Object}
		 *
		 */
		currentUser() {
			return OC.getCurrentUser()
		},
		/**
		 * Returns the ActivityPub ID of the currently logged-in user
		 *
		 * @returns {String}
		 *
		 */
		socialId() {
			return '@' + this.cloudId
		},
		/**
		 * Returns the ActivityPub ID of the currently logged-in user,
		 * minus the leading '@'
		 *
		 * @returns {String}
		 *
		 */
		cloudId() {
			return this.currentUser.uid + '@' + this.hostname
		}
	}
}
