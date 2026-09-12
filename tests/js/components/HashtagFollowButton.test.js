/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import HashtagFollowButton from '../../../src/components/HashtagFollowButton.vue'
import logger from '../../../src/services/logger.js'
import { useSettingsStore } from '../../../src/store/settings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../../src/services/logger.js', () => ({
	default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() },
}))

const API = '/index.php/apps/social/api/v1'

const tagEntity = (name, following) => ({
	name,
	url: `https://cloud.example.org/apps/social/timeline/tags/${name}`,
	history: [],
	following,
})

const mountButton = async ({ tag = 'nextcloud', following = false, isPublic = false } = {}) => {
	const pinia = createPinia()
	setActivePinia(pinia)
	useSettingsStore().setServerData({ public: isPublic, cloudAddress: 'https://cloud.example.org' })
	axios.get.mockResolvedValue({ data: tagEntity(tag, following) })

	const wrapper = mount(HashtagFollowButton, {
		props: { tag },
		global: { plugins: [pinia] },
	})
	await flushPromises()

	return wrapper
}

describe('HashtagFollowButton', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	it('asks the server about the tag and offers to follow one that is not followed', async () => {
		const wrapper = await mountButton()

		expect(axios.get).toHaveBeenCalledWith(API + '/tags/nextcloud')
		const button = wrapper.find('button')
		expect(button.text()).toBe('Follow')
		expect(button.attributes('aria-pressed')).toBe('false')
		expect(button.attributes('aria-label')).toBe('Follow the hashtag #nextcloud')
	})

	// the state has to be said, not only coloured: aria-pressed and the label
	// both carry it
	it('says that a followed tag is followed', async () => {
		const button = (await mountButton({ following: true })).find('button')

		expect(button.text()).toBe('Following')
		expect(button.attributes('aria-pressed')).toBe('true')
		expect(button.attributes('aria-label')).toBe('Unfollow the hashtag #nextcloud')
	})

	it('follows the tag and takes the answer as the new state', async () => {
		const wrapper = await mountButton()
		axios.post.mockResolvedValueOnce({ data: tagEntity('nextcloud', true) })

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(API + '/tags/nextcloud/follow')
		expect(wrapper.find('button').text()).toBe('Following')
		expect(wrapper.find('button').attributes('aria-pressed')).toBe('true')
		expect(wrapper.emitted('changed')[0]).toEqual([{ tag: 'nextcloud', following: true }])
	})

	// the answer is the state, not the wish: a server that refuses to record
	// the follow and says so must not leave the button claiming otherwise
	it('keeps the state the server reports over the one that was asked for', async () => {
		const wrapper = await mountButton()
		axios.post.mockResolvedValueOnce({ data: tagEntity('nextcloud', false) })

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(wrapper.find('button').text()).toBe('Follow')
		expect(wrapper.find('button').attributes('aria-pressed')).toBe('false')
	})

	it('unfollows a tag it is following', async () => {
		const wrapper = await mountButton({ following: true })
		axios.post.mockResolvedValueOnce({ data: tagEntity('nextcloud', false) })

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(API + '/tags/nextcloud/unfollow')
		expect(wrapper.find('button').text()).toBe('Follow')
		expect(wrapper.find('button').attributes('aria-pressed')).toBe('false')
		expect(wrapper.emitted('changed')[0]).toEqual([{ tag: 'nextcloud', following: false }])
	})

	it('sends one request however often it is clicked while one is in flight', async () => {
		const wrapper = await mountButton()
		let release
		axios.post.mockReturnValueOnce(new Promise((resolve) => {
			release = resolve
		}))

		// clicked natively and without waiting in between: `disabled` only
		// reaches the DOM on the next render, so this is the case the guard in
		// the handler is there for
		const button = wrapper.find('button').element
		button.click()
		button.click()
		button.click()
		await nextTick()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(wrapper.find('button').text()).toBe('Following …')
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()

		release({ data: tagEntity('nextcloud', true) })
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(wrapper.find('button').text()).toBe('Following')
	})

	it('says that a refused follow did not happen instead of showing it as done', async () => {
		const wrapper = await mountButton()
		axios.post.mockRejectedValueOnce(new Error('nope'))

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not follow the hashtag #nextcloud')
		expect(logger.error).toHaveBeenCalled()
		expect(wrapper.find('button').text()).toBe('Follow')
		expect(wrapper.find('button').attributes('aria-pressed')).toBe('false')
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('says that a refused unfollow did not happen', async () => {
		const wrapper = await mountButton({ following: true })
		axios.post.mockRejectedValueOnce(new Error('nope'))

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Could not unfollow the hashtag #nextcloud')
		expect(wrapper.find('button').text()).toBe('Following')
		expect(wrapper.find('button').attributes('aria-pressed')).toBe('true')
	})

	// every route of the tag API needs a viewer and answers 401 without one
	it('stays off the public page and asks it nothing', async () => {
		const wrapper = await mountButton({ isPublic: true })

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('reads the state again when the reader moves to another hashtag', async () => {
		const wrapper = await mountButton({ following: true })
		axios.get.mockResolvedValue({ data: tagEntity('fediverse', false) })

		await wrapper.setProps({ tag: 'fediverse' })
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith(API + '/tags/fediverse')
		expect(wrapper.find('button').text()).toBe('Follow')
		expect(wrapper.find('button').attributes('aria-label')).toBe('Follow the hashtag #fediverse')
	})

	it('shows no control at all when the state could not be read', async () => {
		const pinia = createPinia()
		setActivePinia(pinia)
		useSettingsStore().setServerData({ public: false })
		axios.get.mockRejectedValueOnce(new Error('gone'))

		const wrapper = mount(HashtagFollowButton, {
			props: { tag: 'nextcloud' },
			global: { plugins: [pinia] },
		})
		await flushPromises()

		expect(wrapper.find('button').exists()).toBe(false)
		expect(logger.error).toHaveBeenCalled()
	})

	it('escapes a hashtag that would otherwise change the path', async () => {
		await mountButton({ tag: 'a/b' })

		expect(axios.get).toHaveBeenCalledWith(API + '/tags/a%2Fb')
	})
})
