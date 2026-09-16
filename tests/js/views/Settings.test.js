/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils'
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

// The two account sections are `defineAsyncComponent`s, so mounting the page
// starts a dynamic import that outlives the test: vitest tears the
// environment down while `@nextcloud/vue` is still pulling in a stylesheet,
// and the run ends with an EnvironmentTeardownError although every assertion
// passed. They have tests of their own; here they are stubs.
const asyncStubs = {
	AccountSettings: { name: 'AccountSettings', template: '<section class="account-settings-stub" />' },
	// reads the featured tags and the suggestions on mount, same story
	FeaturedTagsSettings: { name: 'FeaturedTagsSettings', template: '<section class="featured-tags-settings-stub" />' },
	ListsSettings: { name: 'ListsSettings', template: '<section class="lists-settings-stub" />' },
	// reads the reader's keyword filters on mount, so the same applies
	FiltersSettings: { name: 'FiltersSettings', template: '<section class="filters-settings-stub" />' },
	// reads the recap setting on mount, for the same reason: its request would
	// answer after this file has finished
	RecapSettings: { name: 'RecapSettings', template: '<section class="recap-settings-stub" />' },
	// reads the portfolio and the collections on mount, same story again
	PortfolioSettings: { name: 'PortfolioSettings', template: '<section class="portfolio-settings-stub" />' },
}

describe('Settings', () => {
	it('shows every shortcut the app listens for', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()
		const rows = wrapper.findAll('.shortcut-list__row')

		expect(rows).toHaveLength(SHORTCUTS.length)
		expect(rows[0].find('kbd').text()).toBe(SHORTCUTS[0].keys[0])
		expect(rows[0].find('dd').text()).toBe(SHORTCUTS[0].label)
	})

	/** A shortcut with two keys is two keys, not "j/k" in one box. */
	it('gives every key of a shortcut its own cap', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()
		const twoKeyed = SHORTCUTS.findIndex((shortcut) => shortcut.keys.length > 1)

		expect(twoKeyed).toBeGreaterThan(-1)
		expect(wrapper.findAll('.shortcut-list__row')[twoKeyed].findAll('kbd'))
			.toHaveLength(SHORTCUTS[twoKeyed].keys.length)
	})

	it('says that the keys stop while you are typing', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.find('.shortcut-list__hint').text())
			.toBe('Shortcuts are off while you are writing.')
	})

	/**
	 * A shortcut is a promise about a keystroke, and the `?` dialog makes the
	 * same one. They draw the same component so the two cannot drift apart.
	 */
	it('draws the same list the ? dialog does', async () => {
		const page = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()
		const dialog = mount(ShortcutHelp, {
			props: { open: true },
			global: { stubs: { NcModal: NcModalStub } },
		})

		expect(page.findComponent(ShortcutList).exists()).toBe(true)
		expect(dialog.findComponent(ShortcutList).exists()).toBe(true)
		const rows = page.findAll('.shortcut-list__row').length
		expect(dialog.findAll('.shortcut-list__row')).toHaveLength(rows)
	})

	/**
	 * The frame is a list of sections, and Migration was the second.
	 *
	 * The shortcuts are reference rather than a setting — nothing there is
	 * changed, it is a list to look something up in — so they sit at the end,
	 * under the things that are done to the account, with only the deletion
	 * below them, which is last because it cannot be undone.
	 */
	it('is a page of sections rather than a page about shortcuts', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.find('.settings__heading').text()).toBe('Settings')
		expect(wrapper.findAll('.settings__section-heading').map((h) => h.text()))
			.toEqual(['Your account', 'Featured hashtags', 'Lists', 'Filtered words', 'Scheduled posts', 'Portfolio', 'Archived posts', 'Waiting to be looked at', 'Looking back', 'Authorized apps', 'Migration', 'Keyboard shortcuts', 'Delete your Social account'])
	})

	/**
	 * Each section is linked to from somewhere, and the scheduled posts wore
	 * the migration tools' id: `#migration` scrolled to the wrong section and
	 * the tools it names had no anchor at all.
	 */
	it('gives each section the id that names it', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		const idOf = (heading) => wrapper.findAll('.settings__section')
			.find((section) => section.find('.settings__section-heading').text() === heading)
			.attributes('id')

		expect(idOf('Scheduled posts')).toBe('scheduled')
		expect(idOf('Migration')).toBe('migration')
		expect(idOf('Keyboard shortcuts')).toBe('shortcuts')
	})

	/**
	 * The hashtag editor is reached from the reader's own profile, which links
	 * at `#featured-tags` — a section with no id is a link that lands at the
	 * top of the page and looks like it did nothing.
	 */
	it('gives the hashtag editor the id its profile link points at', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.find('#featured-tags').exists()).toBe(true)
	})

	/**
	 * Keyword filters apply to every timeline this app reads and were until
	 * now reachable only from a Mastodon client, which is the one combination
	 * a reader cannot get out of on their own.
	 */
	it('holds the keyword filters, at an id another page can link to', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.find('#filters .filters-settings-stub').exists()).toBe(true)
	})

	/**
	 * A page of its own for a list that is usually empty, and whose entries
	 * offer one action, would be a navigation entry earning its place from
	 * nothing. The composer's clock is where a post is scheduled; this is
	 * where one is taken back.
	 */
	it('holds the posts waiting to go out', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.findComponent(ScheduledPosts).exists()).toBe(true)
	})

	/**
	 * Migration used to be a page of its own with an entry in the account
	 * menu. That menu is for places to read something; this is a thing you do
	 * to the account, which is what Settings is for.
	 */
	it('holds the migration tools', async () => {
		const wrapper = mount(Settings, { global: { stubs: asyncStubs } })
		await flushPromises()

		expect(wrapper.findComponent(MigrationSettings).exists()).toBe(true)
		// the section supplies the heading, so the panel no longer repeats it
		expect(wrapper.findComponent(MigrationSettings).find('h2').exists()).toBe(false)
	})
})
