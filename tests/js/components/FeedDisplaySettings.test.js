/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'
import FeedDisplaySettings from '../../../src/components/FeedDisplaySettings.vue'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({ default: { patch: vi.fn() } }))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({ default: { error: vi.fn() } }))

const API = '/index.php/apps/social/api/v1/social/feed_preferences'

function mountSettings(hidden = false) {
	setActivePinia(createPinia())
	useSettingsStore().setServerData({ hideRepliesInFeeds: hidden })

	return mount(FeedDisplaySettings)
}

const toggle = (wrapper) => wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })

describe('FeedDisplaySettings', () => {
	beforeEach(() => vi.clearAllMocks())

	it('shows replies as separate cards by default', () => {
		const wrapper = mountSettings()

		expect(toggle(wrapper).props('modelValue')).toBe(true)
		expect(wrapper.text()).toContain('Replies can appear as separate cards')
	})

	it('hides reply cards and persists the preference', async () => {
		axios.patch.mockResolvedValue({ data: { hide_replies: true } })
		const wrapper = mountSettings()

		toggle(wrapper).vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(axios.patch).toHaveBeenCalledWith(API, { hide_replies: true })
		expect(useSettingsStore().hidesRepliesInFeeds).toBe(true)
		expect(toggle(wrapper).props('modelValue')).toBe(false)
	})

	it('restores the previous preference when saving fails', async () => {
		axios.patch.mockRejectedValue(new Error('nope'))
		const wrapper = mountSettings()

		toggle(wrapper).vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not save that setting')
		expect(useSettingsStore().hidesRepliesInFeeds).toBe(false)
	})
})
