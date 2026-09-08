/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { fromNow, fullDateTime } from '../../../src/utils/relativeTime.js'

const NOW = new Date('2026-09-08T12:00:00Z')
const ago = (seconds) => new Date(NOW.getTime() - seconds * 1000).toISOString()

describe('fromNow', () => {
	it('rounds to the largest unit that fits', () => {
		expect(fromNow(ago(30), NOW)).toBe('30 seconds ago')
		expect(fromNow(ago(5 * 60), NOW)).toBe('5 minutes ago')
		expect(fromNow(ago(3 * 3600), NOW)).toBe('3 hours ago')
		expect(fromNow(ago(2 * 24 * 3600), NOW)).toBe('2 days ago')
		expect(fromNow(ago(3 * 7 * 24 * 3600), NOW)).toBe('3 weeks ago')
		expect(fromNow(ago(5 * 30 * 24 * 3600), NOW)).toBe('5 months ago')
		expect(fromNow(ago(2 * 365 * 24 * 3600), NOW)).toBe('2 years ago')
	})

	it('uses the words a reader expects for the nearest units', () => {
		// numeric: 'auto' is what turns 1 day ago into yesterday
		expect(fromNow(ago(24 * 3600), NOW)).toBe('yesterday')
		expect(fromNow(ago(0), NOW)).toBe('now')
	})

	it('reads the future as the future, which is what a poll deadline is', () => {
		const inTwoHours = new Date(NOW.getTime() + 2 * 3600 * 1000).toISOString()

		expect(fromNow(inTwoHours, NOW)).toBe('in 2 hours')
	})

	it('takes a Date, a string or a number', () => {
		expect(fromNow(new Date(NOW.getTime() - 60000), NOW)).toBe('1 minute ago')
		expect(fromNow(NOW.getTime() - 60000, NOW)).toBe('1 minute ago')
	})

	it('says nothing about a date it cannot read', () => {
		expect(fromNow('not a date', NOW)).toBe('')
		expect(fromNow(undefined, NOW)).toBe('')
	})
})

describe('fullDateTime', () => {
	it('writes out a date with its time', () => {
		const formatted = fullDateTime('2026-09-08T12:00:00Z')

		// the exact wording is the platform's; what matters is that all the
		// parts are there
		expect(formatted).toMatch(/2026/)
		expect(formatted).toMatch(/\d{1,2}[:.]\d{2}/)
		expect(formatted.length).toBeGreaterThan(10)
	})

	it('says nothing about a date it cannot read', () => {
		expect(fullDateTime('nope')).toBe('')
	})
})
