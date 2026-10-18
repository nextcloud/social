/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { savesData } from '../../../src/utils/saveData.js'

/**
 * @param {boolean} reduced whether `prefers-reduced-data: reduce` matches
 */
function preferReducedData(reduced) {
	vi.spyOn(window, 'matchMedia').mockImplementation((query) => ({
		matches: reduced && query === '(prefers-reduced-data: reduce)',
		media: query,
		addEventListener: () => {},
		removeEventListener: () => {},
	}))
}

describe('savesData', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		delete navigator.connection
	})

	it('is false for a reader who asked for nothing', () => {
		preferReducedData(false)

		expect(savesData()).toBe(false)
	})

	it('honours the browser\'s Save-Data switch', () => {
		preferReducedData(false)
		Object.defineProperty(navigator, 'connection', { value: { saveData: true }, configurable: true })

		expect(savesData()).toBe(true)
	})

	it('honours prefers-reduced-data', () => {
		preferReducedData(true)

		expect(savesData()).toBe(true)
	})
})
