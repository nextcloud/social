/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import { afterFirstTimeline, noteTimelineRequest, resetBootForTests } from '../../../src/services/boot.js'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

/** a request the test settles by hand */
function pending() {
	let resolve
	let reject
	const promise = new Promise((_resolve, _reject) => {
		resolve = _resolve
		reject = _reject
	})

	return { promise, resolve, reject }
}

describe('what waits for the timeline', () => {
	beforeEach(() => {
		resetBootForTests()
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.unstubAllGlobals()
	})

	it('runs the callback once the first timeline request has settled, not before', async () => {
		const request = pending()
		const callback = vi.fn()

		noteTimelineRequest(request.promise)
		afterFirstTimeline(callback)

		// the idle slot passes; the timeline is still in flight
		await vi.advanceTimersByTimeAsync(1000)
		expect(callback).not.toHaveBeenCalled()

		request.resolve({ data: [] })
		await vi.advanceTimersByTimeAsync(0)

		expect(callback).toHaveBeenCalledTimes(1)
	})

	it('goes ahead on a failed timeline too: the sidebar is not hostage to it', async () => {
		const request = pending()
		const callback = vi.fn()

		noteTimelineRequest(request.promise)
		afterFirstTimeline(callback)
		request.reject(new Error('500'))
		await vi.advanceTimersByTimeAsync(1000)

		expect(callback).toHaveBeenCalledTimes(1)
	})

	it('runs after the idle slot on a page with no timeline at all', async () => {
		const callback = vi.fn()

		afterFirstTimeline(callback)
		expect(callback).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(1000)

		expect(callback).toHaveBeenCalledTimes(1)
	})

	it('does not decide at once: the timeline below the sidebar has not asked yet when the sidebar mounts', async () => {
		const request = pending()
		const callback = vi.fn()

		// the sidebar mounts first and registers; the timeline asks a moment
		// later, still before the browser is idle
		afterFirstTimeline(callback)
		noteTimelineRequest(request.promise)
		await vi.advanceTimersByTimeAsync(1000)

		expect(callback).not.toHaveBeenCalled()
		request.resolve({ data: [] })
		await vi.advanceTimersByTimeAsync(0)
		expect(callback).toHaveBeenCalledTimes(1)
	})

	it('only the first request on the page is waited for', async () => {
		const first = pending()
		const second = pending()
		const callback = vi.fn()

		noteTimelineRequest(first.promise)
		noteTimelineRequest(second.promise)
		afterFirstTimeline(callback)
		first.resolve({ data: [] })
		await vi.advanceTimersByTimeAsync(1000)

		// the second page of the same timeline is not something to wait for
		expect(callback).toHaveBeenCalledTimes(1)
	})

	it('uses an idle callback where the browser offers one, with a deadline', () => {
		const requestIdleCallback = vi.fn()
		vi.stubGlobal('requestIdleCallback', requestIdleCallback)

		afterFirstTimeline(() => {})

		expect(requestIdleCallback).toHaveBeenCalledWith(expect.any(Function), { timeout: 1000 })
	})

	it('is told about every request the timeline store sends', async () => {
		setActivePinia(createPinia())
		const store = useTimelineStore()
		const request = pending()
		axios.get.mockReturnValue(request.promise)
		const callback = vi.fn()

		const fetching = store.fetchTimeline()
		afterFirstTimeline(callback)
		await vi.advanceTimersByTimeAsync(1000)
		expect(callback).not.toHaveBeenCalled()

		request.resolve({ data: [] })
		await fetching
		await vi.advanceTimersByTimeAsync(0)

		expect(callback).toHaveBeenCalledTimes(1)
	})
})
