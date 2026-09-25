/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import {
	fetchInterests,
	hasInterestsFeed,
	isTracking,
	lessLikeThis,
	saveInterestSettings,
	sendSignals,
	undoLessLikeThis,
} from '../../../src/services/interests.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/auth', async (importOriginal) => ({
	...(await importOriginal()),
	getRequestToken: () => 'token/+1',
}))

const API = '/index.php/apps/social/api/v1/interests'
const events = [{ status_id: '1789344497747043682', kind: 'dwell', ms: 5400, context: 'home' }]

describe('the interests API', () => {
	beforeEach(() => {
		for (const method of ['get', 'post', 'put', 'delete']) {
			axios[method].mockReset().mockResolvedValue({ data: { thin: true } })
		}
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('tracks only while the feature, the reader and the pause all allow it', () => {
		expect(isTracking({ enabled: true, learning: true, paused: false })).toBe(true)
		expect(isTracking({ enabled: false, learning: true, paused: false })).toBe(false)
		expect(isTracking({ enabled: true, learning: false, paused: false })).toBe(false)
		expect(isTracking({ enabled: true, learning: true, paused: true })).toBe(false)
		expect(isTracking(undefined)).toBe(false)
	})

	it('keeps the feed while paused', () => {
		expect(hasInterestsFeed({ enabled: true, learning: true, paused: true })).toBe(true)
		expect(hasInterestsFeed({ enabled: true, learning: false })).toBe(false)
		expect(hasInterestsFeed(null)).toBe(false)
	})

	it('reads and saves the state', async () => {
		expect(await fetchInterests()).toEqual({ thin: true })
		expect(axios.get).toHaveBeenCalledWith(API)

		await saveInterestSettings({ noticeAcknowledged: true })
		expect(axios.put).toHaveBeenCalledWith(API + '/settings', { noticeAcknowledged: true })
	})

	it('sends less like this and takes it back by the post\'s id', async () => {
		await lessLikeThis('1789344497747043682')
		expect(axios.post).toHaveBeenCalledWith(API + '/less/1789344497747043682')

		await undoLessLikeThis('1789344497747043682')
		expect(axios.delete).toHaveBeenCalledWith(API + '/less/1789344497747043682')
	})

	it('posts signals as a request while the page stays', async () => {
		await sendSignals(events)

		expect(axios.post).toHaveBeenCalledWith(API + '/signals', { events })
	})

	it('sends nothing for nothing', async () => {
		await sendSignals([])

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('sends a beacon on the way out, the token in the query and the body as JSON', async () => {
		const sendBeacon = vi.fn(() => true)
		vi.stubGlobal('navigator', { sendBeacon })

		await sendSignals(events, { beacon: true })

		expect(axios.post).not.toHaveBeenCalled()
		const [target, blob] = sendBeacon.mock.calls[0]
		expect(target).toBe(API + '/signals?requesttoken=token%2F%2B1')
		expect(blob.type).toBe('application/json')
		expect(JSON.parse(await blob.text())).toEqual({ events })
	})

	it('falls back to a request when the browser will not queue the beacon', async () => {
		vi.stubGlobal('navigator', { sendBeacon: vi.fn(() => false) })

		await sendSignals(events, { beacon: true })

		expect(axios.post).toHaveBeenCalledWith(API + '/signals', { events })
	})
})
