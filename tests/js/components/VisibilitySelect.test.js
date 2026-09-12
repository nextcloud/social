/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import VisibilitySelect from '../../../src/components/Visibility/VisibilitySelect.vue'
import visibilitiesInfo from '../../../src/components/Visibility/VisibilitiesInfos.js'

const ICON_CLASSES = {
	public: 'earth-icon',
	unlisted: 'lock-open-icon',
	followers: 'account-multiple-icon',
	direct: 'at-icon',
}

/**
 * NcActions renders its menu into a popover appended to <body>, so the
 * component is attached to the document and the entries are read from there.
 *
 * @param {string} visibility - Currently selected visibility id
 * @param {Function} [errorHandler] - Vue's app-level error handler, to catch what a handler throws
 */
function mountSelect(visibility, errorHandler) {
	return mount(VisibilitySelect, {
		props: { visibility },
		attachTo: document.body,
		global: errorHandler ? { config: { errorHandler } } : {},
	})
}

const toggle = (wrapper) => wrapper.find('button.action-item__menutoggle')

const menuEntries = () => Array.from(document.body.querySelectorAll('li.action'))

// floating-vue shows the popover from a timer, so wait until the entries exist
async function openMenu(wrapper) {
	await toggle(wrapper).trigger('click')
	for (let attempt = 0; attempt < 50 && menuEntries().length === 0; attempt++) {
		await new Promise((resolve) => setTimeout(resolve, 10))
	}
	await flushPromises()
}

describe('VisibilitySelect', () => {
	let wrapper

	beforeEach(() => {
		localStorage.clear()
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		document.body.innerHTML = ''
	})

	it('shows the selected visibility on the closed trigger', () => {
		wrapper = mountSelect('followers')
		// `menu-name` is what @nextcloud/vue 9 calls the trigger's label (v8's
		// `menu-title` is not a prop and was silently dropped), and NcActions
		// deliberately omits aria-label when the name is visible
		const selected = visibilitiesInfo.find(({ id }) => id === 'followers')
		expect(toggle(wrapper).text()).toContain(selected.text)
		expect(toggle(wrapper).attributes('aria-label')).toBeUndefined()
		expect(toggle(wrapper).find('.account-multiple-icon').exists()).toBe(true)
		expect(toggle(wrapper).attributes('aria-expanded')).toBe('false')
		expect(menuEntries()).toHaveLength(0)
	})

	it('offers every visibility with its description and icon once opened', async () => {
		wrapper = mountSelect('public')
		await openMenu(wrapper)

		const entries = menuEntries()
		expect(entries.map((entry) => entry.textContent.trim()))
			.toEqual(visibilitiesInfo.map(({ description }) => description))
		visibilitiesInfo.forEach(({ id }, index) => {
			expect(entries[index].querySelector('.material-design-icon').classList.contains(ICON_CLASSES[id])).toBe(true)
		})
	})

	it('highlights only the currently selected visibility', async () => {
		wrapper = mountSelect('direct')
		await openMenu(wrapper)

		const selected = menuEntries().filter((entry) => entry.classList.contains('selected-visibility'))
		expect(selected).toHaveLength(1)
		expect(selected[0].textContent).toContain('Visible to mentioned users only')
	})

	it('emits the chosen visibility and remembers it for the next post', async () => {
		wrapper = mountSelect('public')
		await openMenu(wrapper)

		const followers = menuEntries().find((entry) => entry.textContent.includes('Visible to followers only'))
		followers.querySelector('button').click()
		await flushPromises()

		expect(wrapper.emitted('update:visibility')).toEqual([['followers']])
		expect(localStorage.getItem('social.lastPostType')).toBe('followers')
	})

	it('still makes the choice where the preference cannot be written', async () => {
		// localStorage throws outright in a private window and where site data
		// is blocked; this write was bare, although the read of the very same
		// key is guarded
		const setItem = vi.spyOn(localStorage, 'setItem').mockImplementation(() => {
			throw new Error('denied')
		})
		const errorHandler = vi.fn()
		wrapper = mountSelect('public', errorHandler)
		await openMenu(wrapper)

		const followers = menuEntries().find((entry) => entry.textContent.includes('Visible to followers only'))
		followers.querySelector('button').click()
		await flushPromises()

		expect(wrapper.emitted('update:visibility')).toEqual([['followers']])
		expect(errorHandler).not.toHaveBeenCalled()
		setItem.mockRestore()
	})

	it('names a visibility it does not know rather than failing to render', () => {
		// the template reads `.text` off the entry this looks up, so an id
		// from another version took the whole composer down with it
		wrapper = mountSelect('local-only')

		expect(toggle(wrapper).exists()).toBe(true)
		expect(toggle(wrapper).find('.account-multiple-icon').exists()).toBe(true)
	})

	it('does not write a preference before a choice is made', () => {
		wrapper = mountSelect('unlisted')
		expect(localStorage.getItem('social.lastPostType')).toBeNull()
	})

	it('follows the visibility prop when the parent changes it', async () => {
		wrapper = mountSelect('public')
		await wrapper.setProps({ visibility: 'direct' })
		expect(toggle(wrapper).find('.at-icon').exists()).toBe(true)
		expect(toggle(wrapper).find('.earth-icon').exists()).toBe(false)
	})
})
