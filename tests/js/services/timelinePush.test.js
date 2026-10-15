/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/notify_push', () => ({ listen: vi.fn(() => true) }))

/**
 * A fresh copy of the module, because what it guards is module state: whether
 * notify_push has already been subscribed to for this page.
 *
 * @return {Promise<object>} the module, the mocked `listen`, and `push()`, which
 *         plays one event from the server
 */
async function load() {
	vi.resetModules()
	const { listen } = await import('@nextcloud/notify_push')
	listen.mockClear()
	listen.mockReturnValue(true)
	const push = await import('../../../src/services/timelinePush.js')

	return { ...push, listen, push: () => listen.mock.calls[0][1]() }
}

describe('timelinePush', () => {
	let visibility

	const lookAway = () => visibility.mockReturnValue('hidden')
	const comeBack = () => {
		visibility.mockReturnValue('visible')
		document.dispatchEvent(new Event('visibilitychange'))
	}

	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
		visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
	})

	afterEach(() => {
		vi.useRealTimers()
		visibility.mockRestore()
	})

	it('subscribes to notify_push once, however many subscribers there are', async () => {
		const { onTimelinePush, listen } = await load()

		onTimelinePush(vi.fn())
		onTimelinePush(vi.fn())
		onTimelinePush(vi.fn())

		expect(listen).toHaveBeenCalledTimes(1)
		expect(listen).toHaveBeenCalledWith('social_timeline', expect.any(Function))
	})

	it('runs every subscriber once for one pushed event, once the pushes have settled', async () => {
		const { onTimelinePush, push, PUSH_SETTLE_MS } = await load()
		const first = vi.fn()
		const second = vi.fn()
		onTimelinePush(first)
		onTimelinePush(second)

		push()
		expect(first).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)

		expect(first).toHaveBeenCalledTimes(1)
		expect(second).toHaveBeenCalledTimes(1)
	})

	it('gathers a burst of pushes into one run', async () => {
		const { onTimelinePush, push, PUSH_SETTLE_MS } = await load()
		const handler = vi.fn()
		onTimelinePush(handler)

		for (let i = 0; i < 5; i++) {
			push()
			await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS / 3)
		}
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)

		expect(handler).toHaveBeenCalledTimes(1)
	})

	it('lets a steady stream of pushes through no later than the longest wait', async () => {
		const { onTimelinePush, push, PUSH_SETTLE_MS, PUSH_MAX_WAIT_MS } = await load()
		const handler = vi.fn()
		onTimelinePush(handler)

		// never quiet for long enough to settle
		for (let waited = 0; waited < PUSH_MAX_WAIT_MS; waited += PUSH_SETTLE_MS / 2) {
			push()
			await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS / 2)
		}

		expect(handler).toHaveBeenCalledTimes(1)
	})

	it('runs nothing while the tab is hidden, and catches up once when it is looked at', async () => {
		const { onTimelinePush, push, PUSH_SETTLE_MS } = await load()
		const handler = vi.fn()
		onTimelinePush(handler)

		lookAway()
		push()
		push()
		await vi.advanceTimersByTimeAsync(10 * PUSH_SETTLE_MS)
		expect(handler).not.toHaveBeenCalled()

		comeBack()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)
		expect(handler).toHaveBeenCalledTimes(1)

		// a second visibilitychange is not a second catch-up
		comeBack()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)
		expect(handler).toHaveBeenCalledTimes(1)
	})

	it('does not catch up on returning when nothing was pushed meanwhile', async () => {
		const { onTimelinePush, PUSH_SETTLE_MS } = await load()
		const handler = vi.fn()
		onTimelinePush(handler)

		lookAway()
		comeBack()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)

		expect(handler).not.toHaveBeenCalled()
	})

	it('keeps one run of a subscriber in flight, and runs it once more for what came meanwhile', async () => {
		const { onTimelinePush, push, PUSH_SETTLE_MS } = await load()
		let answer
		const handler = vi.fn(() => new Promise((resolve) => {
			answer = resolve
		}))
		onTimelinePush(handler)

		push()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)
		expect(handler).toHaveBeenCalledTimes(1)

		// two more runs' worth while the first is still on its way
		push()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)
		push()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)
		expect(handler).toHaveBeenCalledTimes(1)

		answer()
		await vi.advanceTimersByTimeAsync(0)
		expect(handler).toHaveBeenCalledTimes(2)

		answer()
		await vi.advanceTimersByTimeAsync(0)
		expect(handler).toHaveBeenCalledTimes(2)
	})

	it('runs a subscriber again after an answer that failed', async () => {
		const { onTimelinePush, push, PUSH_SETTLE_MS } = await load()
		const handler = vi.fn(() => Promise.reject(new Error('down')))
		onTimelinePush(handler)

		push()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)
		push()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)

		expect(handler).toHaveBeenCalledTimes(2)
	})

	it('leaves a handler that unsubscribed alone', async () => {
		const { onTimelinePush, offTimelinePush, push, PUSH_SETTLE_MS } = await load()
		const gone = vi.fn()
		const staying = vi.fn()
		onTimelinePush(gone)
		onTimelinePush(staying)

		offTimelinePush(gone)
		push()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)

		expect(gone).not.toHaveBeenCalled()
		expect(staying).toHaveBeenCalledTimes(1)
	})

	it('runs a handler subscribed twice once', async () => {
		const { onTimelinePush, offTimelinePush, push, PUSH_SETTLE_MS } = await load()
		const handler = vi.fn()
		onTimelinePush(handler)
		onTimelinePush(handler)

		push()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)
		expect(handler).toHaveBeenCalledTimes(1)

		offTimelinePush(handler)
		push()
		await vi.advanceTimersByTimeAsync(PUSH_SETTLE_MS)
		expect(handler).toHaveBeenCalledTimes(1)
	})

	it('reports whether push is there, so the caller knows how often to poll', async () => {
		const { onTimelinePush, listen } = await load()
		expect(onTimelinePush(vi.fn())).toBe(true)

		// the answer is remembered rather than asked for again
		listen.mockReturnValue(false)
		expect(onTimelinePush(vi.fn())).toBe(true)
	})

	it('says so when the server has no notify_push', async () => {
		vi.resetModules()
		const { listen } = await import('@nextcloud/notify_push')
		listen.mockReturnValue(false)
		const { onTimelinePush } = await import('../../../src/services/timelinePush.js')

		expect(onTimelinePush(vi.fn())).toBe(false)
	})
})
