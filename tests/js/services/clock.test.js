/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { offTick, onTick, TICK_MS } from '../../../src/services/clock.js'

describe('the shared clock', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('calls back with the time as it passes', () => {
		const listener = vi.fn()
		const stop = onTick(listener)

		vi.advanceTimersByTime(TICK_MS)
		expect(listener).toHaveBeenCalledTimes(1)
		expect(listener.mock.calls[0][0]).toBeTypeOf('number')

		vi.advanceTimersByTime(TICK_MS * 2)
		expect(listener).toHaveBeenCalledTimes(3)

		stop()
	})

	it('runs one interval for any number of listeners', () => {
		const interval = vi.spyOn(globalThis, 'setInterval')
		const first = onTick(vi.fn())
		const second = onTick(vi.fn())

		// one timer per post would be one timer per post
		expect(interval).toHaveBeenCalledTimes(1)

		first()
		second()
	})

	it('stops the interval with the last listener', () => {
		const clear = vi.spyOn(globalThis, 'clearInterval')
		const first = onTick(vi.fn())
		const second = onTick(vi.fn())

		first()
		expect(clear).not.toHaveBeenCalled()

		second()
		expect(clear).toHaveBeenCalledTimes(1)
	})

	it('hears nothing more once a listener has gone', () => {
		const listener = vi.fn()
		onTick(listener)
		offTick(listener)

		vi.advanceTimersByTime(TICK_MS * 3)
		expect(listener).not.toHaveBeenCalled()
	})

	it('is not derailed by a listener unsubscribing from inside a tick', () => {
		const second = vi.fn()
		const first = vi.fn(() => stopFirst())
		const stopFirst = onTick(first)
		const stopSecond = onTick(second)

		vi.advanceTimersByTime(TICK_MS)

		expect(first).toHaveBeenCalledTimes(1)
		expect(second).toHaveBeenCalledTimes(1)

		stopSecond()
	})
})
