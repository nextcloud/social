/**
 * @file provides global account related methods
 * @mixin
 *
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mapStores } from 'pinia'

import { useAccountStore } from '../store/account.js'
import serverData from './serverData.js'

export default {
	mixins: [
		serverData,
	],
	computed: {
		...mapStores(useAccountStore),
		/** @return {string} the complete account name */
		profileAccount() {
			if (!this.uid) return ''
			return (this.uid.indexOf('@') === -1) ? this.uid + '@' + this.hostname : this.uid
		},

		/** @return {import('../types/Mastodon.js').Account} detailed information about an account (account must be loaded in the store first) */
		accountInfo() {
			return this.accountStore.getAccount(this.profileAccount)
		},

		/**
		 * @return {boolean} whether accountInfo() has anything to show yet
		 */
		accountLoaded() {
			return this.accountInfo !== undefined
		},

		/** @return {boolean} */
		isLocal() {
			return this.accountInfo && !this.accountInfo.acct.includes('@')
		},
		/** @return {import('../types/Mastodon.js').Relationship} */
		relationship() {
			return this.accountInfo && this.accountStore.getRelationshipWith(this.accountInfo.id)
		},
	},
}
