/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { DEFAULT_RATIO, FEED_TALLEST, FEED_WIDEST, feedRatioOf, ratioOf, unscaledWidth } from '../../../src/components/GalleryRatio.js'

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

/**
 * The photo feed holds every picture between 4:5 and 1.91:1, as Instagram's
 * feed does: a skyscraper would be the whole screen and a panorama a strip.
 */
describe('feedRatioOf', () => {
	it.each([
		['a square', 1000, 1000, 1],
		['a 4:5 portrait', 1080, 1350, 4 / 5],
		['a 3:2 landscape', 1500, 1000, 1.5],
		['a 1.91:1 landscape', 1910, 1000, 1.91],
	])('keeps %s its own shape', (_name, width, height, expected) => {
		expect(feedRatioOf(sized(width, height))).toBeCloseTo(expected, 6)
	})

	it('gives a 9:16 picture the tallest shape the feed has, 4:5', () => {
		expect(feedRatioOf(sized(1080, 1920))).toBe(FEED_TALLEST)
	})

	it('gives a 3:1 panorama the widest shape the feed has, 1.91:1', () => {
		expect(feedRatioOf(sized(3000, 1000))).toBe(FEED_WIDEST)
	})

	it('gives a picture that never said its size the default, which is in range', () => {
		expect(feedRatioOf({})).toBeCloseTo(DEFAULT_RATIO, 6)
	})
})

/** A small picture is drawn at its own size rather than stretched into a blur. */
describe('unscaledWidth', () => {
	const original = (width, height) => ({ meta: { original: { width, height }, small: { width: Math.min(width, 800), height: Math.min(height, 800) } } })

	it('is the picture\'s own width when the frame has its shape', () => {
		expect(unscaledWidth(original(320, 320), 1)).toBe(320)
	})

	it('is held to the picture\'s height where a tall picture is cut to a wider frame', () => {
		// 300x900 in a 4:5 frame: the width is cropped to fit the height,
		// so the frame can be as wide as 4/5 of 900 before the width runs out
		expect(unscaledWidth(original(300, 900), 4 / 5)).toBe(300)
	})

	it('is held to the picture\'s height where a panorama is cut to a narrower frame', () => {
		// 900x300 in a 1.91 frame: the height fills, the sides are cropped
		expect(unscaledWidth(original(900, 300), 1.91)).toBe(573)
	})

	// the server's preview of an upright picture is 450 wide; the picture is not small
	it('reads the original\'s size, not the size of the server\'s preview', () => {
		expect(unscaledWidth({ meta: { original: { width: 1080, height: 1920 }, small: { width: 450, height: 800 } } }, 4 / 5)).toBe(1080)
	})

	it('falls back to the preview where the original\'s size is not known', () => {
		expect(unscaledWidth({ meta: { small: { width: 400, height: 400 } } }, 1)).toBe(400)
	})

	it('sets no bound for a picture that never said its size', () => {
		expect(unscaledWidth({}, 1)).toBe(Infinity)
	})
})
