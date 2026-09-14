/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Settings from '../../../src/views/Settings.vue'
import MigrationSettings from '../../../src/components/MigrationSettings.vue'
import ScheduledPosts from '../../../src/components/ScheduledPosts.vue'
import ShortcutList from '../../../src/components/ShortcutList.vue'
import ShortcutHelp from '../../../src/components/ShortcutHelp.vue'
import { SHORTCUTS } from '../../../src/services/shortcuts.js'

// the scheduled list asks the server for its entries as soon as it is drawn
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: [] }), delete: vi.fn() },
}))

const NcModalStub = { name: 'NcModal', template: '<div class="modal-stub"><slot /></div>' }

describe('Settings', () => {
	it('shows every shortcut the app listens for', () => {
		const wrapper = mount(Settings)
		const rows = wrapper.findAll('.shortcut-list__row')

		expect(rows).toHaveLength(SHORTCUTS.length)
		expect(rows[0].find('kbd').text()).toBe(SHORTCUTS[0].keys[0])
		expect(rows[0].find('dd').text()).toBe(SHORTCUTS[0].label)
	})

	/** A shortcut with two keys is two keys, not "j/k" in one box. */
	it('gives every key of a shortcut its own cap', () => {
		const wrapper = mount(Settings)
		const twoKeyed = SHORTCUTS.findIndex((shortcut) => shortcut.keys.length > 1)

		expect(twoKeyed).toBeGreaterThan(-1)
		expect(wrapper.findAll('.shortcut-list__row')[twoKeyed].findAll('kbd'))
			.toHaveLength(SHORTCUTS[twoKeyed].keys.length)
	})

	it('says that the keys stop while you are typing', () => {
		expect(mount(Settings).find('.shortcut-list__hint').text())
			.toBe('Shortcuts are off while you are writing.')
	})

	/**
	 * A shortcut is a promise about a keystroke, and the `?` dialog makes the
	 * same one. They draw the same component so the two cannot drift apart.
	 */
	it('draws the same list the ? dialog does', () => {
		const page = mount(Settings)
		const dialog = mount(ShortcutHelp, {
			props: { open: true },
			global: { stubs: { NcModal: NcModalStub } },
		})

		expect(page.findComponent(ShortcutList).exists()).toBe(true)
		expect(dialog.findComponent(ShortcutList).exists()).toBe(true)
		const rows = page.findAll('.shortcut-list__row').length
		expect(dialog.findAll('.shortcut-list__row')).toHaveLength(rows)
	})

	/** The frame is a list of sections, and Migration was the second. */
	it('is a page of sections rather than a page about shortcuts', () => {
		const wrapper = mount(Settings)

		expect(wrapper.find('.settings__heading').text()).toBe('Settings')
		expect(wrapper.findAll('.settings__section-heading').map((h) => h.text()))
			.toEqual(['Keyboard shortcuts', 'Scheduled posts', 'Migration'])
	})

	/**
	 * A page of its own for a list that is usually empty, and whose entries
	 * offer one action, would be a navigation entry earning its place from
	 * nothing. The composer's clock is where a post is scheduled; this is
	 * where one is taken back.
	 */
	it('holds the posts waiting to go out', () => {
		expect(mount(Settings).findComponent(ScheduledPosts).exists()).toBe(true)
	})

	/**
	 * Migration used to be a page of its own with an entry in the account
	 * menu. That menu is for places to read something; this is a thing you do
	 * to the account, which is what Settings is for.
	 */
	it('holds the migration tools', () => {
		const wrapper = mount(Settings)

		expect(wrapper.findComponent(MigrationSettings).exists()).toBe(true)
		// the section supplies the heading, so the panel no longer repeats it
		expect(wrapper.findComponent(MigrationSettings).find('h2').exists()).toBe(false)
	})
})
