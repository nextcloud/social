/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { accountHue, accountStyle } from './accountColour.js'

describe('accountHue', () => {
	it('gives the same handle the same hue every time', () => {
		expect(accountHue('maya')).toBe(accountHue('maya'))
		expect(accountHue('maya@devel')).toBe(accountHue('maya@devel'))
	})

	it('does not care about a leading @ or about case', () => {
		expect(accountHue('@maya@devel')).toBe(accountHue('maya@devel'))
		expect(accountHue('Maya@Devel')).toBe(accountHue('maya@devel'))
	})

	it('tells accounts apart', () => {
		const hues = ['maya', 'hugo', 'greta', 'tomas', 'ines', 'felix', 'zoe', 'omar']
			.map((handle) => accountHue(handle))

		expect(new Set(hues).size).toBeGreaterThan(5)
	})

	it('answers a hue for anything, including nothing', () => {
		for (const input of ['', null, undefined, '@', 'a']) {
			const hue = accountHue(input)
			expect(Number.isInteger(hue)).toBe(true)
			expect(hue).toBeGreaterThanOrEqual(0)
			expect(hue).toBeLessThan(360)
		}
	})

	/**
	 * The reds belong to errors. An account whose colour is the colour of a
	 * refusal reads as a warning wherever it appears.
	 */
	it('steps over the hues that read as an error', () => {
		const handles = Array.from({ length: 400 }, (unused, i) => `account${i}`)

		for (const handle of handles) {
			const hue = accountHue(handle)
			expect(hue < 350 && hue >= 12).toBe(true)
		}
	})
})

describe('accountStyle', () => {
	it('carries the hue as a custom property', () => {
		expect(accountStyle({ acct: 'maya@devel' }))
			.toEqual({ '--account-hue': String(accountHue('maya@devel')) })
	})

	it('takes a handle as well as an account', () => {
		expect(accountStyle('maya@devel')).toEqual(accountStyle({ acct: 'maya@devel' }))
	})

	it('falls back through the fields an account might have', () => {
		expect(accountStyle({ username: 'hugo' })).toEqual(accountStyle('hugo'))
		expect(accountStyle({})).toEqual(accountStyle(''))
		expect(accountStyle(null)).toEqual(accountStyle(''))
	})
})
