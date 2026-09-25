/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { STEP_SIZES, moveTo, rankWeight, sizeStep } from '../../../src/utils/interestCloud.js'

describe('the interest cloud\'s size steps', () => {
	it('draws the feed\'s own weight curve', () => {
		expect(rankWeight(0)).toBe(1)
		expect(rankWeight(4)).toBeCloseTo(1 / 1.6)
		expect(rankWeight(20)).toBeCloseTo(0.25)
	})

	it('runs from 2 rem at the top to 0.85 rem in the tail, in six steps', () => {
		expect(STEP_SIZES).toHaveLength(6)
		expect(STEP_SIZES[0]).toBe(2)
		expect(STEP_SIZES[5]).toBe(0.85)
		expect([...STEP_SIZES].sort((a, b) => b - a)).toEqual(STEP_SIZES)
	})

	/**
	 * Tiers rather than thirty sizes: the top changes quickly, where the
	 * difference matters, and the tail settles.
	 */
	it('puts ranks into the steps the curve cuts them into', () => {
		const steps = Array.from({ length: 30 }, (_, rank) => sizeStep(rank))

		expect(steps.slice(0, 2)).toEqual([0, 0])
		expect(steps.slice(2, 4)).toEqual([1, 1])
		expect(steps.slice(4, 7)).toEqual([2, 2, 2])
		expect(steps.slice(7, 11)).toEqual([3, 3, 3, 3])
		expect(steps.slice(11, 19)).toEqual([4, 4, 4, 4, 4, 4, 4, 4])
		expect(steps.slice(19)).toEqual(Array(11).fill(5))
	})

	it('never grows a tag that went down a rank', () => {
		for (let rank = 0; rank < 100; rank++) {
			expect(sizeStep(rank + 1)).toBeGreaterThanOrEqual(sizeStep(rank))
			// and changes by one notch at most
			expect(sizeStep(rank + 1) - sizeStep(rank)).toBeLessThanOrEqual(1)
		}
	})
})

describe('moving a tag in an order', () => {
	it('takes it out and puts it back at the index', () => {
		expect(moveTo(['a', 'b', 'c', 'd'], 'a', 2)).toEqual(['b', 'c', 'a', 'd'])
		expect(moveTo(['a', 'b', 'c', 'd'], 'd', 0)).toEqual(['d', 'a', 'b', 'c'])
	})

	it('keeps the index inside the list', () => {
		expect(moveTo(['a', 'b', 'c'], 'a', 99)).toEqual(['b', 'c', 'a'])
		expect(moveTo(['a', 'b', 'c'], 'c', -3)).toEqual(['c', 'a', 'b'])
	})
})
