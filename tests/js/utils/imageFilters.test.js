/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import {
	applyFilterToFile,
	availableFilters,
	filterCss,
	isFilterActive,
} from '../../../src/utils/imageFilters.js'

vi.mock('@nextcloud/l10n', () => ({ t: (app, text) => text }))

describe('imageFilters', () => {
	describe('filterCss', () => {
		it('gives no filter for the original', () => {
			expect(filterCss('none')).toBe('')
			expect(isFilterActive('none')).toBe(false)
		})

		/**
		 * An id this build does not know -- an old draft, a hand-edited store --
		 * has to leave the picture alone rather than throw or produce `filter:
		 * undefined`, which would blank the preview.
		 */
		it('leaves a picture alone for an id it does not know', () => {
			for (const id of ['nonsense', '', null, undefined, 42]) {
				expect(filterCss(id)).toBe('')
				expect(isFilterActive(id)).toBe(false)
			}
		})

		it('gives real CSS for a real filter', () => {
			expect(filterCss('mono')).toBe('grayscale(1)')
			expect(isFilterActive('mono')).toBe(true)
		})
	})

	describe('availableFilters', () => {
		it('offers the original first, so the default is the one that changes nothing', () => {
			expect(availableFilters()[0].id).toBe('none')
		})

		it('names every filter', () => {
			for (const filter of availableFilters()) {
				expect(filter.name).toBeTruthy()
				expect(typeof filter.css).toBe('string')
			}
		})

		it('has no duplicate ids', () => {
			const ids = availableFilters().map((filter) => filter.id)
			expect(new Set(ids).size).toBe(ids.length)
		})
	})

	describe('applyFilterToFile', () => {
		const png = () => new File(['x'], 'cat.png', { type: 'image/png' })

		it('hands back the same file when there is no filter to apply', async () => {
			const file = png()
			expect(await applyFilterToFile(file, 'none')).toBe(file)
		})

		it('hands back the same file for something that is not a picture', async () => {
			const video = new File(['x'], 'clip.mp4', { type: 'video/mp4' })
			expect(await applyFilterToFile(video, 'mono')).toBe(video)
		})

		/**
		 * A canvas takes the first frame, which is not what anybody meant by
		 * "apply a filter" to an animation.
		 */
		it('leaves an animated picture alone rather than flattening it to one frame', async () => {
			const gif = new File(['x'], 'wave.gif', { type: 'image/gif' })
			const webp = new File(['x'], 'wave.webp', { type: 'image/webp' })

			expect(await applyFilterToFile(gif, 'mono')).toBe(gif)
			expect(await applyFilterToFile(webp, 'mono')).toBe(webp)
		})

		/**
		 * A filter is a decoration. Losing somebody's upload because a canvas
		 * would not cooperate is not a trade worth making, so every failure
		 * path returns the original rather than throwing.
		 */
		it('returns the original when the browser cannot decode the picture', async () => {
			const file = png()
			vi.stubGlobal('createImageBitmap', vi.fn().mockRejectedValue(new Error('nope')))

			await expect(applyFilterToFile(file, 'mono')).resolves.toBe(file)

			vi.unstubAllGlobals()
		})

		it('returns the original when the canvas produces no blob', async () => {
			const file = png()
			vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 2, height: 2, close: vi.fn() }))
			vi.spyOn(document, 'createElement').mockReturnValue({
				width: 0,
				height: 0,
				getContext: () => ({ filter: '', drawImage: vi.fn() }),
				toBlob: (callback) => callback(null),
			})

			await expect(applyFilterToFile(file, 'mono')).resolves.toBe(file)

			vi.restoreAllMocks()
			vi.unstubAllGlobals()
		})

		it('returns the original when the context cannot filter', async () => {
			const file = png()
			vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 2, height: 2, close: vi.fn() }))
			vi.spyOn(document, 'createElement').mockReturnValue({
				width: 0,
				height: 0,
				getContext: () => null,
				toBlob: vi.fn(),
			})

			await expect(applyFilterToFile(file, 'mono')).resolves.toBe(file)

			vi.restoreAllMocks()
			vi.unstubAllGlobals()
		})

		it('keeps a PNG a PNG, so transparency does not turn black', async () => {
			vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 2, height: 2, close: vi.fn() }))
			let askedFor = null
			vi.spyOn(document, 'createElement').mockReturnValue({
				width: 0,
				height: 0,
				getContext: () => ({ filter: '', drawImage: vi.fn() }),
				toBlob: (callback, type) => {
					askedFor = type
					callback(new Blob(['y'], { type }))
				},
			})

			const out = await applyFilterToFile(png(), 'mono')

			expect(askedFor).toBe('image/png')
			expect(out.type).toBe('image/png')
			expect(out.name).toBe('cat.png')

			vi.restoreAllMocks()
			vi.unstubAllGlobals()
		})

		/** The extension has to follow the type, or the name lies about the file. */
		it('renames a transcoded picture so its extension matches its type', async () => {
			vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 2, height: 2, close: vi.fn() }))
			vi.spyOn(document, 'createElement').mockReturnValue({
				width: 0,
				height: 0,
				getContext: () => ({ filter: '', drawImage: vi.fn() }),
				toBlob: (callback, type) => callback(new Blob(['y'], { type })),
			})

			const out = await applyFilterToFile(
				new File(['x'], 'holiday.jpeg', { type: 'image/jpeg' }),
				'warm',
			)

			expect(out.type).toBe('image/jpeg')
			expect(out.name).toBe('holiday.jpg')

			vi.restoreAllMocks()
			vi.unstubAllGlobals()
		})

		it('applies the filter the id names, not some other one', async () => {
			vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 2, height: 2, close: vi.fn() }))
			const context = { filter: '', drawImage: vi.fn() }
			vi.spyOn(document, 'createElement').mockReturnValue({
				width: 0,
				height: 0,
				getContext: () => context,
				toBlob: (callback, type) => callback(new Blob(['y'], { type })),
			})

			await applyFilterToFile(png(), 'sepia')

			expect(context.filter).toBe(filterCss('sepia'))

			vi.restoreAllMocks()
			vi.unstubAllGlobals()
		})
	})
})
