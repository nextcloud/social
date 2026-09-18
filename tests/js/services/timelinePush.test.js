/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/notify_push', () => ({ listen: vi.fn(() => true) }))

/**
 * A fresh copy of the module, because what it guards is module state: whether
 * notify_push has already been subscribed to for this page.
 *
 * @return {Promise<object>} the module and the mocked `listen`
 */
async function load() {
	vi.resetModules()
	const { listen } = await import('@nextcloud/notify_push')
	listen.mockClear()
	listen.mockReturnValue(true)
	const push = await import('../../../src/services/timelinePush.js')

	return { ...push, listen }
}

describe('timelinePush', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('subscribes to notify_push once, however many subscribers there are', async () => {
		const { onTimelinePush, listen } = await load()

		onTimelinePush(vi.fn())
		onTimelinePush(vi.fn())
		onTimelinePush(vi.fn())

		expect(listen).toHaveBeenCalledTimes(1)
		expect(listen).toHaveBeenCalledWith('social_timeline', expect.any(Function))
	})

	it('runs every subscriber once for one pushed event', async () => {
		const { onTimelinePush, listen } = await load()
		const first = vi.fn()
		const second = vi.fn()
		onTimelinePush(first)
		onTimelinePush(second)

		listen.mock.calls[0][1]()

		expect(first).toHaveBeenCalledTimes(1)
		expect(second).toHaveBeenCalledTimes(1)
	})

	it('leaves a handler that unsubscribed alone', async () => {
		const { onTimelinePush, offTimelinePush, listen } = await load()
		const gone = vi.fn()
		const staying = vi.fn()
		onTimelinePush(gone)
		onTimelinePush(staying)

		offTimelinePush(gone)
		listen.mock.calls[0][1]()

		expect(gone).not.toHaveBeenCalled()
		expect(staying).toHaveBeenCalledTimes(1)
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
