/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const post = {
	id: '1',
	uri: 'https://cloud.example.org/users/alice/statuses/1',
	content: '<p>hello</p>',
	created_at: '2026-01-01T10:00:00.000Z',
	account: { acct: 'alice', display_name: 'Alice' },
}

/**
 * The first screenful the server put in the document.
 *
 * Without it the first screen is a staircase: fetch the bundle, mount, and
 * only then ask the server — a second round trip and a full Nextcloud boot
 * before anything a person came to read is on screen.
 */
describe('the first page the server rendered with', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useTimelineStore()
		axios.get.mockReset()
	})

	it('answers the first fetch without asking the server', async () => {
		store.seededPage = [post]

		const page = await store.fetchTimeline()

		expect(axios.get).not.toHaveBeenCalled()
		expect(page).toEqual([post])
		expect(store.timeline).toContain('1')
	})

	/** It is a snapshot of one moment, so it answers once and never again. */
	it('is used once and then the server is asked', async () => {
		store.seededPage = [post]
		await store.fetchTimeline()

		axios.get.mockResolvedValue({ data: [] })
		await store.fetchTimeline()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(store.seededPage).toBeNull()
	})

	/** A cursor is a different question, and the seeded page is not its answer. */
	it('is not used for a page the reader scrolled to', async () => {
		store.seededPage = [post]
		axios.get.mockResolvedValue({ data: [] })

		await store.fetchTimeline({ max_id: '99' })

		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	/** It is the home timeline's, and no other list's. */
	it('is not used for a timeline it was not rendered for', async () => {
		store.seededPage = [post]
		store.type = 'notifications'
		axios.get.mockResolvedValue({ data: [] })

		await store.fetchTimeline()

		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('is absent on a page the server rendered without one', async () => {
		axios.get.mockResolvedValue({ data: [] })

		await store.fetchTimeline()

		expect(axios.get).toHaveBeenCalledTimes(1)
	})
})
