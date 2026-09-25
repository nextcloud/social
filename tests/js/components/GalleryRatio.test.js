/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { DEFAULT_RATIO, ratioOf } from '../../../src/components/GalleryRatio.js'

const sized = (width, height) => ({ meta: { original: { width, height } } })

describe('ratioOf', () => {
	/**
	 * The shape a frame reserves is the shape the media is. This used to be
	 * clamped between 3:4 and 16:9, which meant a phone short was given a 3:4
	 * box and cropped top and bottom to fill it.
	 */
	it.each([
		['a 9:16 short', 1080, 1920, 1080 / 1920],
		['a 16:9 video', 1920, 1080, 1920 / 1080],
		['a square', 1000, 1000, 1],
		['a 3:1 panorama', 3000, 1000, 3],
		['a skyscraper', 400, 2000, 0.2],
	])('gives %s its own shape', (_name, width, height, expected) => {
		expect(ratioOf(sized(width, height))).toBeCloseTo(expected, 6)
	})

	it('is taller than 3:4 for a short, which the old floor made impossible', () => {
		expect(ratioOf(sized(1080, 1920))).toBeLessThan(3 / 4)
	})

	/** `AttachmentMeta::jsonSerialize()` drops a zero width or height. */
	it.each([
		['no attachment', null],
		['no meta', {}],
		['no dimensions', { meta: { original: {} } }],
		['a zero width', sized(0, 900)],
		['a zero height', sized(900, 0)],
		['dimensions that are not numbers', sized('wide', 'tall')],
	])('falls back for %s', (_name, attachment) => {
		expect(ratioOf(attachment)).toBe(DEFAULT_RATIO)
	})

	it('reads the small copy when there is no original', () => {
		expect(ratioOf({ meta: { small: { width: 640, height: 480 } } })).toBeCloseTo(4 / 3, 6)
	})

	it('takes the caller\'s fallback over its own', () => {
		expect(ratioOf(null, 1)).toBe(1)
	})
})
