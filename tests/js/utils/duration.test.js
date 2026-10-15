/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { duration } from '../../../src/utils/duration.js'

describe('a length of time', () => {
	it('is said in the largest unit that still says something', () => {
		expect(duration(45)).toBe('45 seconds')
		expect(duration(90)).toBe('2 minutes')
		expect(duration(7200)).toBe('2 hours')
		expect(duration(3 * 86400)).toBe('3 days')
	})
})
