/*
 * @copyright Copyright (c) 2019 Cyrille Bollu <cyrpub@bollu.be>
 *
 * @author Cyrille Bollu <cyrpub@bollu.be>
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

/*
 * This file provides global account related methods
 */

import serverData from './serverData'

export default {
	mixins: [
		serverData
	],
	methods: {
		// Returns the complete account name
		profileAccount(uid) {
			return (uid.indexOf('@') === -1) ? uid + '@' + this.hostname : uid
		},
		// Returns detailed information about an account (account must be loaded in the store first)
		accountInfo(uid) {
			return this.$store.getters.getAccount(this.profileAccount(uid))
		},
		// Somewhat duplicate with accountInfo(), but needed (for some reason) to avoid glitches
		// where components would first show "user not found" before display an account's account info
		accountLoaded(uid) {
			return this.$store.getters.accountLoaded(this.profileAccount(uid))
		}
	}
}
