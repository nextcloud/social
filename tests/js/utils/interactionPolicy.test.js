/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { allowedByAuthor, isShareable } from '../../../src/utils/interactionPolicy.js'

describe('what the author said may be done with a post', () => {
	/** Most posts carry no policy at all, and a post with none is open. */
	it('allows everything when the author said nothing', () => {
		for (const interaction of ['reply', 'boost', 'like', 'quote']) {
			expect(allowedByAuthor({}, interaction)).toBe(true)
			expect(allowedByAuthor({ interaction_policy: {} }, interaction)).toBe(true)
		}
	})

	it('refuses only what the author refused', () => {
		const item = { interaction_policy: { boost: false, reply: true } }

		expect(allowedByAuthor(item, 'boost')).toBe(false)
		expect(allowedByAuthor(item, 'reply')).toBe(true)
		expect(allowedByAuthor(item, 'like')).toBe(true)
	})

	/**
	 * Only `false` is a refusal. A server that answers something else about an
	 * interaction has said nothing this app understands, and a post nobody has
	 * forbidden anything about is one anybody may answer.
	 */
	it('treats anything that is not a plain no as a yes', () => {
		expect(allowedByAuthor({ interaction_policy: { reply: 'nobody' } }, 'reply')).toBe(true)
		expect(allowedByAuthor({ interaction_policy: { reply: 0 } }, 'reply')).toBe(true)
		expect(allowedByAuthor({ interaction_policy: null }, 'reply')).toBe(true)
	})

	it('survives being asked about nothing at all', () => {
		expect(allowedByAuthor(undefined, 'reply')).toBe(true)
	})
})

describe('whether a post can be passed on', () => {
	it('allows the audiences that were already everybody', () => {
		expect(isShareable({ visibility: 'public' })).toBe(true)
		expect(isShareable({ visibility: 'unlisted' })).toBe(true)
	})

	/** Passing one of these on would put it in front of people it was kept from. */
	it('refuses the ones that were addressed to somebody', () => {
		expect(isShareable({ visibility: 'private' })).toBe(false)
		expect(isShareable({ visibility: 'direct' })).toBe(false)
	})

	it('refuses a post whose audience it was not told', () => {
		expect(isShareable({})).toBe(false)
		expect(isShareable(undefined)).toBe(false)
	})
})
