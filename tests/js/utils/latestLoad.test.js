/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { latestLoad } from '../../../src/utils/latestLoad.js'

describe('latestLoad', () => {
	it('lets only the newest load commit', () => {
		const loads = latestLoad()
		const first = loads.begin()
		expect(first()).toBe(true)

		const second = loads.begin()
		expect(first()).toBe(false)
		expect(second()).toBe(true)
	})

	it('hands work that continues a load the same generation, and outdates it with the next one', () => {
		const loads = latestLoad()
		loads.begin()
		const more = loads.current()
		expect(more()).toBe(true)

		loads.begin()
		expect(more()).toBe(false)
	})

	it('keeps each view its own count', () => {
		const one = latestLoad()
		const other = latestLoad()
		const mine = one.begin()
		other.begin()

		expect(mine()).toBe(true)
	})
})
