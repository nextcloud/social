/**
 * @file provides global account related methods
 * @mixin
 *
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import serverData from './serverData.js'

export default {
	mixins: [
		serverData,
	],
	computed: {
		/** @return {string} the complete account name */
		profileAccount() {
			return (this.uid.indexOf('@') === -1) ? this.uid + '@' + this.hostname : this.uid
		},

		/** @return {import('../types/Mastodon.js').Account} detailed information about an account (account must be loaded in the store first) */
		accountInfo() {
			return this.$store.getters.getAccount(this.profileAccount)
		},

		/**
		 * Somewhat duplicate with accountInfo(), but needed (for some reason) to avoid glitches
		 * where components would first show "user not found" before display an account's account info
		 *
		 * @return {boolean}
		 */
		accountLoaded() {
			return this.$store.getters.accountLoaded(this.profileAccount) !== undefined
		},

		/** @return {boolean} */
		isLocal() {
			return !this.accountInfo.acct.includes('@')
		},
		/** @return {import('../types/Mastodon.js').Relationship} */
		relationship() {
			return this.$store.getters.getRelationshipWith(this.accountInfo.id)
		},
	},
}
