/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InterestsLearningBanner, { DISMISSED_KEY } from '../../../src/components/InterestsLearningBanner.vue'
import { fetchInterests } from '../../../src/services/interests.js'

vi.mock('../../../src/services/interests.js', () => ({ fetchInterests: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const NcButton = {
	name: 'NcButton',
	emits: ['click'],
	template: '<button class="dismiss" @click="$emit(\'click\')"><slot name="icon" /></button>',
}

async function mountBanner() {
	const wrapper = mount(InterestsLearningBanner, { global: { stubs: { NcButton, RouterLink: RouterLinkStub } } })
	await flushPromises()

	return wrapper
}

describe('the still-learning note', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		window.localStorage.clear()
	})

	it('asks once, and shows while learning is thin', async () => {
		fetchInterests.mockResolvedValue({ thin: true })
		const wrapper = await mountBanner()

		expect(fetchInterests).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).toContain('Still learning what you like.')
		expect(wrapper.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'settings', hash: '#interests' })
	})

	it('says nothing once there is enough to go on', async () => {
		fetchInterests.mockResolvedValue({ thin: false })

		expect((await mountBanner()).find('.interests-banner').exists()).toBe(false)
	})

	it('says nothing when the question could not be answered', async () => {
		fetchInterests.mockRejectedValue(new Error('500'))

		expect((await mountBanner()).find('.interests-banner').exists()).toBe(false)
	})

	it('stays away in this browser once put away', async () => {
		fetchInterests.mockResolvedValue({ thin: true })
		const wrapper = await mountBanner()
		await wrapper.find('.dismiss').trigger('click')

		expect(wrapper.find('.interests-banner').exists()).toBe(false)
		expect(window.localStorage.getItem(DISMISSED_KEY)).not.toBeNull()

		const again = await mountBanner()
		expect(again.find('.interests-banner').exists()).toBe(false)
		expect(fetchInterests).toHaveBeenCalledTimes(1)
	})
})
