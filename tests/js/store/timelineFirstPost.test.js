/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useAccountStore } from '../../../src/store/account.js'
import { FIRST_POST_KEY, useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

/**
 * The timeline store, next to as much of the account store as the decision
 * reads: the reader's own account, as it was when the page loaded.
 *
 * @param {object|undefined} currentAccount the account the getter answers with
 * @return {object} the timeline store
 */
function makeStore(currentAccount) {
	setActivePinia(createPinia())
	if (currentAccount !== undefined) {
		const url = 'https://cloud.example.org/users/alice'
		const account = useAccountStore()
		account.addAccount({ actorId: url, data: { url, ...currentAccount } })
		account.setCurrentAccount(`${currentAccount.acct}@cloud.example.org`)
	}

	return useTimelineStore()
}

describe('celebrateFirstPost', () => {
	beforeEach(() => {
		window.localStorage.clear()
		vi.restoreAllMocks()
	})

	it('celebrates a post from an account that had none', async () => {
		const store = makeStore({ acct: 'alice', statuses_count: 0 })

		expect(store.celebrateFirstPost()).toBe(true)
		expect(store.isCelebratingFirstPost).toBe(true)
		// and it is written down, so a reload does not do it again
		expect(window.localStorage.getItem(FIRST_POST_KEY)).not.toBeNull()
	})

	it('does not celebrate the second post', async () => {
		const store = makeStore({ acct: 'alice', statuses_count: 0 })

		expect(store.celebrateFirstPost()).toBe(true)
		store.endFirstPostCelebration()

		expect(store.celebrateFirstPost()).toBe(false)
		expect(store.isCelebratingFirstPost).toBe(false)
	})

	it('does not celebrate again in a browser that already has the flag', async () => {
		window.localStorage.setItem(FIRST_POST_KEY, '1')
		const store = makeStore({ acct: 'alice', statuses_count: 0 })

		expect(store.celebrateFirstPost()).toBe(false)
		expect(store.isCelebratingFirstPost).toBe(false)
	})

	// somebody who has been here for years and cleared their browser storage is
	// not congratulated on post number 1001
	it('never celebrates a reader who has posted before, whatever the browser has forgotten', async () => {
		const store = makeStore({ acct: 'alice', statuses_count: 1000 })

		expect(store.celebrateFirstPost()).toBe(false)
		expect(store.isCelebratingFirstPost).toBe(false)
		expect(window.localStorage.getItem(FIRST_POST_KEY)).toBeNull()
	})

	it.each([
		['the account has not loaded yet', undefined],
		['the account says nothing about how much it has posted', { acct: 'alice' }],
	])('holds back when %s', async (name, currentAccount) => {
		const store = makeStore(currentAccount)

		expect(store.celebrateFirstPost()).toBe(false)
		expect(store.isCelebratingFirstPost).toBe(false)
	})

	describe('with a browser that refuses to store anything', () => {
		beforeEach(() => {
			// what a private window does: it throws, it does not answer
			vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => {
				throw new Error('The operation is insecure')
			})
			vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
				throw new Error('The operation is insecure')
			})
		})

		it('still celebrates, and does not throw on the way', async () => {
			const store = makeStore({ acct: 'alice', statuses_count: 0 })

			// synchronous now: a Pinia action returns what it returns, where a
			// Vuex dispatch always handed back a promise
			expect(store.celebrateFirstPost()).toBe(true)
			expect(store.isCelebratingFirstPost).toBe(true)
		})

		it('still refuses the second post, on the session flag alone', async () => {
			const store = makeStore({ acct: 'alice', statuses_count: 0 })

			store.celebrateFirstPost()
			store.endFirstPostCelebration()

			expect(store.celebrateFirstPost()).toBe(false)
		})

		it('still never celebrates a reader who has posted before', async () => {
			const store = makeStore({ acct: 'alice', statuses_count: 42 })

			expect(store.celebrateFirstPost()).toBe(false)
		})
	})
})
