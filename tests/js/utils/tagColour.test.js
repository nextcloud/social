/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { tagHue, tagStyle, seriesStyle } from '../../../src/utils/tagColour.js'

describe('tagHue', () => {
	it('gives the same tag the same hue every time', () => {
		expect(tagHue('design')).toBe(tagHue('design'))
	})

	// the server folds these together, so the reader must not see two colours
	it('ignores the hash, the case and the space around it', () => {
		const hue = tagHue('design')

		expect(tagHue('#design')).toBe(hue)
		expect(tagHue('Design')).toBe(hue)
		expect(tagHue('  #DESIGN  ')).toBe(hue)
	})

	it('stays inside a hue circle', () => {
		for (const tag of ['a', 'nextcloud', 'fediverse', 'ü', '2026', 'a-very-long-hashtag-name']) {
			const hue = tagHue(tag)
			expect(Number.isInteger(hue)).toBe(true)
			expect(hue).toBeGreaterThanOrEqual(0)
			expect(hue).toBeLessThan(360)
		}
	})

	it('spreads a realistic set of tags over the circle', () => {
		const tags = ['design', 'nextcloud', 'fediverse', 'photography', 'music', 'cooking', 'rust', 'berlin']
		const hues = new Set(tags.map(tagHue))

		// not a promise of perfect distribution, only that it is not a constant
		expect(hues.size).toBeGreaterThan(tags.length / 2)
	})

	it('answers for an empty tag instead of throwing', () => {
		expect(tagHue('')).toBe(0)
		expect(tagHue(undefined)).toBe(0)
		expect(tagHue(null)).toBe(0)
	})
})

describe('tagStyle', () => {
	it('carries the hue and a colour for each theme', () => {
		const style = tagStyle('design')

		expect(style['--tag-hue']).toBe(String(tagHue('design')))
		expect(style['--tag-colour']).toMatch(/^hsl\(\d+, \d+%, \d+%\)$/)
		expect(style['--tag-colour-dark']).toMatch(/^hsl\(\d+, \d+%, \d+%\)$/)
	})
})

describe('the colours a chart band is drawn with', () => {
	/**
	 * A fixed palette is a set of colours chosen against one background, and
	 * this app has two. Six hardcoded hexes looked deliberate on the light
	 * theme and muddy on the dark one, where everything else had moved.
	 */
	it('answers a light and a dark value, so the stylesheet can choose', () => {
		const style = seriesStyle(0)

		expect(style['--series-colour']).toMatch(/^hsl\(/)
		expect(style['--series-colour-dark']).toMatch(/^hsl\(/)
		expect(style['--series-colour']).not.toBe(style['--series-colour-dark'])
	})

	it('gives the same band the same colour every time', () => {
		expect(seriesStyle(3)).toEqual(seriesStyle(3))
	})

	/** Six bands of one hue is a gradient; a legend needs them told apart. */
	it('spreads consecutive bands around the wheel', () => {
		const hue = (i) => Number(seriesStyle(i)['--series-colour'].match(/hsl\((\d+)/)[1])

		expect(hue(0)).not.toBe(hue(1))
		expect(hue(1)).not.toBe(hue(2))
		// six steps of 60 degrees walk the wheel exactly once
		expect(hue(6)).toBe(hue(0))
	})

	it('survives a band index that is not a number', () => {
		expect(seriesStyle(undefined)['--series-colour']).toMatch(/^hsl\(/)
	})
})
