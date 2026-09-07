/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createStore } from 'vuex'

import errors from '../../../src/store/errors.js'
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
		errors.state.errors = []
		store = createStore({ modules: { errors } })
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('starts without errors', () => {
		expect(store.getters.appErrors).toEqual([])
		expect(store.getters.hasErrors).toBe(false)
	})

	it('addError appends an entry stamped with the current time as id', () => {
		store.commit('addError', { title: 'Account lookup failed', message: 'Could not load account bob' })

		expect(store.getters.appErrors).toEqual([
			{ id: Date.now(), title: 'Account lookup failed', message: 'Could not load account bob' },
		])
		expect(store.getters.hasErrors).toBe(true)
	})

	it('keeps errors in the order they were added', () => {
		store.commit('addError', { title: 'first', message: 'a' })
		vi.advanceTimersByTime(5)
		store.commit('addError', { title: 'second', message: 'b' })

		expect(store.getters.appErrors.map(e => e.title)).toEqual(['first', 'second'])
		expect(store.getters.appErrors[1].id - store.getters.appErrors[0].id).toBe(5)
	})

	it('dismissError removes only the entry with that id', () => {
		store.commit('addError', { title: 'first', message: 'a' })
		vi.advanceTimersByTime(1)
		store.commit('addError', { title: 'second', message: 'b' })
		const [first, second] = store.getters.appErrors

		store.commit('dismissError', first.id)

		expect(store.getters.appErrors).toEqual([second])

		store.commit('dismissError', 'unknown')

		expect(store.getters.appErrors).toEqual([second])
	})

	it('clearErrors removes everything', () => {
		store.commit('addError', { title: 'first', message: 'a' })
		vi.advanceTimersByTime(1)
		store.commit('addError', { title: 'second', message: 'b' })

		store.commit('clearErrors')

		expect(store.getters.appErrors).toEqual([])
		expect(store.getters.hasErrors).toBe(false)
	})

	it('addAppError logs the error and stores it', async () => {
		await store.dispatch('addAppError', { title: 'Account lookup failed', message: 'nope' })

		expect(logger.error).toHaveBeenCalledWith('App error', { title: 'Account lookup failed', message: 'nope' })
		expect(store.getters.appErrors).toEqual([{ id: Date.now(), title: 'Account lookup failed', message: 'nope' }])
	})

	it('dismissAppError dismisses by id', async () => {
		await store.dispatch('addAppError', { title: 'x', message: 'y' })
		const { id } = store.getters.appErrors[0]

		await store.dispatch('dismissAppError', id)

		expect(store.getters.hasErrors).toBe(false)
	})
})
