/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { asId, isNewerId, newerId, newestId, oldestId } from '../../../src/utils/snowflake.js'

// two real-shaped ids 73 apart: doubles are spaced 256 apart up here, so both
// round to the same Number and neither survives a parseInt
const OLDER = '1789553297940456400'
const NEWER = '1789553297940456473'

describe('asId', () => {
	it('keeps every digit of a twenty-digit id', () => {
		expect(asId(NEWER)).toBe(1789553297940456473n)
	})

	it('holds anything that was never an id at nothing', () => {
		expect(asId('not-a-number')).toBe(0n)
		expect(asId(undefined)).toBe(0n)
		expect(asId(null)).toBe(0n)
		expect(asId('')).toBe(0n)
		expect(asId('12.5')).toBe(0n)
	})
})

describe('isNewerId', () => {
	it('tells two twenty-digit ids apart', () => {
		expect(isNewerId(NEWER, OLDER)).toBe(true)
		expect(isNewerId(OLDER, NEWER)).toBe(false)
	})

	it('is false for the same id, so a marker never moves for nothing', () => {
		expect(isNewerId('42', '42')).toBe(false)
	})

	it('holds anything unreadable at nothing', () => {
		expect(isNewerId(undefined, '0')).toBe(false)
		expect(isNewerId('1', undefined)).toBe(true)
	})
})

describe('newerId', () => {
	it('answers the newer of the two, as a string', () => {
		expect(newerId(NEWER, OLDER)).toBe(NEWER)
		expect(newerId(OLDER, NEWER)).toBe(NEWER)
	})
})

describe('newestId', () => {
	it('answers the newest id of a list, as the string it was given', () => {
		expect(newestId(['20', '50', '30'])).toBe('50')
	})

	it('tells twenty-digit ids apart that a Number cannot', () => {
		// Math.max of the two parsed is 1789553297940456400 — a min_id below
		// the newest post on screen, so that post comes back on every poll
		expect(newestId([OLDER, NEWER])).toBe(NEWER)
	})

	it('skips entries that are not ids', () => {
		expect(newestId([undefined, 'not-a-number', '7'])).toBe('7')
	})

	it('answers undefined for a list with no id in it', () => {
		expect(newestId([])).toBeUndefined()
		expect(newestId([undefined, null])).toBeUndefined()
	})
})

describe('oldestId', () => {
	it('answers the oldest id of a list, as the string it was given', () => {
		expect(oldestId(['20', '50', '30'])).toBe('20')
	})

	it('tells twenty-digit ids apart that a Number cannot', () => {
		// Math.min of the two parsed is 1789553297940456400 — a max_id *above*
		// the oldest post on screen, so the next page repeats it for ever and
		// the end of the timeline is never reached
		expect(oldestId([NEWER, OLDER])).toBe(OLDER)
	})

	it('answers undefined for a list with no id in it', () => {
		expect(oldestId([])).toBeUndefined()
	})
})
