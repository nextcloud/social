/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'

import DiscoverCategories from '../../../src/components/DiscoverCategories.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

const stubs = { RouterLink: { template: '<a><slot /></a>' } }

describe('the curated part of Explore', () => {
	beforeEach(() => vi.clearAllMocks())

	it('shows each subject and the hashtags it means', async () => {
		axios.get.mockResolvedValue({
			data: { categories: [{ id: 1, name: 'Architecture', hashtags: ['brutalism', 'concrete'] }] },
		})
		const wrapper = mount(DiscoverCategories, { global: { stubs } })
		await flushPromises()

		expect(wrapper.text()).toContain('Architecture')
		expect(wrapper.text()).toContain('#brutalism')
		// and says which half of the page this is
		expect(wrapper.text()).toContain('rather than counted')
	})

	/** A heading over nothing is worse than no heading. */
	it('renders nothing at all when nobody has curated anything', async () => {
		axios.get.mockResolvedValue({ data: { categories: [] } })
		const wrapper = mount(DiscoverCategories, { global: { stubs } })
		await flushPromises()

		expect(wrapper.text()).toBe('')
	})

	it('drops a subject with no hashtags left in it', async () => {
		axios.get.mockResolvedValue({ data: { categories: [{ id: 1, name: 'Empty', hashtags: [] }] } })
		const wrapper = mount(DiscoverCategories, { global: { stubs } })
		await flushPromises()

		expect(wrapper.text()).toBe('')
	})

	/** An Explore page without this is still an Explore page. */
	it('says nothing when the server does not answer', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(DiscoverCategories, { global: { stubs } })
		await flushPromises()

		expect(wrapper.text()).toBe('')
	})
})
