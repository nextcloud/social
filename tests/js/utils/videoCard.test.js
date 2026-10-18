/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { chipsOf, compactCount, filterByChip, mayPreview, RECENT_FOR, videoAttachmentOf, videoCardOf } from '../../../src/utils/videoCard.js'

const NOW = Date.parse('2026-10-16T12:00:00Z')

/**
 * @param {string} id the post's id
 * @param {object} card what its video card says
 * @param {object} [extra] the rest of the status
 * @return {object} a video post
 */
function video(id, card = {}, extra = {}) {
	return {
		id,
		created_at: '2026-10-15T12:00:00.000Z',
		video_card: { title: id, duration: 60, thumbnail: null, views: null, live: false, category: null, published: '2026-10-15T12:00:00.000Z', ...card },
		...extra,
	}
}

/**
 * @param {object} matches what the window answers to each media query
 * @param {object} [navigator] the navigator
 * @return {object} a window as `mayPreview` reads one
 */
function windowWith(matches, navigator = {}) {
	return { matchMedia: (query) => ({ matches: matches[query] === true }), navigator }
}

const desk = { '(hover: hover) and (pointer: fine)': true }

describe('the video card helpers', () => {
	it('reads the card of a boost off the boosted post', () => {
		const post = video('1')
		expect(videoCardOf({ id: 'b', reblog: post, video_card: null })).toBe(post.video_card)
		expect(videoCardOf({ id: '2', video_card: null })).toBeNull()
	})

	it('previews the first video of a post', () => {
		const media = { type: 'video', url: 'v.mp4' }
		expect(videoAttachmentOf({ media_attachments: [{ type: 'image' }, media] })).toBe(media)
		expect(videoAttachmentOf({ media_attachments: [] })).toBeNull()
	})

	it('writes a count the short way', () => {
		expect(compactCount(950)).toBe('950')
		expect(compactCount(1234)).toBe('1.2K')
		expect(compactCount(3400000)).toBe('3.4M')
	})

	describe('whether a card may move under the pointer', () => {
		it('may with a mouse', () => {
			expect(mayPreview(windowWith(desk))).toBe(true)
		})

		it('may not on a touch screen', () => {
			expect(mayPreview(windowWith({}))).toBe(false)
		})

		it('may not for somebody who asked for less motion', () => {
			expect(mayPreview(windowWith({ ...desk, '(prefers-reduced-motion: reduce)': true }))).toBe(false)
		})

		it('may not on a connection the reader asked to spare', () => {
			expect(mayPreview(windowWith(desk, { connection: { saveData: true } }))).toBe(false)
			expect(mayPreview(windowWith({ ...desk, '(prefers-reduced-data: reduce)': true }))).toBe(false)
		})
	})

	describe('the chips', () => {
		it('offers All and Recently uploaded around the categories, most frequent first', () => {
			const chips = chipsOf([
				video('1', { category: 'Music' }),
				video('2', { category: 'Travel' }),
				video('3', { category: 'Travel' }),
				{ id: '4', video_card: null },
			])

			expect(chips.map((chip) => chip.value)).toEqual(['all', 'category:Travel', 'category:Music', 'recent'])
			expect(chips[1].label).toBe('Travel')
		})

		it('offers Live only when something is live', () => {
			expect(chipsOf([video('1')]).map((chip) => chip.value)).not.toContain('live')
			expect(chipsOf([video('1'), video('2', { live: true })]).map((chip) => chip.value)).toEqual(['all', 'live', 'recent'])
		})
	})

	describe('what a chip keeps', () => {
		const entries = [
			video('1', { category: 'Music' }),
			video('2', { live: true }, { created_at: '2026-10-16T11:00:00.000Z' }),
			video('3', { category: 'Music' }, { created_at: '2026-01-01T00:00:00.000Z' }),
			{ id: '4', video_card: null },
		]
		const ids = (list) => list.map((entry) => entry.id)

		it('keeps every video under All, and nothing that is not one', () => {
			expect(ids(filterByChip(entries, 'all', NOW))).toEqual(['1', '2', '3'])
		})

		it('keeps what is live under Live', () => {
			expect(ids(filterByChip(entries, 'live', NOW))).toEqual(['2'])
		})

		it('keeps one category', () => {
			expect(ids(filterByChip(entries, 'category:Music', NOW))).toEqual(['1', '3'])
		})

		it('keeps the week\'s uploads under Recently uploaded, newest first', () => {
			expect(ids(filterByChip(entries, 'recent', NOW))).toEqual(['2', '1'])
			expect(filterByChip([video('5', {}, { created_at: new Date(NOW - RECENT_FOR - 1000).toISOString() })], 'recent', NOW)).toEqual([])
		})

		it('shows a video boosted and posted once', () => {
			const post = video('1')
			expect(ids(filterByChip([{ id: 'boost', reblog: post, video_card: null }, post], 'all', NOW))).toEqual(['boost'])
		})
	})
})
