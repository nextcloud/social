/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createStore } from 'vuex'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import notifications from '../../../src/store/notifications.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

let store

describe('notifications store', () => {
	beforeEach(() => {
		store = createStore({ modules: { notifications } })
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	describe('fetchUnreadNotifications', () => {
		it('reads the count the server keeps', async () => {
			axios.get.mockResolvedValue({ data: { count: 5 } })

			await store.dispatch('fetchUnreadNotifications')

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/notifications/unread_count'),
			)
			expect(store.getters.unreadNotifications).toBe(5)
		})

		it('starts at nothing waiting', () => {
			expect(store.getters.unreadNotifications).toBe(0)
		})

		it('says nothing when the count cannot be read', async () => {
			axios.get.mockRejectedValue(new Error('offline'))

			await store.dispatch('fetchUnreadNotifications')

			// a badge is not worth interrupting anyone about
			expect(showError).not.toHaveBeenCalled()
			expect(store.getters.unreadNotifications).toBe(0)
		})

		it('treats a missing or unreadable count as none', async () => {
			axios.get.mockResolvedValue({ data: {} })
			await store.dispatch('fetchUnreadNotifications')
			expect(store.getters.unreadNotifications).toBe(0)

			axios.get.mockResolvedValue({ data: { count: 'lots' } })
			await store.dispatch('fetchUnreadNotifications')
			expect(store.getters.unreadNotifications).toBe(0)
		})
	})

	describe('markNotificationsRead', () => {
		it('sends the marker and clears the badge', async () => {
			store.commit('setUnreadNotifications', 5)
			axios.post.mockResolvedValue({ data: {} })

			await store.dispatch('markNotificationsRead', 42)

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/markers'),
				{ notifications: { last_read_id: '42' } },
			)
			expect(store.getters.unreadNotifications).toBe(0)
		})

		it('clears the badge before the server answers', async () => {
			store.commit('setUnreadNotifications', 5)
			let resolve
			axios.post.mockReturnValue(new Promise((_resolve) => { resolve = _resolve }))

			const pending = store.dispatch('markNotificationsRead', 42)

			// the reader is looking at the notifications; the badge is already wrong
			expect(store.getters.unreadNotifications).toBe(0)
			resolve({ data: {} })
			await pending
		})

		it('asks the server what it really thinks when the marker will not save', async () => {
			store.commit('setUnreadNotifications', 5)
			axios.post.mockRejectedValue(new Error('nope'))
			axios.get.mockResolvedValue({ data: { count: 5 } })

			await store.dispatch('markNotificationsRead', 42)

			expect(showError).toHaveBeenCalled()
			expect(store.getters.unreadNotifications).toBe(5)
		})

		it('sends nothing when there is no position to record', async () => {
			await store.dispatch('markNotificationsRead', 0)

			expect(axios.post).not.toHaveBeenCalled()
		})
	})
})
