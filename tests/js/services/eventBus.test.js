/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

import eventBus from '../../../src/services/eventBus.js'

describe('eventBus', () => {
	beforeEach(() => {
		eventBus.all.clear()
	})

	it('is a single shared instance so a post can reach the composer', async () => {
		const { default: again } = await import('../../../src/services/eventBus.js')

		expect(again).toBe(eventBus)
	})

	it('delivers the emitted payload object itself to the handler', () => {
		const handler = vi.fn()
		const post = { id: '1', content: '<p>hi</p>' }
		eventBus.on('composer-reply', handler)

		eventBus.emit('composer-reply', post)

		expect(handler).toHaveBeenCalledTimes(1)
		expect(handler.mock.calls[0][0]).toBe(post)
	})

	it('calls every handler of an event in registration order and nothing else', () => {
		const calls = []
		eventBus.on('composer-reply', () => calls.push('first'))
		eventBus.on('composer-reply', () => calls.push('second'))
		eventBus.on('other', () => calls.push('other'))

		eventBus.emit('composer-reply', {})

		expect(calls).toEqual(['first', 'second'])
	})

	it('off with a handler removes only that handler', () => {
		const keep = vi.fn()
		const drop = vi.fn()
		eventBus.on('composer-reply', keep)
		eventBus.on('composer-reply', drop)

		eventBus.off('composer-reply', drop)
		eventBus.emit('composer-reply', {})

		expect(keep).toHaveBeenCalledTimes(1)
		expect(drop).not.toHaveBeenCalled()
	})

	it('off without a handler removes every handler of that event, as the composer does on unmount', () => {
		const first = vi.fn()
		const second = vi.fn()
		const other = vi.fn()
		eventBus.on('composer-reply', first)
		eventBus.on('composer-reply', second)
		eventBus.on('other', other)

		eventBus.off('composer-reply')
		eventBus.emit('composer-reply', {})
		eventBus.emit('other', {})

		expect(first).not.toHaveBeenCalled()
		expect(second).not.toHaveBeenCalled()
		expect(other).toHaveBeenCalledTimes(1)
	})

	it('emitting without listeners is a no-op', () => {
		expect(() => eventBus.emit('composer-reply', { id: '1' })).not.toThrow()
	})

	it('supports a wildcard listener receiving the type and payload', () => {
		const any = vi.fn()
		eventBus.on('*', any)

		eventBus.emit('composer-reply', { id: '1' })

		expect(any).toHaveBeenCalledWith('composer-reply', { id: '1' })
	})
})
