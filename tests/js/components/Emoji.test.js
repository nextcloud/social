/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import Emoji from '../../../src/components/Emoji.vue'

const mountEmoji = (emoji) => mount(Emoji, { props: { emoji } })

describe('Emoji', () => {
	it('renders a twemoji image from the app bundle for a simple emoji', () => {
		const img = mountEmoji('😀').find('img.emoji')
		expect(img.attributes('src')).toBe('/apps/social/img/twemoji/1f600.svg')
		expect(img.attributes('alt')).toBe('😀')
		expect(img.attributes('draggable')).toBe('false')
	})

	it('drops the variation selector from a plain emoji', () => {
		// red heart is U+2764 U+FE0F; twemoji ships it as 2764.svg
		expect(mountEmoji('❤️').find('img').attributes('src')).toBe('/apps/social/img/twemoji/2764.svg')
	})

	it('keeps the full code point sequence for a ZWJ sequence', () => {
		expect(mountEmoji('👨‍👩‍👧').find('img').attributes('src'))
			.toBe('/apps/social/img/twemoji/1f468-200d-1f469-200d-1f467.svg')
	})

	it('keeps the variation selector inside a ZWJ sequence', () => {
		// rainbow flag: U+1F3F3 U+FE0F U+200D U+1F308
		expect(mountEmoji('🏳️‍🌈').find('img').attributes('src'))
			.toBe('/apps/social/img/twemoji/1f3f3-fe0f-200d-1f308.svg')
	})
})
