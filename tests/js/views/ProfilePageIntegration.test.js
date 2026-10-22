/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/* global setInitialState */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'
import ProfilePageIntegration from '../../../src/views/ProfilePageIntegration.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'

vi.hoisted(() => {
	document.head.dataset.user = 'alice'
	document.head.dataset.userDisplayname = 'Alice'
})

const ProfileStatusCardStub = { name: 'ProfileStatusCard', props: ['status'], template: '<li class="profile-status-card-stub" />' }
const TimelineSwitcherStub = {
	props: ['options', 'value', 'label'],
	emits: ['update:value'],
	template: '<nav class="feed-switcher"><button v-for="option in options" :key="option.value" @click="$emit(\'update:value\', option.value)">{{ option.label }}</button></nav>',
}

const bob = { id: 'https://cloud.example.org/users/bob', acct: 'bob', username: 'bob', display_name: 'Bob', avatar: 'avatar.png', header: 'banner.png' }
const statuses = [
	{ id: '1', content: '<p>first</p>', account: bob },
	{ id: '2', content: '<p>second</p>', account: bob },
]
const homeStatuses = [
	{ id: '2', content: '<p>second from the home feed</p>', account: { ...bob, acct: 'alice' } },
	{ id: 'home-1', content: '<p>From someone followed</p>', account: { ...bob, acct: 'followed@example.org' } },
]
const thirdStatus = { id: '3', content: '<p>third</p>', account: bob }

const intersectionObservers = []
class TestIntersectionObserver {
	constructor(callback) {
		this.callback = callback
		this.targets = new Set()
		intersectionObservers.push(this)
	}

	observe(target) {
		this.targets.add(target)
	}

	unobserve(target) {
		this.targets.delete(target)
	}

	disconnect() {
		this.targets.clear()
	}
}

let get

/**
 * @param {string} userId the Nextcloud user whose profile page it is
 * @param {string} [account] the username of their account here, as the page says it
 * @return {object} the mounted section
 */
function mountSection(userId, account = userId) {
	setInitialState('social', 'profileAccount', account)
	window._nc_initial_state?.clear()

	return mount(ProfilePageIntegration, {
		props: { userId },
		global: { stubs: { ProfileStatusCard: ProfileStatusCardStub, TimelineSwitcher: TimelineSwitcherStub, NcButton: true } },
	})
}

