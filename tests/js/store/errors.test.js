/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useErrorsStore } from '../../../src/store/errors.js'
import logger from '../../../src/services/logger.js'

vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

describe('errors store', () => {
	let store

	beforeEach(() => {
		vi.clearAllMocks()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-09-07T10:00:00.000Z'))
		setActivePinia(createPinia())
		store = useErrorsStore()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('starts without errors', () => {
		expect(store.appErrors).toEqual([])
		expect(store.hasErrors).toBe(false)
	})

	it('addError appends an entry with a unique numeric id', () => {
		store.addError({ title: 'Account lookup failed', message: 'Could not load account bob' })

		expect(store.appErrors).toEqual([
			{ id: expect.any(Number), title: 'Account lookup failed', message: 'Could not load account bob' },
		])
		expect(store.hasErrors).toBe(true)
	})

	it('keeps errors in the order they were added and gives each a distinct id', () => {
		store.addError({ title: 'first', message: 'a' })
		store.addError({ title: 'second', message: 'b' })

		expect(store.appErrors.map((e) => e.title)).toEqual(['first', 'second'])
		expect(store.appErrors[1].id).not.toBe(store.appErrors[0].id)
	})

	it('gives back-to-back errors distinct ids within the same millisecond so dismissing one keeps the other', () => {
		// the clock is frozen, so a Date.now() based id would collide
		store.addError({ title: 'first', message: 'a' })
		store.addError({ title: 'second', message: 'b' })
		const [first, second] = store.appErrors
		expect(first.id).not.toBe(second.id)

		store.dismissError(first.id)

		expect(store.appErrors).toEqual([second])
	})

	it('dismissError removes only the entry with that id', () => {
		store.addError({ title: 'first', message: 'a' })
		vi.advanceTimersByTime(1)
		store.addError({ title: 'second', message: 'b' })
		const [first, second] = store.appErrors

		store.dismissError(first.id)

		expect(store.appErrors).toEqual([second])

		store.dismissError('unknown')

		expect(store.appErrors).toEqual([second])
	})

	it('clearErrors removes everything', () => {
		store.addError({ title: 'first', message: 'a' })
		vi.advanceTimersByTime(1)
		store.addError({ title: 'second', message: 'b' })

		store.clearErrors()

		expect(store.appErrors).toEqual([])
		expect(store.hasErrors).toBe(false)
	})

	it('addAppError logs the error and stores it', async () => {
		await store.addAppError({ title: 'Account lookup failed', message: 'nope' })

		expect(logger.error).toHaveBeenCalledWith('App error', { title: 'Account lookup failed', message: 'nope' })
		expect(store.appErrors).toEqual([{ id: expect.any(Number), title: 'Account lookup failed', message: 'nope' }])
	})

	it('dismissAppError dismisses by id', async () => {
		await store.addAppError({ title: 'x', message: 'y' })
		const { id } = store.appErrors[0]

		await store.dismissAppError(id)

		expect(store.hasErrors).toBe(false)
	})
})
