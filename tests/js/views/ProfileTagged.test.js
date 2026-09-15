/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import ProfileTagged from '../../../src/views/ProfileTagged.vue'
import TimelineSwitcher from '../../../src/components/TimelineSwitcher.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

function mountView(account = 'bob@remote.example') {
	const pinia = createPinia()
	setActivePinia(pinia)

	return mount(ProfileTagged, {
		global: {
			plugins: [pinia],
			mocks: { $route: { name: 'profile.tagged', params: { account } } },
			stubs: {
				TimelineEntry: { name: 'TimelineEntry', props: ['item', 'type'], template: '<li class="entry-stub" />' },
				RouterLink: RouterLinkStub,
			},
		},
	})
}

describe('ProfileTagged', () => {
	beforeEach(() => {
		get.mockReset().mockResolvedValue({ data: [] })
	})

	it('asks the server for the photos that account is named in', async () => {
		mountView('bob@remote.example')
		await flushPromises()

		const wanted = '/apps/social/api/v1.1/accounts/bob%40remote.example/tagged'
		expect(get).toHaveBeenCalledWith(expect.stringContaining(wanted))
	})

	it('draws one entry per photo it is given', async () => {
		get.mockResolvedValue({ data: [{ id: '1' }, { id: '2' }] })

		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.findAll('.entry-stub')).toHaveLength(2)
	})

	/**
	 * Which posts the reader may see is the server's decision; the page draws
	 * what it is handed and asks nothing of its own.
	 */
	it('says so plainly when there are none', async () => {
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.text()).toContain('No photos of them')
		expect(wrapper.findAll('.entry-stub')).toHaveLength(0)
	})

	it('shows Tagged as the place it is, on the same switcher the rest of the profile carries', async () => {
		const wrapper = mountView()
		await flushPromises()

		const switcher = wrapper.findComponent(TimelineSwitcher)
		expect(switcher.props('value')).toBe('tagged')
		expect(switcher.props('options').map((option) => option.label))
			.toEqual(['Posts', 'Photos', 'Videos', 'Tagged', 'Collections'])
	})

	it('offers a way back when the server could not answer', async () => {
		get.mockRejectedValue(new Error('nope'))

		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('.tagged__error').exists()).toBe(true)
		expect(wrapper.text()).toContain('Try again')
	})
})
