/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import {
	NUDGE,
	clampFocus,
	focusFromPoint,
	focusParam,
	isFocalPoint,
	nudgeFocus,
	positionOfFocus,
} from '../../../src/utils/focalPoint.js'

describe('focusFromPoint', () => {
	/**
	 * The one conversion everything else rests on: the browser measures from
	 * the top left with y going down, Mastodon reads from the centre with y
	 * going up. Getting the sign wrong crops the opposite half of the picture.
	 */
	it('reads the middle of the picture as the origin', () => {
		expect(focusFromPoint(200, 100, 400, 200)).toEqual({ x: 0, y: 0 })
	})

	it('puts the corners where Mastodon puts them', () => {
		expect(focusFromPoint(0, 0, 400, 200)).toEqual({ x: -1, y: 1 })
		expect(focusFromPoint(400, 0, 400, 200)).toEqual({ x: 1, y: 1 })
		expect(focusFromPoint(0, 200, 400, 200)).toEqual({ x: -1, y: -1 })
		expect(focusFromPoint(400, 200, 400, 200)).toEqual({ x: 1, y: -1 })
	})

	it('reads a face in the upper third as a point above the middle', () => {
		expect(focusFromPoint(300, 50, 400, 200)).toEqual({ x: 0.5, y: 0.5 })
	})

	it('holds a pointer dragged past the edge inside the picture', () => {
		expect(focusFromPoint(-80, 260, 400, 200)).toEqual({ x: -1, y: -1 })
		expect(focusFromPoint(900, -40, 400, 200)).toEqual({ x: 1, y: 1 })
	})

	it('reads a picture with no size as centred rather than as NaN', () => {
		// an <img> that has not laid out yet measures 0 by 0, and the point
		// would otherwise be divided by it
		expect(focusFromPoint(10, 10, 0, 0)).toEqual({ x: 0, y: 0 })
		expect(focusFromPoint(10, 10, 400, 0)).toEqual({ x: 0, y: 0 })
	})

	it('rounds to two decimals, which is finer than any crop shows', () => {
		expect(focusFromPoint(123, 45, 400, 200)).toEqual({ x: -0.38, y: 0.55 })
	})
})

describe('positionOfFocus', () => {
	it('turns a point back into the percentages CSS measures from', () => {
		expect(positionOfFocus({ x: 0, y: 0 }))
			.toEqual({ left: '50.00%', top: '50.00%', objectPosition: '50.00% 50.00%' })
		expect(positionOfFocus({ x: 1, y: 1 }).top).toBe('0.00%')
		expect(positionOfFocus({ x: -1, y: -1 }))
			.toEqual({ left: '0.00%', top: '100.00%', objectPosition: '0.00% 100.00%' })
	})

	it('is the inverse of reading a pointer', () => {
		const focus = focusFromPoint(120, 40, 400, 200)
		const { left, top } = positionOfFocus(focus)

		expect(Number.parseFloat(left) / 100 * 400).toBeCloseTo(120, 1)
		expect(Number.parseFloat(top) / 100 * 200).toBeCloseTo(40, 1)
	})

	it('centres a picture that has no point, which is what a crop does anyway', () => {
		expect(positionOfFocus(null).objectPosition).toBe('50.00% 50.00%')
		expect(positionOfFocus(undefined).objectPosition).toBe('50.00% 50.00%')
		expect(positionOfFocus({ x: 'left', y: 2 }).objectPosition).toBe('50.00% 50.00%')
	})
})

describe('nudgeFocus', () => {
	it('moves the point by one step, with up meaning up', () => {
		expect(nudgeFocus({ x: 0, y: 0 }, 0, NUDGE)).toEqual({ x: 0, y: 0.05 })
		expect(nudgeFocus({ x: 0.5, y: 0 }, -NUDGE, 0)).toEqual({ x: 0.45, y: 0 })
	})

	it('stops at the edge instead of walking off the picture', () => {
		expect(nudgeFocus({ x: 1, y: -1 }, NUDGE, -NUDGE)).toEqual({ x: 1, y: -1 })
	})

	it('starts from the middle when there is no point yet', () => {
		expect(nudgeFocus(null, NUDGE, 0)).toEqual({ x: 0.05, y: 0 })
	})

	it('does not accumulate binary dust over a run of presses', () => {
		let focus = { x: 0, y: 0 }
		for (let press = 0; press < 6; press++) {
			focus = nudgeFocus(focus, NUDGE, 0)
		}

		expect(focus).toEqual({ x: 0.3, y: 0 })
	})
})

describe('focusParam', () => {
	it('writes the pair the media endpoints take', () => {
		expect(focusParam({ x: 0.25, y: -0.5 })).toBe('0.25,-0.5')
		expect(focusParam({ x: 0, y: 0 })).toBe('0,0')
	})

	it('sends nothing outside the range the server accepts', () => {
		expect(focusParam({ x: 4, y: -9 })).toBe('1,-1')
		expect(focusParam({ x: Number.NaN, y: 0.5 })).toBe('0,0.5')
	})
})

describe('clampFocus and isFocalPoint', () => {
	it('recognises a pair of numbers and nothing else', () => {
		expect(isFocalPoint({ x: 0, y: 0 })).toBe(true)
		expect(isFocalPoint({ x: -1, y: 0.3 })).toBe(true)
		expect(isFocalPoint(null)).toBe(false)
		expect(isFocalPoint(undefined)).toBe(false)
		expect(isFocalPoint({ x: 0 })).toBe(false)
		expect(isFocalPoint({ x: '0', y: '0' })).toBe(false)
		expect(isFocalPoint({ x: Number.NaN, y: 0 })).toBe(false)
		expect(isFocalPoint('0,0')).toBe(false)
	})

	it('makes a point out of whatever it is given', () => {
		expect(clampFocus({ x: 2, y: -2 })).toEqual({ x: 1, y: -1 })
		expect(clampFocus({})).toEqual({ x: 0, y: 0 })
	})
})