describe('ProfilePageIntegration', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		intersectionObservers.length = 0
		vi.stubGlobal('IntersectionObserver', TestIntersectionObserver)
		get = vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: url.endsWith('/timelines/home') ? homeStatuses : url.endsWith('/statuses') ? statuses : bob,
		}))
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	it('loads profile details and posts by URL-encoded account id', async () => {
		mountSection('bob@remote.example')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/bob%40remote.example')
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/bob%40remote.example/statuses', { params: { limit: 20 } })
	})

	/**
	 * The page knows its user by their user id; the account's username is
	 * chosen when it is made and need not be it. Asked by the user id, the
	 * API found nobody, or the account somebody else had given that name.
	 */
	it('asks for the account by its username, not by the profile user\'s id', async () => {
		mountSection('alice', 'aloha_al')
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/aloha_al')
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/aloha_al/statuses', { params: { limit: 20 } })
		expect(get).not.toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/alice')
	})

	it('asks for nothing where the profile\'s user has no account here', async () => {
		mountSection('carol', '')
		await flushPromises()

		expect(get).not.toHaveBeenCalled()
	})

	it('renders the Aloha Social profile banner and one entry per post', async () => {
		const wrapper = mountSection('bob')
		await flushPromises()
		expect(wrapper.find('h2').text()).toBe('Aloha Social')
		expect(wrapper.find('.social-profile__banner').attributes('src')).toBe('banner.png')
		expect(wrapper.findAllComponents(ProfileStatusCardStub).map((entry) => entry.props('status'))).toEqual(statuses)
		expect(wrapper.find('.composer-stub').exists()).toBe(false)
	})

	it('places the banner in the native profile header behind the avatar', async () => {
		const header = document.createElement('div')
		header.className = 'profile__header'
		header.innerHTML = '<div class="profile__header__container"><span class="profile__header__container__avatar"></span></div>'
		document.body.appendChild(header)
		try {
			const wrapper = mountSection('bob')
			await flushPromises()
			expect(header.querySelector('.social-profile__header-banner img')?.getAttribute('src')).toBe('banner.png')
			expect(wrapper.find('.social-profile__banner').exists()).toBe(false)
			wrapper.unmount()
		} finally {
			header.remove()
		}
	})

	it('shows My Feed only on the signed-in user profile and opens it on request', async () => {
		const wrapper = mountSection('alice')
		await flushPromises()
		const feedButton = wrapper.findAll('.feed-switcher button').find((button) => button.text() === 'My Feed')
		expect(feedButton).toBeTruthy()
		await feedButton.trigger('click')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/timelines/home', { params: { limit: 20 } })
		expect(wrapper.find('.composer-stub').exists()).toBe(false)
		expect(wrapper.findAllComponents(ProfileStatusCardStub).map((entry) => entry.props('status').id)).toEqual(['2', 'home-1'])
	})

	it('loads local and global public timelines from their Aloha Social API scopes', async () => {
		const wrapper = mountSection('bob')
		await flushPromises()
		await wrapper.findAll('.feed-switcher button').find((button) => button.text() === 'Local').trigger('click')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/timelines/public', { params: { limit: 20, local: true } })
		await wrapper.findAll('.feed-switcher button').find((button) => button.text() === 'Global').trigger('click')
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/timelines/public', { params: { limit: 20, local: false } })
	})

	it('keeps paging a short profile page while the server is filling Bluesky posts', async () => {
		get.mockImplementation(async (url, config) => {
			if (url.endsWith('/statuses')) {
				return config?.params?.max_id
					? { data: [statuses[1]], headers: {} }
					: { data: [statuses[0]], headers: { 'x-social-filling': '1' } }
			}
			return { data: bob, headers: {} }
		})
		const wrapper = mountSection('bob')
		await flushPromises()

		const loadMore = wrapper.find('.social-profile__load-more')
		expect(loadMore).toBeTruthy()
		await loadMore.trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/bob/statuses', { params: { limit: 20, max_id: '1' } })
		expect(wrapper.findAllComponents(ProfileStatusCardStub).map((entry) => entry.props('status').id)).toEqual(['1', '2'])
	})

	it('loads the next Bluesky profile page as the reader scrolls to the end', async () => {
		get.mockImplementation(async (url, config) => {
			if (url.endsWith('/statuses')) {
				return config?.params?.max_id
					? { data: [thirdStatus], headers: { 'x-social-filling': '1' } }
					: { data: statuses, headers: { 'x-social-filling': '1' } }
			}
			return { data: bob, headers: {} }
		})
		const wrapper = mountSection('bob')
		await flushPromises()

		const observer = intersectionObservers.at(-1)
		expect(observer.targets.size).toBe(1)
		observer.callback([{ isIntersecting: true }])
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/social/api/v1/accounts/bob/statuses', { params: { limit: 20, max_id: '2' } })
		expect(wrapper.findAllComponents(ProfileStatusCardStub).map((entry) => entry.props('status').id)).toEqual(['1', '2', '3'])
	})

	it('offers a manual continuation when a Bluesky refill has no visible posts yet', async () => {
		get.mockImplementation(async (url, config) => {
			if (url.endsWith('/statuses')) {
				return config?.params?.max_id
					? { data: [], headers: { 'x-social-filling': '1' } }
					: { data: [], headers: { 'x-social-filling': '1' } }
			}
			return { data: bob, headers: {} }
		})
		const wrapper = mountSection('bob')
		await flushPromises()

		expect(wrapper.find('.social-profile__feed-state').text()).toContain('Loading')
		const continuation = wrapper.find('.social-profile__load-more')
		expect(continuation.exists()).toBe(true)
		await continuation.trigger('click')
		await flushPromises()

		expect(get.mock.calls.filter(([url]) => url.endsWith('/statuses'))).toHaveLength(2)
	})

	it('keeps the post a boost on the profile repeats where its card reads it', async () => {
		const boosted = { id: '7', content: '<p>passed on</p>', account: { ...bob, acct: 'carol' }, reblog: null }
		const boost = { id: '8', content: '', account: bob, reblog: boosted }
		get.mockImplementation(async (url) => ({ data: url.endsWith('/statuses') ? [boost, ...statuses] : bob }))

		const wrapper = mountSection('bob')
		await flushPromises()

		expect(wrapper.findAllComponents(ProfileStatusCardStub).map((entry) => entry.props('status').id)).toEqual(['8', '1', '2'])
		expect(useTimelineStore().getStatus('7')).toEqual(boosted)
	})

	it('does not contact the server without a user id', () => {
		const wrapper = mountSection('')
		expect(get).not.toHaveBeenCalled()
		expect(wrapper.find('h2').text()).toBe('Aloha Social')
	})

	it('keeps the section usable when posts cannot be loaded', async () => {
		get.mockRejectedValue(new Error('404'))
		const wrapper = mountSection('bob')
		await flushPromises()
		expect(wrapper.find('h2').text()).toBe('Aloha Social')
		expect(wrapper.findAllComponents(ProfileStatusCardStub)).toHaveLength(0)
	})
})
