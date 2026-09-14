/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { setLanguage } from '@nextcloud/l10n'
import LanguageSelect from '../../../src/components/Composer/LanguageSelect.vue'
import { POST_LANGUAGES } from '../../../src/utils/postLanguage.js'

/**
 * NcActions renders its menu into a popover appended to <body>, so the
 * component is attached to the document and the entries are read from there.
 *
 * @param {string} language the code the post carries
 */
function mountSelect(language) {
	return mount(LanguageSelect, { props: { language }, attachTo: document.body })
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

describe('LanguageSelect', () => {
	let wrapper

	beforeEach(() => {
		localStorage.clear()
		setLanguage('en')
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		document.body.innerHTML = ''
		setLanguage('en')
	})

	/** The toolbar has room for a code; the name is what the button is called. */
	it('wears the current code, and says which language that is', () => {
		wrapper = mountSelect('de')

		expect(toggle(wrapper).find('.language-select__current').text()).toBe('de')
		expect(toggle(wrapper).attributes('aria-label')).toBe('Language of the post: German')
	})

	it('offers the languages Nextcloud speaks, named and in alphabetical order', async () => {
		wrapper = mountSelect('en')
		await openMenu(wrapper)

		const names = menuEntries().map((entry) => entry.textContent.trim())
		expect(names).toHaveLength(POST_LANGUAGES.length)
		expect(names).toContain('German')
		expect(names).toContain('English')
		expect([...names]).toEqual([...names].sort((a, b) => a.localeCompare(b)))
	})

	it('marks the one the post is in', async () => {
		wrapper = mountSelect('fr')
		await openMenu(wrapper)

		const selected = menuEntries().filter((entry) => entry.classList.contains('selected-language'))
		expect(selected).toHaveLength(1)
		expect(selected[0].textContent).toContain('French')
	})

	it('emits the language chosen and remembers it for the next post', async () => {
		wrapper = mountSelect('en')
		await openMenu(wrapper)

		menuEntries().find((entry) => entry.textContent.includes('German'))
			.querySelector('button')
			.click()
		await flushPromises()

		expect(wrapper.emitted('update:language')).toEqual([['de']])
		expect(localStorage.getItem('social.lastLanguage')).toBe('de')
	})

	it('does not write a preference before a choice is made', () => {
		wrapper = mountSelect('en')

		expect(localStorage.getItem('social.lastLanguage')).toBeNull()
	})

	/**
	 * A code off a draft, or one remembered from a version with a longer list,
	 * has to stay visible and changeable rather than silently becoming English.
	 */
	it('keeps a language that is not on the list', async () => {
		wrapper = mountSelect('nan')
		await openMenu(wrapper)

		expect(toggle(wrapper).find('.language-select__current').text()).toBe('nan')
		expect(menuEntries()).toHaveLength(POST_LANGUAGES.length + 1)
		expect(menuEntries().filter((entry) => entry.classList.contains('selected-language')))
			.toHaveLength(1)
	})

	it('names the languages in the reader\'s own language', () => {
		setLanguage('de')
		wrapper = mountSelect('de')

		expect(toggle(wrapper).attributes('aria-label')).toBe('Language of the post: Deutsch')
	})

	it('follows the language prop when the parent changes it', async () => {
		wrapper = mountSelect('en')
		await wrapper.setProps({ language: 'es' })

		expect(toggle(wrapper).find('.language-select__current').text()).toBe('es')
		expect(toggle(wrapper).attributes('aria-label')).toBe('Language of the post: Spanish')
	})
})
