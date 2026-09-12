/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import ProfileMediaGrid from '../../../src/components/ProfileMediaGrid.vue'

/**
 * The methods are pure, so they are exercised directly rather than through a
 * mounted component: what is worth pinning here is the focal-point arithmetic
 * and which posts become tiles, neither of which needs a DOM.
 *
 * @param {object} overrides anything to change about the component's state
 * @return {object} a `this` for calling the component's own methods against
 */
function grid(overrides = {}) {
	return {
		broken: [],
		account: 'alice',
		...ProfileMediaGrid.methods,
		...overrides,
	}
}

describe('ProfileMediaGrid', () => {
	describe('focalPosition', () => {
		it('centres a picture that never said where to look', () => {
			expect(grid().focalPosition({})).toBe('50% 50%')
			expect(grid().focalPosition({ meta: {} })).toBe('50% 50%')
			expect(grid().focalPosition(undefined)).toBe('50% 50%')
		})

		it('centres rather than trusting a malformed focus', () => {
			for (const focus of [{ x: 'left', y: 0 }, { x: 0 }, { y: 0 }, null]) {
				expect(grid().focalPosition({ meta: { focus } })).toBe('50% 50%')
			}
		})

		it('maps the centre to the centre', () => {
			expect(grid().focalPosition({ meta: { focus: { x: 0, y: 0 } } })).toBe('50.00% 50.00%')
		})

		/**
		 * The inversion is the part worth pinning: the focal point's y points
		 * up from the centre, CSS measures down from the top. Getting it the
		 * wrong way round crops the top of every portrait instead of the
		 * bottom, which looks plausible until you compare it with the post.
		 */
		it('inverts the vertical axis, because CSS measures down and focus points up', () => {
			expect(grid().focalPosition({ meta: { focus: { x: 0, y: 1 } } })).toBe('50.00% 0.00%')
			expect(grid().focalPosition({ meta: { focus: { x: 0, y: -1 } } })).toBe('50.00% 100.00%')
		})

		it('maps the horizontal axis straight across', () => {
			expect(grid().focalPosition({ meta: { focus: { x: -1, y: 0 } } })).toBe('0.00% 50.00%')
			expect(grid().focalPosition({ meta: { focus: { x: 1, y: 0 } } })).toBe('100.00% 50.00%')
		})

		it('handles a point that is not on an edge', () => {
			expect(grid().focalPosition({ meta: { focus: { x: 0.5, y: -0.5 } } })).toBe('75.00% 75.00%')
		})
	})

	describe('toTile', () => {
		const post = (attachments, extra = {}) => ({
			id: '42',
			media_attachments: attachments,
			...extra,
		})

		it('takes the first picture of an album and says how many there are', () => {
			const tile = grid().toTile(post([
				{ preview_url: 'a.jpg', type: 'image' },
				{ preview_url: 'b.jpg', type: 'image' },
			]))

			expect(tile.preview).toBe('a.jpg')
			expect(tile.count).toBe(2)
		})

		it('falls back to the full picture when there is no preview', () => {
			expect(grid().toTile(post([{ url: 'full.jpg', type: 'image' }])).preview).toBe('full.jpg')
		})

		it('draws nothing rather than a broken image once one has failed', () => {
			const tile = grid({ broken: ['42'] }).toTile(post([{ preview_url: 'gone.jpg' }]))

			expect(tile.preview).toBeNull()
		})

		it('marks a video so the tile can say it is one', () => {
			expect(grid().toTile(post([{ type: 'video' }])).isVideo).toBe(true)
			expect(grid().toTile(post([{ type: 'gifv' }])).isVideo).toBe(true)
			expect(grid().toTile(post([{ type: 'image' }])).isVideo).toBe(false)
		})

		it('carries the sensitive flag, so the tile is veiled like the post', () => {
			expect(grid().toTile(post([{ type: 'image' }], { sensitive: true })).sensitive).toBe(true)
		})

		it('links the tile at the post, not at the picture', () => {
			const tile = grid().toTile(post([{ type: 'image' }]))

			expect(tile.route).toEqual({
				name: 'single-post',
				params: { account: 'alice', id: '42' },
			})
		})

		it('prefers the alt text as the label', () => {
			expect(grid().toTile(post([{ description: 'a cat asleep' }])).label).toBe('a cat asleep')
		})
	})

	describe('tiles', () => {
		it('skips the posts that have no pictures at all', () => {
			const context = {
				posts: [
					{ id: '1', media_attachments: [{ type: 'image' }] },
					{ id: '2', media_attachments: [] },
					{ id: '3' },
					null,
				],
				broken: [],
				account: 'alice',
				...ProfileMediaGrid.methods,
			}

			expect(ProfileMediaGrid.computed.tiles.call(context)).toHaveLength(1)
		})
	})
})
