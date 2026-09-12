/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import HashtagFollowedList from '../../../src/components/HashtagFollowedList.vue'
import logger from '../../../src/services/logger.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const tagEntity = (name) => ({ name, url: `https://cloud.example.org/tags/${name}`, history: [], following: true })

function mountList({ isPublic = false } = {}) {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: isPublic })

	return mount(HashtagFollowedList, {
		global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
	})
}

async function open(wrapper) {
	await wrapper.find('.followed-hashtags__toggle').trigger('click')
	await flushPromises()
}

describe('HashtagFollowedList', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	it('asks for nothing until somebody opens it', () => {
		const wrapper = mountList()

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.find('.followed-hashtags__toggle').attributes('aria-expanded')).toBe('false')
	})

	it('lists the followed hashtags and links each to its timeline', async () => {
		const wrapper = mountList()
		axios.get.mockResolvedValueOnce({ data: [tagEntity('nextcloud'), tagEntity('fediverse')] })

		await open(wrapper)

		expect(axios.get).toHaveBeenCalledWith(API + '/followed_tags', { params: { limit: 50 } })
		expect(wrapper.find('.followed-hashtags__toggle').attributes('aria-expanded')).toBe('true')
		const links = wrapper.findAllComponents(RouterLinkStub)
		expect(links).toHaveLength(2)
		expect(links[0].text()).toBe('#nextcloud')
		expect(links[0].props('to')).toEqual({ name: 'tags', params: { tag: 'nextcloud' } })
		expect(links[1].props('to')).toEqual({ name: 'tags', params: { tag: 'fediverse' } })
	})

	it('says so when the reader follows no hashtag', async () => {
		const wrapper = mountList()
		axios.get.mockResolvedValueOnce({ data: [] })

		await open(wrapper)

		expect(wrapper.findAllComponents(RouterLinkStub)).toHaveLength(0)
		expect(wrapper.find('.followed-hashtags__hint').text()).toBe('You are not following any hashtag yet.')
	})

	it('reports a list it could not load', async () => {
		const wrapper = mountList()
		axios.get.mockRejectedValueOnce(new Error('nope'))

		await open(wrapper)

		expect(showError).toHaveBeenCalledWith('Could not load the hashtags you follow')
		expect(logger.error).toHaveBeenCalled()
		expect(wrapper.findAllComponents(RouterLinkStub)).toHaveLength(0)
	})

	it('re-reads the list for somebody who is looking at it, and not otherwise', async () => {
		const wrapper = mountList()
		axios.get.mockResolvedValue({ data: [tagEntity('nextcloud')] })

		wrapper.vm.refresh()
		await flushPromises()
		expect(axios.get).not.toHaveBeenCalled()

		await open(wrapper)
		axios.get.mockResolvedValue({ data: [tagEntity('nextcloud'), tagEntity('fediverse')] })
		wrapper.vm.refresh()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.findAllComponents(RouterLinkStub)).toHaveLength(2)
	})

	// `/followed_tags` needs a viewer, and the public page has none
	it('stays off the public page', () => {
		const wrapper = mountList({ isPublic: true })

		expect(wrapper.find('.followed-hashtags').exists()).toBe(false)
		expect(axios.get).not.toHaveBeenCalled()
	})
})
