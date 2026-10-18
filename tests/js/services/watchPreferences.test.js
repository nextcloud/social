/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { autoplayNext, setAutoplayNext, setTheaterMode, theaterMode } from '../../../src/services/watchPreferences.js'

describe('how the reader likes the watch page', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		window.localStorage.clear()
	})

	it('starts with the column beside the player and nothing opening by itself', () => {
		expect(theaterMode()).toBe(false)
		expect(autoplayNext()).toBe(false)
	})

	it('remembers each in this browser', () => {
		setTheaterMode(true)
		setAutoplayNext(true)
		expect(theaterMode()).toBe(true)
		expect(autoplayNext()).toBe(true)

		setTheaterMode(false)
		expect(theaterMode()).toBe(false)
		expect(autoplayNext()).toBe(true)
	})

	it('goes on without a browser store to keep them in', () => {
		vi.spyOn(window, 'localStorage', 'get').mockImplementation(() => {
			throw new Error('blocked')
		})

		expect(() => setTheaterMode(true)).not.toThrow()
		expect(theaterMode()).toBe(false)
	})
})
