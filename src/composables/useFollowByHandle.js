/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref } from 'vue'
import { useAccountStore } from '../store/account.js'

/**
 * Following somebody from a list of strangers.
 *
 * `FollowButton` cannot do this job: it waits for a relationship the account
 * store has never fetched for an account nobody here follows, so in a list of
 * search results or suggestions it renders nothing at all. Every such list
 * therefore grew its own copy of the same three lines — the handle a request
 * is in flight for, the handles already taken, and a call to the store — and
 * the copies had begun to drift.
 *
 * A handle is what these lists carry, and it is the only durable reference to
 * somebody on another server: the account has no local id until this instance
 * has heard of them, which following is precisely what causes.
 *
 * @return {{following: import('vue').Ref<string>, followed: import('vue').Ref<string[]>, follow: (account: object) => Promise<void>, isFollowed: (account: object) => boolean, isPending: (account: object) => boolean}}
 *         the state a row needs to draw its own button
 */
export function useFollowByHandle() {
	const accountStore = useAccountStore()
	/** the handle a follow is in flight for, so only that row shows a spinner */
	const following = ref('')
	/** the handles taken during this visit; the list itself is not refetched */
	const followed = ref([])

	/**
	 * @param {object} account the row's account, as the server described it
	 * @return {Promise<void>} when the request has settled either way
	 */
	async function follow(account) {
		const handle = account?.acct ?? ''
		if (handle === '' || following.value === handle) {
			return
		}

		following.value = handle
		try {
			const response = await accountStore.followAccount({ accountToFollow: handle })
			if (response) {
				followed.value = [...followed.value, handle]
			}
		} finally {
			// whatever happened, this row is no longer waiting: a failure that
			// left the spinner on was the one thing worse than a failure
			following.value = ''
		}
	}

	/**
	 * @param {object} account the row's account
	 * @return {boolean} whether this visit followed them
	 */
	function isFollowed(account) {
		const handle = account?.acct ?? ''

		return handle !== '' && followed.value.includes(handle)
	}

	/**
	 * A row with no handle is never the row that is waiting: idle is also the
	 * empty string, and without this such a row draws a spinner that never
	 * stops.
	 *
	 * @param {object} account the row's account
	 * @return {boolean} whether their request is in flight
	 */
	function isPending(account) {
		const handle = account?.acct ?? ''

		return handle !== '' && following.value === handle
	}

	return { following, followed, follow, isFollowed, isPending }
}
