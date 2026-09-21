/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ShortcutList from '../../../src/components/ShortcutList.vue'
import { SHORTCUTS } from '../../../src/services/shortcuts.js'

/**
 * The list the `?` dialog and the Settings page both show.
 *
 * A shortcut is a promise about a keystroke, so what matters here is that the
 * list is the one the handler reads and not a second copy of it: a hand-written
 * table drifts from the keys that actually work, and the reader is the last to
 * find out.
 */
describe('the shortcut list', () => {
	it('shows every shortcut the handler listens for', () => {
		const wrapper = mount(ShortcutList)

		expect(wrapper.findAll('.shortcut-list__row')).toHaveLength(SHORTCUTS.length)
	})

	it('shows each key next to what it does', () => {
		const rows = mount(ShortcutList).findAll('.shortcut-list__row')

		SHORTCUTS.forEach((shortcut, index) => {
			expect(rows[index].findAll('kbd').map((key) => key.text())).toEqual(shortcut.keys)
			expect(rows[index].find('dd').text()).toBe(shortcut.label)
		})
	})

	/** Both keys are shown for a shortcut that has two, not only the first. */
	it('shows both keys where a shortcut has two', () => {
		const like = SHORTCUTS.findIndex((shortcut) => shortcut.event === 'shortcut:like')
		const row = mount(ShortcutList).findAll('.shortcut-list__row')[like]

		expect(row.findAll('kbd').map((key) => key.text())).toEqual(['l', 'f'])
	})

	/** The one thing about them that surprises people. */
	it('says that they are off while you are writing', () => {
		expect(mount(ShortcutList).find('.shortcut-list__hint').text())
			.toContain('off while you are writing')
	})

	/** A description list: the keys are the terms, what they do the details. */
	it('pairs the keys and their meanings as a description list', () => {
		const wrapper = mount(ShortcutList)

		expect(wrapper.find('dl').exists()).toBe(true)
		expect(wrapper.findAll('dt')).toHaveLength(SHORTCUTS.length)
		expect(wrapper.findAll('dd')).toHaveLength(SHORTCUTS.length)
	})
})
