/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { forgetProgress, isUnfinished, loadUnfinished, progressOf, rememberProgress } from '../../../src/services/videoProgress.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

/**
 * @param {string} id the post
 * @param {number} position where the reader stopped
 * @param {number} duration how long it runs
 * @return {object} a status from "continue watching"
 */
function watching(id, position, duration) {
	return { id, watch_progress: { position, duration, updated_at: '2026-10-16T12:00:00.000Z' } }
}

describe('where the reader stopped in a video', () => {
	afterEach(() => {
		forgetProgress()
		vi.mocked(axios.get).mockReset()
	})

	it('knows nothing of a video the reader never started', () => {
		expect(progressOf({ id: '1' })).toBeNull()
		expect(progressOf({ id: '1', watch_progress: null })).toBeNull()
		expect(progressOf({ id: '1', watch_progress: { position: 30, duration: 0 } })).toBeNull()
	})

	it('reads it off a status that carries it', () => {
		expect(progressOf(watching('1', 30, 120))).toEqual({
			position: 30,
			duration: 120,
			fraction: 0.25,
			updatedAt: Date.parse('2026-10-16T12:00:00.000Z'),
		})
	})

	it('remembers what "continue watching" said, for every card of the same video', async () => {
		vi.mocked(axios.get).mockResolvedValue({ data: [watching('7', 60, 600)] })

		const statuses = await loadUnfinished(12)

		expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/videos/continue'), { params: { limit: 12 } })
		expect(statuses.map((status) => status.id)).toEqual(['7'])
		expect(progressOf({ id: '7' }).fraction).toBe(0.1)
	})

	it('answers nothing when the server would not say', async () => {
		vi.mocked(axios.get).mockRejectedValue(new Error('down'))

		expect(await loadUnfinished()).toEqual([])
	})

	/** A player on the page moves the bar of every card for the same video. */
	it('takes what a player reported over an older answer of the server', () => {
		rememberProgress('1', 90, 120)

		expect(progressOf(watching('1', 30, 120)).position).toBe(90)
		expect(progressOf({ id: '1' }).fraction).toBe(0.75)
	})

	it('takes a newer answer of the server over what a player reported', () => {
		vi.useFakeTimers({ now: Date.parse('2026-10-16T11:00:00.000Z') })
		try {
			rememberProgress('1', 90, 120)
		} finally {
			vi.useRealTimers()
		}

		expect(progressOf(watching('1', 30, 120)).position).toBe(30)
	})

	/** Finished is finished: the bar goes, and an older bookmark does not come back. */
	it('forgets a video watched to the end', () => {
		rememberProgress('1', 118, 120)

		expect(progressOf(watching('1', 30, 120))).toBeNull()
		expect(progressOf({ id: '1' })).toBeNull()
	})

	it('counts begun and not finished as unfinished', () => {
		expect(isUnfinished(null)).toBe(false)
		expect(isUnfinished({ fraction: 0.5 })).toBe(true)
		expect(isUnfinished({ fraction: 0.97 })).toBe(false)
	})
})
