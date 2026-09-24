/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { earliestSchedule, isTooSoon, proposedSchedule } from '../../../src/utils/schedule.js'

const now = Date.UTC(2026, 9, 1, 9, 2, 30)

describe('schedule', () => {
	it('offers nothing sooner than the five minutes the server insists on', () => {
		expect(earliestSchedule(now).getTime()).toBe(now + 5 * 60 * 1000)
	})

	it('refuses a time under five minutes out, and anything that is not a time', () => {
		expect(isTooSoon(new Date(now + 4 * 60 * 1000), now)).toBe(true)
		expect(isTooSoon(new Date(now + 5 * 60 * 1000), now)).toBe(false)
		expect(isTooSoon(new Date('not a date'), now)).toBe(true)
		expect(isTooSoon(null, now)).toBe(true)
	})

	it('proposes an hour out, on the picker\'s five-minute step', () => {
		expect(proposedSchedule(now).toISOString()).toBe('2026-10-01T10:05:00.000Z')
	})
})
