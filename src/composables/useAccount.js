/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, toValue } from 'vue'

import { useAccountStore } from '../store/account.js'
import { useServerData } from './useServerData.js'

/**
 * One account, as the page knows it: the handle a user id resolves to, what
 * the store holds for it, and the reader's relationship with it.
 *
 * @param {import('vue').MaybeRefOrGetter<string>} uid a user id or a full handle
 * @return {object} the account as a set of computed properties
 */
export function useAccount(uid) {
	const accountStore = useAccountStore()
	const { hostname } = useServerData()

	/** the complete account name: a local user id is qualified with this host */
	const profileAccount = computed(() => {
		const value = toValue(uid)
		if (!value) {
			return ''
		}

		return value.indexOf('@') === -1 ? value + '@' + hostname.value : value
	})

	/** @type {import('vue').ComputedRef<import('../types/Mastodon.js').Account|undefined>} */
	const accountInfo = computed(() => accountStore.getAccount(profileAccount.value))

	/**
	 * Whether there is anything to show yet.
	 *
	 * This used to be a store getter of its own, with a comment calling it
	 * "somewhat duplicate with accountInfo(), but needed (for some reason)".
	 * It was the same lookup with `!== undefined` around it, and it still is —
	 * one query, asked once. It says "the store has this account", never "the
	 * server was asked and had none": a view that needs to tell those two
	 * apart has to watch its own lookup.
	 */
	const accountLoaded = computed(() => accountInfo.value !== undefined)

	/** whether the account lives on this instance */
	const isLocal = computed(() => accountInfo.value && !accountInfo.value.acct.includes('@'))

	/** @type {import('vue').ComputedRef<import('../types/Mastodon.js').Relationship|undefined>} */
	const relationship = computed(() => accountInfo.value && accountStore.getRelationshipWith(accountInfo.value.id))

	return { profileAccount, accountInfo, accountLoaded, isLocal, relationship }
}
