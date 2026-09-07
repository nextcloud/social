/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import visibilitiesInfo from '../../../src/components/Visibility/VisibilitiesInfos.js'

describe('VisibilitiesInfos', () => {
	it('lists the four Mastodon visibilities in menu order', () => {
		expect(visibilitiesInfo.map(({ id }) => id)).toEqual(['public', 'unlisted', 'followers', 'direct'])
	})

	it('gives every visibility a non-empty label and description', () => {
		for (const info of visibilitiesInfo) {
			expect(info.text).toEqual(expect.any(String))
			expect(info.text).not.toBe('')
			expect(info.description).toEqual(expect.any(String))
			expect(info.description).not.toBe('')
		}
	})

	it('describes who gets to see a post of each visibility', () => {
		const byId = Object.fromEntries(visibilitiesInfo.map((info) => [info.id, info]))
		expect(byId.public).toMatchObject({ text: 'Public', description: 'Visible for all' })
		expect(byId.unlisted).toMatchObject({ text: 'Unlisted' })
		expect(byId.unlisted.description).toMatch(/opted-out of discovery/)
		expect(byId.followers).toMatchObject({ text: 'Followers', description: 'Visible to followers only' })
		expect(byId.direct).toMatchObject({ text: 'Direct message', description: 'Visible to mentioned users only' })
	})

	it('uses unique labels so the menu entries can be told apart', () => {
		const texts = visibilitiesInfo.map(({ text }) => text)
		expect(new Set(texts).size).toBe(texts.length)
	})
})
