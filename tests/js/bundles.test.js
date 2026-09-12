/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * What the built bundles are allowed to contain.
 *
 * These read the committed output in js/, which is what an installation
 * actually serves — the thing worth asserting about, and the reason the
 * directory is in the repository at all.
 */
const JS = resolve(process.cwd(), 'js')

function bundle(name) {
	const path = join(JS, name)

	return existsSync(path) ? readFileSync(path, 'utf8') : null
}

/** The picker's own payload, not merely a reference to the chunk that holds it. */
const CARRIES_EMOJI_DATA = /emoji-mart-vue-fast|"skin_variations"|frequently-used-emojis/

describe('the built bundles', () => {
	it('are present, because the tests below read what is actually served', () => {
		expect(existsSync(JS)).toBe(true)
		expect(readdirSync(JS).some((entry) => entry.endsWith('.js'))).toBe(true)
	})

	it.each(['social-dashboard.js', 'social-profilePage.js', 'social-ostatus.js'])(
		'%s does not carry the emoji picker',
		(name) => {
			const content = bundle(name)
			if (content === null) {
				// a partial build; the presence check above is what guards that
				return
			}

			// none of these can compose a post, and the picker is most of a
			// megabyte — it used to arrive with the post overflow menu
			expect(CARRIES_EMOJI_DATA.test(content)).toBe(false)
		},
	)

	it('keeps the picker in a chunk of its own', () => {
		const picker = bundle('social-emoji-picker.js')
		expect(picker, 'social-emoji-picker.js is missing; run the build').not.toBeNull()
		expect(CARRIES_EMOJI_DATA.test(picker)).toBe(true)
	})

	it('keeps the chunk that carries the post menu small', () => {
		const menus = readdirSync(JS).filter((entry) => entry.includes('NcActionButton') && entry.endsWith('.js'))
		expect(menus.length).toBeGreaterThan(0)

		for (const name of menus) {
			// every post's "..." menu needs this; it was 1.1 MB when the emoji
			// picker shared it
			const size = statSync(join(JS, name)).size
			expect(size, `${name} is ${Math.round(size / 1024)} KB`).toBeLessThan(400 * 1024)
		}
	})
})
