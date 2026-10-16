/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'
import ShortcutsSettings from '../../../src/components/ShortcutsSettings.vue'
import ShortcutList from '../../../src/components/ShortcutList.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({ default: { patch: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { error: vi.fn() } }))

const API = '/index.php/apps/social/api/v1/social/keyboard_shortcuts'

/**
 * @param {object} serverData what the page says
 * @return {object} the mounted section
 */
function mountSettings(serverData = {}) {
	setActivePinia(createPinia())
	useSettingsStore().setServerData(serverData)

	return mount(ShortcutsSettings)
}

const toggle = (wrapper) => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })

describe('ShortcutsSettings', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('is on for an account that never touched it, above the list of keys', () => {
		const wrapper = mountSettings()

		expect(toggle(wrapper).props('modelValue')).toBe(true)
		expect(wrapper.text()).toContain('Use keyboard shortcuts')
		expect(wrapper.findComponent(ShortcutList).exists()).toBe(true)
	})

	it('says when Nextcloud\'s own Accessibility setting keeps them off', () => {
		expect(mountSettings().text()).not.toContain('Accessibility settings')

		window.OCP.Accessibility = { disableKeyboardShortcuts: () => true }
		try {
			expect(mountSettings().text()).toContain('turned off for all of Nextcloud in your Accessibility settings')
		} finally {
			delete window.OCP.Accessibility
		}
	})

	it('shows the stored choice', () => {
		expect(toggle(mountSettings({ keyboardShortcuts: false })).props('modelValue')).toBe(false)
	})

	it('turns them off at once, and saves it with the account', async () => {
		axios.patch.mockResolvedValue({ data: { enabled: false } })
		const wrapper = mountSettings({ keyboardShortcuts: true })

		toggle(wrapper).vm.$emit('update:modelValue', false)
		expect(useSettingsStore().shortcutsEnabled).toBe(false)
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(API, { enabled: false })
		expect(useSettingsStore().shortcutsEnabled).toBe(false)
		expect(toggle(wrapper).props('modelValue')).toBe(false)
	})

	it('puts the switch back and says so when the server refuses', async () => {
		axios.patch.mockRejectedValue(new Error('nope'))
		const wrapper = mountSettings({ keyboardShortcuts: true })

		toggle(wrapper).vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not save that setting')
		expect(useSettingsStore().shortcutsEnabled).toBe(true)
	})
})
