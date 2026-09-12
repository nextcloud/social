/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nameForTransition, transitionsWanted, withViewTransition } from '../../../src/utils/viewTransition.js'

function withApi(implementation) {
	document.startViewTransition = implementation
}

function wantsLessMotion(reduce) {
	window.matchMedia = vi.fn(() => ({ matches: reduce, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
}

describe('view transitions as an enhancement', () => {
	afterEach(() => {
		delete document.startViewTransition
		vi.restoreAllMocks()
	})

	it('are wanted only where the browser has them and motion is welcome', () => {
		wantsLessMotion(false)
		expect(transitionsWanted()).toBe(false)

		withApi(vi.fn())
		expect(transitionsWanted()).toBe(true)

		wantsLessMotion(true)
		expect(transitionsWanted()).toBe(false)
	})

	it('applies the change directly when the browser has no transitions', async () => {
		wantsLessMotion(false)
		const change = vi.fn()

		await withViewTransition(change)

		expect(change).toHaveBeenCalledTimes(1)
	})

	it('applies the change exactly once inside a transition', async () => {
		wantsLessMotion(false)
		const change = vi.fn()
		withApi((callback) => {
			const done = Promise.resolve(callback())
			return { updateCallbackDone: done, finished: done, ready: done }
		})

		await withViewTransition(change)

		expect(change).toHaveBeenCalledTimes(1)
	})

	it('still applies the change when the transition itself fails', async () => {
		wantsLessMotion(false)
		const change = vi.fn()
		withApi((callback) => {
			callback()
			return { updateCallbackDone: Promise.reject(new Error('interrupted')) }
		})

		await withViewTransition(change)

		expect(change).toHaveBeenCalledTimes(1)
	})

	describe('naming an element for one transition', () => {
		it('names it and hands back the way to release it', () => {
			wantsLessMotion(false)
			withApi(vi.fn())
			const element = document.createElement('div')

			const release = nameForTransition(element, 'social-media')
			expect(element.style.viewTransitionName).toBe('social-media')

			release()
			expect(element.style.viewTransitionName).toBe('')
		})

		it('does nothing without an element, and nothing without the API', () => {
			wantsLessMotion(false)
			expect(() => nameForTransition(null, 'x')()).not.toThrow()

			const element = document.createElement('div')
			nameForTransition(element, 'x')
			expect(element.style.viewTransitionName).toBe('')
		})
	})
})
