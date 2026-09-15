/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlacePage from '../../../src/views/PlacePage.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
const { showError } = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('../../../src/services/toast.js', () => ({ showError }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

function post(id) {
	return { id, media_attachments: [{ type: 'image', preview_url: `https://cloud.example.org/${id}-small.jpg`, url: `https://cloud.example.org/${id}.jpg` }], account: { acct: 'alice' } }
}

function mountPage(id = '4') {
	return mount(PlacePage, {
		props: { id },
		global: {
			stubs: {
				ProfileMediaGrid: { name: 'ProfileMediaGrid', props: ['posts', 'account', 'loading'], template: '<div class="grid-stub">{{ posts.length }}</div>' },
				NcEmptyContent: { template: '<div class="empty-stub" />', props: ['name', 'description'] },
			},
		},
	})
}

describe('PlacePage', () => {
	beforeEach(() => {
		get.mockReset()
		showError.mockReset()
	})

	it('heads the page with the place and draws the public posts taken there', async () => {
		get.mockImplementation((url) => Promise.resolve({
			data: url.endsWith('/statuses') ? [post('101'), post('102')] : { id: '4', name: 'Berlin', country: 'Germany' },
		}))

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.place__title').text()).toBe('Berlin')
		expect(wrapper.find('.place__country').text()).toBe('Germany')
		expect(wrapper.findComponent({ name: 'ProfileMediaGrid' }).props('posts')).toHaveLength(2)
		expect(get).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/places/4/statuses'), { params: { limit: 20 } })
	})

	it('pages by the last post it has when a page came back full', async () => {
		const full = Array.from({ length: 20 }, (unused, index) => post(String(200 - index)))
		get.mockImplementation((url) => Promise.resolve({
			data: url.endsWith('/statuses') ? full : { id: '4', name: 'Berlin', country: '' },
		}))

		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.find('.place__more').exists()).toBe(true)
		await wrapper.find('.place__more button').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenLastCalledWith(expect.stringContaining('/places/4/statuses'), { params: { limit: 20, max_id: '181' } })
	})

	it('tells a place that does not exist from a failure', async () => {
		get.mockRejectedValue({ response: { status: 404 } })

		const wrapper = mountPage('99')
		await flushPromises()

		expect(wrapper.find('.place__error').text()).toContain('no such place')
	})
})
