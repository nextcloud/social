/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import { useNotificationsStore } from '../../../src/store/notifications.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

let store

describe('notifications store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		store = useNotificationsStore()
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	describe('fetchUnreadNotifications', () => {
		it('reads the count the server keeps', async () => {
			axios.get.mockResolvedValue({ data: { count: 5 } })

			await store.fetchUnreadNotifications()

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/notifications/unread_count'),
			)
			expect(store.unreadNotifications).toBe(5)
		})

		it('starts at nothing waiting', () => {
			expect(store.unreadNotifications).toBe(0)
		})

		it('says nothing when the count cannot be read', async () => {
			axios.get.mockRejectedValue(new Error('offline'))

			await store.fetchUnreadNotifications()

			// a badge is not worth interrupting anyone about
			expect(showError).not.toHaveBeenCalled()
			expect(store.unreadNotifications).toBe(0)
		})

		it('treats a missing or unreadable count as none', async () => {
			axios.get.mockResolvedValue({ data: {} })
			await store.fetchUnreadNotifications()
			expect(store.unreadNotifications).toBe(0)

			axios.get.mockResolvedValue({ data: { count: 'lots' } })
			await store.fetchUnreadNotifications()
			expect(store.unreadNotifications).toBe(0)
		})
	})

	describe('markNotificationsRead', () => {
		it('sends the marker and clears the badge', async () => {
			store.setUnreadNotifications(5)
			axios.post.mockResolvedValue({ data: {} })

			await store.markNotificationsRead(42)

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/api/v1/markers'),
				{ notifications: { last_read_id: '42' } },
			)
			expect(store.unreadNotifications).toBe(0)
		})

		it('clears the badge before the server answers', async () => {
			store.setUnreadNotifications(5)
			let resolve
			axios.post.mockReturnValue(new Promise((_resolve) => { resolve = _resolve }))

			const pending = store.markNotificationsRead(42)

			// the reader is looking at the notifications; the badge is already wrong
			expect(store.unreadNotifications).toBe(0)
			resolve({ data: {} })
			await pending
		})

		it('asks the server what it really thinks when the marker will not save', async () => {
			store.setUnreadNotifications(5)
			axios.post.mockRejectedValue(new Error('nope'))
			axios.get.mockResolvedValue({ data: { count: 5 } })

			await store.markNotificationsRead(42)

			expect(showError).toHaveBeenCalled()
			expect(store.unreadNotifications).toBe(5)
		})

		it('sends nothing when there is no position to record', async () => {
			await store.markNotificationsRead(0)

			expect(axios.post).not.toHaveBeenCalled()
		})
	})
})
