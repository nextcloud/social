/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

/**
 * A fake `matchMedia` whose answer the test flips.
 *
 * @param {boolean} matches the first answer
 * @return {{ query: object, flip: (matches: boolean) => void, asked: string[] }} the query object, a way to change the answer, the queries asked for
 */
function fakeMatchMedia(matches) {
	const listeners = new Set()
	const asked = []
	const query = {
		matches,
		addEventListener: (type, fn) => listeners.add(fn),
		removeEventListener: (type, fn) => listeners.delete(fn),
	}
	vi.stubGlobal('matchMedia', (q) => {
		asked.push(q)
		return query
	})
	return {
		query,
		asked,
		listeners,
		flip: (value) => {
			query.matches = value
			listeners.forEach((fn) => fn({ matches: value }))
		},
	}
}

async function load() {
	vi.resetModules()
	return import('../../../src/services/phone.js')
}

describe('services/phone', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('asks the browser one question, at the width the stylesheets use', async () => {
		const media = fakeMatchMedia(true)
		const { isPhone, PHONE_WIDTH } = await load()

		expect(isPhone()).toBe(true)
		expect(media.asked).toEqual([`(max-width: ${PHONE_WIDTH}px)`])
		expect(PHONE_WIDTH).toBe(600)
	})

	it('tells a subscriber when the answer changes, until they stop listening', async () => {
		const media = fakeMatchMedia(false)
		const { isPhone, onPhoneChange } = await load()
		const seen = []
		const stop = onPhoneChange((phone) => seen.push(phone))

		media.flip(true)
		expect(isPhone()).toBe(true)
		expect(seen).toEqual([true])

		stop()
		media.flip(false)
		expect(seen).toEqual([true], 'nothing after stop()')
		expect(media.listeners.size).toBe(0)
	})

	it('is never a phone where there is no matchMedia, and never throws', async () => {
		vi.stubGlobal('matchMedia', undefined)
		const { isPhone, onPhoneChange } = await load()

		expect(isPhone()).toBe(false)
		expect(() => onPhoneChange(() => {})()).not.toThrow()
	})
})
