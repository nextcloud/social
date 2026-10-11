/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { cssUrl } from '../../../src/utils/cssUrl.js'

describe('cssUrl', () => {
	it('quotes an ordinary address', () => {
		expect(cssUrl('https://remote.example/media/header.png')).toBe('url("https://remote.example/media/header.png")')
	})

	it('keeps a quote, a backslash and a line break inside the string', () => {
		expect(cssUrl('https://remote.example/a"b\\c')).toBe('url("https://remote.example/a\\"b\\\\c")')
		expect(cssUrl('https://remote.example/a\nb\r\nc\fd')).toBe('url("https://remote.example/a\\a b\\a c\\c d")')
	})

	it('leaves parentheses and spaces to the quotes', () => {
		expect(cssUrl('https://remote.example/a b) c')).toBe('url("https://remote.example/a b) c")')
	})

	/** What a browser reads back is the address that went in, and nothing after it. */
	it('round-trips through a style declaration', () => {
		const el = document.createElement('div')
		el.style.backgroundImage = cssUrl('https://remote.example/a")b.png')
		el.style.backgroundColor = 'red'

		expect(el.style.backgroundImage).toContain('a\\")b.png')
		expect(el.style.backgroundColor).toBe('red')
	})
})
